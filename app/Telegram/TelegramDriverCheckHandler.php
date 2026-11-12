<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Application\Telegram\Actions\NotifyTelegramResolverExhaustion;
use App\Application\Telegram\Actions\NudgeSilentClientChecks;
use App\Application\Telegram\Actions\ProcessAutoReply;
use App\Application\Telegram\Actions\ProcessClientCheckMessage;
use App\Application\Telegram\Actions\ProcessCreatedDriverMessage;
use App\Application\Telegram\Actions\ProcessRequestCancellation;
use App\Application\Telegram\Actions\ProcessTelegramDriverCheckResults;
use App\Application\Telegram\Actions\ProcessUpdatedDriverMessage;
use App\Application\Telegram\Actions\RouteTelegramFile;
use App\Application\Telegram\Actions\TelegramDriverCheckStarter;
use App\Application\Telegram\Services\AutoReplyTelegramGifs;
use App\Application\Telegram\Services\ChatReader;
use App\Application\Telegram\Services\ClientCheckSender;
use App\Application\Telegram\Services\DutyOwners;
use App\Application\Telegram\Services\RequestCancellationAsker;
use App\Application\Telegram\Services\ScheduledMessageSender;
use App\Application\Telegram\Services\TelegramDriverCheckChats;
use App\Application\Telegram\Services\TelegramDriverCheckRecorder;
use App\Application\Telegram\Services\TelegramMessageTypeDetector;
use App\Models\Telegram\TelegramListener;
use App\Enums\Drivers\TelegramDriverMessageType;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Media\Document;
use danog\MadelineProto\EventHandler\Message as TelegramIncomingMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\Loop\Update\UpdateLoop;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramDriverCheckHandler extends SimpleEventHandler
{
    /**
     * Health probe interval, seconds.
     */
    private const HEALTH_CHECK_PERIOD = 15.0;

    /**
     * Consecutive failed probes before the process asks to be restarted.
     *
     * MadelineProto legitimately stops the update loops for a moment during a
     * DC migration or a re-login, so a single failed probe must not trigger a
     * restart.
     */
    private const HEALTH_CHECK_STRIKES = 3;

    /**
     * How often the watch list is re-read from the database, seconds.
     *
     * This is what makes a chat added or removed in the panel take effect
     * without restarting the listener.
     */
    private const CHAT_REFRESH_PERIOD = 30.0;

    /**
     * How often client check comments and retries are looked at, seconds.
     *
     * 2, not 10 (2026-10-07): the comment waits 5 seconds for a re-send of
     * the same request (batch_quiet_seconds), and a 10-second tick on top
     * of that made it arrive 5-15 seconds after the forward. With 2 it is
     * 5-7. A tick is a few small queries, cheap at this rate.
     */
    private const CLIENT_CHECK_PERIOD = 2.0;

    /**
     * Chats to watch, or null when onStart() could not run at all.
     *
     * An empty list is a legitimate state - no chat configured yet - and
     * must not be confused with a failed start.
     *
     * @var list<int>|null
     */
    private ?array $targetChatIds = null;

    private int $unhealthyStrikes = 0;

    private bool $restartRequested = false;

    public function onStart(): void
    {
        Log::info('TelegramDriverCheckHandler: onStart');

        try {
            $this->targetChatIds = app(
                TelegramDriverCheckStarter::class,
            )->execute($this);

            Log::info(
                'TelegramDriverCheckHandler: started',
                [
                    'chat_count' => $this->targetChatIds === null
                        ? 0
                        : count($this->targetChatIds),
                    'target_chat_ids' => $this->targetChatIds,
                ],
            );
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: onStart failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );

            throw $e;
        }
    }

    #[Handler]
    public function handleIncomingMessage(
        Incoming&TelegramIncomingMessage $message,
    ): void {
        try {
            if (
                $this->targetChatIds === null
                || $this->targetChatIds === []
            ) {
                return;
            }

            $chatId = $message->chatId ?? null;

            if ($chatId === null) {
                return;
            }

            // Ignore all other chats silently.
            if (! in_array((int) $chatId, $this->targetChatIds, true)) {
                /*
                 * Except a private chat: an operator or a sales manager
                 * writing to us - "+", "ok" after a penalty - is answered
                 * from the auto replies file. Strangers are not.
                 */
                if (
                    (int) $chatId > 0
                    && app(DutyOwners::class)->ownsPenalties(null)
                ) {
                    app(
                        ProcessAutoReply::class,
                    )->execute(
                        telegram: $this,
                        senderId: (int) ($message->senderId ?? 0),
                        messageId: (int) ($message->id ?? 0),
                        text: (string) ($message->message ?? ''),
                    );
                }

                return;
            }

            app(
                TelegramDriverCheckChats::class,
            )->touch((int) $chatId);

            /*
             * The bots' morning files: forwarded to the groups their kind
             * is set to. Only documents - a photo is never one of them.
             */
            if (
                $message->media instanceof Document
                && app(DutyOwners::class)->ownsFiles(null)
                && app(
                    RouteTelegramFile::class,
                )->execute(
                    telegram: $this,
                    chatId: (int) $chatId,
                    messageId: (int) ($message->id ?? 0),
                    fileName: (string) ($message->media->fileName ?? ''),
                    caption: (string) ($message->message ?? ''),
                )
            ) {
                return;
            }

            Log::info(
                'TelegramDriverCheckHandler: incoming message',
                [
                    'chat_id' => $chatId,
                    'message_id' => $message->id ?? null,
                ],
            );

            $messageId = (int) ($message->id ?? 0);

            if ($messageId <= 0) {
                Log::warning(
                    'TelegramDriverCheckHandler: invalid message id',
                );

                return;
            }

            $text = trim(
                \App\Application\Telegram\Services\LinkedText::expand(
                    (string) ($message->message ?? ''),
                    $message->entities ?? [],
                ),
            );

            if ($text === '') {
                Log::info(
                    'TelegramDriverCheckHandler: empty text',
                );

                return;
            }

            $type = app(
                TelegramMessageTypeDetector::class,
            )->detect($text);

            Log::info(
                'TelegramDriverCheckHandler: detected type',
                [
                    'type' => $type?->value ?? null,
                ],
            );

            /*
             * A CRM penalty is a client check, not a driver check: it has
             * its own table and flow and never reaches the recorder below.
             */
            if ($type === TelegramDriverMessageType::PENALTY) {
                /* Another listener reads the penalties (Listeners -> Who does what): left to it. */
                if (! app(DutyOwners::class)->ownedBy(TelegramListener::DUTY_PENALTY_INTAKE, null)) {
                    return;
                }

                app(
                    ProcessClientCheckMessage::class,
                )->execute(
                    telegram: $this,
                    chatId: (int) $chatId,
                    messageId: $messageId,
                    text: $text,
                    raw: [
                        'class' => $message::class,
                        'id' => $message->id ?? null,
                        'chat_id' => $message->chatId ?? null,
                        'sender_id' => $message->senderId ?? null,
                        'message' => $message->message ?? null,
                        'date' => $message->date ?? null,
                    ],
                );

                return;
            }

            /*
             * "❌ Запрос отменён": the one who got its last penalty is asked
             * why. Read by whoever reads the penalties.
             */
            if ($type === TelegramDriverMessageType::REQUEST_CANCELLED) {
                if (app(DutyOwners::class)->ownedBy(TelegramListener::DUTY_PENALTY_INTAKE, null)) {
                    app(ProcessRequestCancellation::class)->execute((int) $chatId, $messageId, $text);
                }

                return;
            }

            $check = app(
                TelegramDriverCheckRecorder::class,
            )->record(
                message: $message,
                text: $text,
                type: $type,
            );



            if ($check === null) {
                return;
            }

            if (
                $type ===
                TelegramDriverMessageType::UPDATED_DRIVER
            ) {
                app(
                    ProcessUpdatedDriverMessage::class,
                )->execute(
                    telegram: $this,
                    update: $check,
                    text: $text,
                );

                return;
            }

            if (
                $type !==
                TelegramDriverMessageType::CREATED_DRIVER
            ) {
                Log::info(
                    'TelegramDriverCheckHandler: message ignored by type',
                    [
                        'type' => $type?->value ?? null,
                    ],
                );

                return;
            }


            app(
                ProcessCreatedDriverMessage::class,
            )->execute(
                check: $check,
                text: $text,
            );
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: handle failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                    'chat_id' => $message->chatId ?? null,
                    'message_id' => $message->id ?? null,
                ],
            );
        }
    }

    #[Cron(period: 1.0)]
    public function cron(): void
    {


        try {
            if ($this->targetChatIds === null) {
                Log::warning(
                    'TelegramDriverCheckHandler: cron has no watch list',
                );

                return;
            }

            if ($this->targetChatIds === []) {
                return;
            }

            app(
                ProcessTelegramDriverCheckResults::class,
            )->execute(
                $this,
                $this->targetChatIds,
            );



            app(
                NotifyTelegramResolverExhaustion::class,
            )->execute(
                $this,
                $this->targetChatIds,
            );
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: cron failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * Re-reads the watch list from the database.
     *
     * The panel writes to telegram_driver_check_chats while this process is
     * running, so without this the only way to add or remove a group would be a
     * restart. Resolution of a new invite link happens here too - it needs the
     * MadelineProto session this process owns.
     */
    #[Cron(period: self::CHAT_REFRESH_PERIOD)]
    public function refreshChats(): void
    {
        /*
     * onStart() failed: the health probe is already asking for a restart,
     * and a watch list without a start notification would only hide it.
     */
        if ($this->targetChatIds === null || $this->restartRequested) {
            return;
        }

        try {
            $chatIds = app(
                TelegramDriverCheckChats::class,
            )->resolve($this);

            if ($chatIds === $this->targetChatIds) {
                return;
            }

            Log::info(
                'TelegramDriverCheckHandler: watch list changed',
                [
                    'from' => $this->targetChatIds,
                    'to' => $chatIds,
                ],
            );

            $this->targetChatIds = $chatIds;
        } catch (Throwable $e) {
            /*
         * Keep watching what we already have: a failed refresh is a reason
         * to skip a beat, never to go deaf.
         */
            Log::error(
                'TelegramDriverCheckHandler: watch list refresh failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * Client checks: the one comment per batch once the bot's burst is
     * over, and the retries of what Telegram refused.
     */
    #[Cron(period: self::CLIENT_CHECK_PERIOD)]
    public function flushClientChecks(): void
    {
        if ($this->targetChatIds === null || $this->restartRequested) {
            return;
        }

        /* Other listeners are set to deliver all the penalties (Listeners -> Who does what). */
        if (! app(DutyOwners::class)->ownsPenalties(null)) {
            return;
        }

        try {
            app(
                ClientCheckSender::class,
            )->flush($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: client check flush failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }

        /*
         * After the comments: a penalty nobody answered gets its nudge.
         * Apart, so a failure here never holds the comments back.
         */
        try {
            app(
                NudgeSilentClientChecks::class,
            )->execute($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: client check nudge failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }

        /* The cancelled requests: "why?" under their last penalty. */
        try {
            app(RequestCancellationAsker::class)->flush($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: cancellation question failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * The file forwards Telegram refused (a group the account was not in
     * yet, a flood wait), tried again a minute later.
     */
    #[Cron(period: 60.0)]
    public function retryFileForwards(): void
    {
        if ($this->targetChatIds === null || $this->restartRequested) {
            return;
        }

        if (! app(DutyOwners::class)->ownsFiles(null)) {
            return;
        }

        try {
            app(
                RouteTelegramFile::class,
            )->retry($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: file forward retry failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * The scheduled messages (Driver check -> Scheduled messages): those
     * whose time has come, and those "Send now" was pressed for.
     */
    #[Cron(period: 5.0)]
    public function sendScheduledMessages(): void
    {
        if ($this->targetChatIds === null || $this->restartRequested) {
            return;
        }

        try {
            app(
                ScheduledMessageSender::class,
            )->flush($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: scheduled messages failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * The auto replies page looking for a GIF in Telegram: only this
     * process owns the session, so the panel leaves the search here.
     */
    #[Cron(period: 1.0)]
    public function answerGifSearches(): void
    {
        if ($this->restartRequested) {
            return;
        }

        /* The GIFs are searched with the account that answers the replies. */
        if (! app(DutyOwners::class)->ownsPenalties(null)) {
            return;
        }

        try {
            app(
                AutoReplyTelegramGifs::class,
            )->serve($this);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: GIF search failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * The developer's chat reader (GET /api/telegram/reader): this process
     * holds Driver Check's session, so the reads asked of it are answered here.
     */
    #[Cron(period: 1.0)]
    public function serveChatReads(): void
    {
        $accountId = (int) config('services.telegram.driver_check_account_id');

        if ($this->restartRequested || $accountId <= 0) {
            return;
        }

        try {
            app(ChatReader::class)->serve($this, $accountId);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: chat reads failed',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * Liveness probe for MadelineProto's update pipeline.
     *
     * Runs on its own PeriodicLoop, independently of the update loops, so it stays
     * alive exactly in the situation we need to detect: MadelineProto 8.7 catches
     * Amp\TimeoutException in UpdateLoop::loop() but the RPC drop timeout actually
     * throws Amp\CancelledException (with the TimeoutException as previous), so it
     * escapes, danog\Loop\Loop marks the loop as not running and the library's own
     * event loop error handler swallows it. The process then stays alive forever
     * without ever fetching updates.getDifference again.
     *
     * A dead pipeline is unrecoverable inside the same process, so the only
     * correct action is to stop the event loop and let the watchdog start a new
     * one.
     */
    #[Cron(period: self::HEALTH_CHECK_PERIOD)]
    public function healthCheck(): void
    {
        if ($this->restartRequested) {
            return;
        }

        try {
            $problem = $this->detectProblem();

            if ($problem === null) {
                $this->unhealthyStrikes = 0;

                return;
            }

            $this->unhealthyStrikes++;

            Log::warning(
                'TelegramDriverCheckHandler: health probe failed',
                [
                    'pid' => getmypid(),
                    'reason' => $problem,
                    'strikes' => $this->unhealthyStrikes,
                    'strikes_required' => self::HEALTH_CHECK_STRIKES,
                ],
            );

            if ($this->unhealthyStrikes < self::HEALTH_CHECK_STRIKES) {
                return;
            }

            $this->requestProcessRestart($problem);
        } catch (Throwable $e) {
            Log::error(
                'TelegramDriverCheckHandler: health probe error',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );
        }
    }

    /**
     * Returns a health marker reason, or null when everything is fine.
     */
    private function detectProblem(): ?string
    {
        if ($this->targetChatIds === null) {
            /*
         * onStart() could not resolve the target chat: the listener is up but
         * silently ignores every message. Restarting is the only way out.
         */
            return TelegramListenerHealth::REASON_START_FAILED;
        }

        $api = $this->wrapper->getAPI();

        if (!$api instanceof MTProto) {
            /*
         * IPC client: the update loops live in another process, nothing to
         * probe from here.
         */
            return null;
        }

        $updater = $api->updaters[UpdateLoop::GENERIC] ?? null;

        if ($updater === null) {
            // Not initialised yet.
            return null;
        }

        if (!$updater->isRunning()) {
            return TelegramListenerHealth::REASON_UPDATE_LOOP_DEAD;
        }

        $feeder = $api->feeders[UpdateLoop::GENERIC] ?? null;

        if ($feeder !== null && !$feeder->isRunning()) {
            return TelegramListenerHealth::REASON_UPDATE_LOOP_DEAD;
        }

        return null;
    }

    /**
     * Ask for a full process restart: write the reason where the command can read
     * it, then stop the event loop gracefully so MadelineProto serializes the
     * session on the way out.
     */
    private function requestProcessRestart(string $reason): void
    {
        $this->restartRequested = true;

        $context = [
            'pid' => getmypid(),
            'target_chat_ids' => $this->targetChatIds,
            'strikes' => $this->unhealthyStrikes,
            'probe_period' => self::HEALTH_CHECK_PERIOD,
        ];

        TelegramListenerHealth::markUnhealthy($reason, $context);

        Log::critical(
            'TelegramDriverCheckHandler: requesting full process restart',
            $context + ['reason' => $reason],
        );

        try {
            $this->stop();
        } catch (Throwable $e) {
            Log::critical(
                'TelegramDriverCheckHandler: graceful stop failed, forcing exit',
                [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            );

            /*
         * Last resort: the watchdog must get a non-zero exit code. PHP
         * shutdown handlers still run, so MadelineProto flushes the session.
         */
            exit(1);
        }
    }
}
