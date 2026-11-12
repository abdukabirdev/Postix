<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Application\Telegram\Actions\NudgeSilentClientChecks;
use App\Application\Telegram\Actions\ProcessAutoReply;
use App\Application\Telegram\Actions\ProcessClientCheckMessage;
use App\Application\Telegram\Actions\ProcessRequestCancellation;
use App\Application\Telegram\Actions\RouteTelegramFile;
use App\Application\Telegram\Services\AutoReplyTelegramGifs;
use App\Application\Telegram\Services\ClientCheckSender;
use App\Application\Telegram\Services\DutyOwners;
use App\Application\Telegram\Services\RequestCancellationAsker;
use App\Application\Telegram\Services\ScheduledMessageSender;
use App\Application\Telegram\Services\TelegramDriverCheckChats;
use App\Application\Telegram\Services\TelegramMessageTypeDetector;
use App\Enums\Drivers\TelegramDriverMessageType;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramListener;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Media\Document;
use danog\MadelineProto\EventHandler\Message as TelegramIncomingMessage;
use danog\MadelineProto\EventHandler\SimpleFilter\Incoming;
use danog\MadelineProto\Loop\Update\UpdateLoop;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\ParseMode;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The event loop of one of the listeners next to Driver Check (CEO, Head
 * Operation ...): the account of a telegram_listeners row, doing what the
 * row's duties say.
 *
 * Driver Check's own handler (TelegramDriverCheckHandler) keeps reading the
 * groups and recording the checks and the penalties; this one carries the
 * plumbing every extra account needs - the start notice, a heartbeat the
 * panel reads, a health probe that asks for a restart - and runs the
 * duties it is given:
 *
 *  - the scheduled messages, when the listener is switched on for them
 *    (TelegramListener::hasDuty());
 *  - the penalties and the files, when DutyOwners says this listener is
 *    their owner ("Who does what" in the panel). Each is the same code
 *    Driver Check runs, on this account's session.
 */
final class TelegramListenerHandler extends SimpleEventHandler
{
    /**
     * Which listener this process is: startAndLoop() builds the handler
     * itself, so the command leaves the key here first.
     */
    public static ?string $key = null;

    private const HEALTH_CHECK_PERIOD = 15.0;

    /** Consecutive failed probes before the process asks to be restarted. */
    private const HEALTH_CHECK_STRIKES = 3;

    private const HEARTBEAT_PERIOD = 30.0;

    /** How long the list of watched groups is kept, seconds. */
    private const WATCH_REFRESH = 30.0;

    /** @var list<int>|null */
    private ?array $watched = null;

    private float $watchedAt = 0.0;

    private int $unhealthyStrikes = 0;

    private bool $restartRequested = false;

    public function onStart(): void
    {
        $listener = $this->listener();

        if ($listener === null) {
            Log::critical('TelegramListenerHandler: no such listener, stopping', ['key' => self::$key]);

            $this->restartRequested = true;

            return;
        }

        try {
            $self = $this->getSelf();

            $account = $listener->account;

            $account?->update([
                ...(is_array($self) ? TelegramAccount::profileFrom($self) : []),
                'last_checked_at' => now(),
                'status' => 'running',
                'last_error' => null,
            ]);

            $this->messages->sendMessage([
                'peer' => 'me',
                'message' => "✅ <b>Listener «" . htmlspecialchars($listener->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "» запущен</b>\n\n"
                    . '🆔 Account ID: ' . ($account?->id ?? '—') . "\n"
                    . '⚙️ Обязанности: ' . ($this->dutyNames($listener) === [] ? '—' : implode(', ', $this->dutyNames($listener))) . "\n"
                    . '🕐 Время: ' . now()->format('Y-m-d H:i:s'),
                'parse_mode' => ParseMode::HTML,
                'no_webpage' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: start notice failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        Log::info('TelegramListenerHandler: started', ['key' => self::$key, 'duties' => $listener->duties()]);
    }

    /**
     * The panel's "is it alive" - the account's last_checked_at.
     */
    #[Cron(period: self::HEARTBEAT_PERIOD)]
    public function heartbeat(): void
    {
        if ($this->restartRequested) {
            return;
        }

        try {
            $listener = $this->listener();

            /* Switched off or removed in the panel: leave; the watchdog waits instead of restarting. */
            if ($listener === null || ! $listener->is_enabled) {
                $this->restartRequested = true;

                Log::warning('TelegramListenerHandler: switched off, stopping', ['key' => self::$key]);

                $this->stop();

                return;
            }

            $listener->account?->update(['last_checked_at' => now(), 'status' => 'running']);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: heartbeat failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Duty: the scheduled messages set to be written from this account.
     */
    #[Cron(period: 5.0)]
    public function sendScheduledMessages(): void
    {
        if ($this->restartRequested) {
            return;
        }

        try {
            $listener = $this->listener();

            /* Whatever is set to be written from this account, no switch needed. */
            if ($listener === null || $listener->telegram_account_id === null) {
                return;
            }

            app(ScheduledMessageSender::class)->flush($this, $listener->key);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: scheduled messages failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Messages to this account: the notifications bots' files (for the kinds
     * it forwards), the penalties of the watched groups (when it owns the
     * penalty intake) and the private replies to penalties (for the roles it
     * delivers). Driver checks are never read here; that stays with Driver Check.
     */
    #[Handler]
    public function handleIncomingMessage(Incoming&TelegramIncomingMessage $message): void
    {
        try {
            $chatId = (int) ($message->chatId ?? 0);

            if ($chatId === 0 || $this->restartRequested) {
                return;
            }

            $owners = app(DutyOwners::class);

            if (
                $message->media instanceof Document
                && $owners->ownsFiles(self::$key)
                && app(RouteTelegramFile::class)->execute(
                    telegram: $this,
                    chatId: $chatId,
                    messageId: (int) ($message->id ?? 0),
                    fileName: (string) ($message->media->fileName ?? ''),
                    caption: (string) ($message->message ?? ''),
                    listenerKey: self::$key,
                )
            ) {
                return;
            }

            if ($chatId < 0 && $owners->ownedBy(TelegramListener::DUTY_PENALTY_INTAKE, self::$key) && $this->takePenaltyIn($message, $chatId)) {
                return;
            }

            if ($chatId > 0 && $owners->ownsPenalties(self::$key)) {
                app(ProcessAutoReply::class)->execute(
                    telegram: $this,
                    senderId: (int) ($message->senderId ?? 0),
                    messageId: (int) ($message->id ?? 0),
                    text: (string) ($message->message ?? ''),
                    listenerKey: self::$key,
                );
            }
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: handle failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'chat_id' => $message->chatId ?? null,
                'message_id' => $message->id ?? null,
            ]);
        }
    }

    /**
     * Duty "penalty intake": a penalty the bot posted in one of the watched
     * groups is recorded here, as Driver Check does when it is the one that
     * reads. The account must be a member of the group.
     */
    private function takePenaltyIn(TelegramIncomingMessage $message, int $chatId): bool
    {
        if (! in_array($chatId, $this->watchedChats(), true)) {
            return false;
        }

        $text = trim(\App\Application\Telegram\Services\LinkedText::expand((string) ($message->message ?? ''), $message->entities ?? []));

        $type = $text === '' ? null : app(TelegramMessageTypeDetector::class)->detect($text);

        /* "❌ Запрос отменён": the one who got its last penalty is asked why. */
        if ($type === TelegramDriverMessageType::REQUEST_CANCELLED) {
            app(ProcessRequestCancellation::class)->execute($chatId, (int) ($message->id ?? 0), $text);

            return true;
        }

        if ($type !== TelegramDriverMessageType::PENALTY) {
            return false;
        }

        app(ProcessClientCheckMessage::class)->execute(
            telegram: $this,
            chatId: $chatId,
            messageId: (int) ($message->id ?? 0),
            text: $text,
            raw: [
                'class' => $message::class,
                'id' => $message->id ?? null,
                'chat_id' => $message->chatId ?? null,
                'sender_id' => $message->senderId ?? null,
                'message' => $message->message ?? null,
                'date' => $message->date ?? null,
            ],
            listenerKey: self::$key,
        );

        return true;
    }

    /**
     * The watched groups, re-read from the database every WATCH_REFRESH seconds.
     *
     * @return list<int>
     */
    private function watchedChats(): array
    {
        if ($this->watched === null || microtime(true) - $this->watchedAt > self::WATCH_REFRESH) {
            $this->watched = app(TelegramDriverCheckChats::class)->knownIds();
            $this->watchedAt = microtime(true);
        }

        return $this->watched;
    }

    /**
     * Duty "penalties": the forwards waiting for this account (those Driver
     * Check handed over, and the ones Telegram refused), the comment after
     * the burst, and the nudge for the penalties nobody answered.
     */
    #[Cron(period: 2.0)]
    public function flushClientChecks(): void
    {
        if ($this->restartRequested || ! app(DutyOwners::class)->ownsPenalties(self::$key)) {
            return;
        }

        try {
            app(ClientCheckSender::class)->flush($this, self::$key);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: client check flush failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        /* Apart, so a failure here never holds the comments back. */
        try {
            app(NudgeSilentClientChecks::class)->execute($this, self::$key);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: client check nudge failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        /* The cancelled requests: "why?" under their last penalty. */
        try {
            app(RequestCancellationAsker::class)->flush($this, self::$key);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: cancellation question failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Duty "penalties": the auto replies page looking for a GIF in Telegram.
     */
    #[Cron(period: 1.0)]
    public function answerGifSearches(): void
    {
        if ($this->restartRequested || ! app(DutyOwners::class)->ownsPenalties(self::$key)) {
            return;
        }

        try {
            app(AutoReplyTelegramGifs::class)->serve($this);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: GIF search failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Duty "files": the forwards Telegram refused, tried again a minute later.
     */
    #[Cron(period: 60.0)]
    public function retryFileForwards(): void
    {
        if ($this->restartRequested || ! app(DutyOwners::class)->ownsFiles(self::$key)) {
            return;
        }

        try {
            app(RouteTelegramFile::class)->retry($this, self::$key);
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: file forward retry failed', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * MadelineProto can lose its update loop without the process noticing;
     * a dead loop is unrecoverable in place, so the process is stopped and
     * the watchdog starts a new one.
     */
    #[Cron(period: self::HEALTH_CHECK_PERIOD)]
    public function healthCheck(): void
    {
        if ($this->restartRequested) {
            return;
        }

        try {
            if (! $this->updateLoopAlive()) {
                $this->unhealthyStrikes++;

                Log::warning('TelegramListenerHandler: health probe failed', [
                    'key' => self::$key,
                    'strikes' => $this->unhealthyStrikes,
                ]);

                if ($this->unhealthyStrikes >= self::HEALTH_CHECK_STRIKES) {
                    $this->restartRequested = true;

                    Log::critical('TelegramListenerHandler: update loop is dead, asking for a restart', ['key' => self::$key]);

                    $this->stop();
                }

                return;
            }

            $this->unhealthyStrikes = 0;
        } catch (Throwable $e) {
            Log::error('TelegramListenerHandler: health probe error', [
                'key' => self::$key,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    private function updateLoopAlive(): bool
    {
        $api = $this->wrapper->getAPI();

        /* An IPC client: the loops live in another process. */
        if (! $api instanceof MTProto) {
            return true;
        }

        $updater = $api->updaters[UpdateLoop::GENERIC] ?? null;

        if ($updater === null) {
            return true;
        }

        if (! $updater->isRunning()) {
            return false;
        }

        $feeder = $api->feeders[UpdateLoop::GENERIC] ?? null;

        return $feeder === null || $feeder->isRunning();
    }

    /**
     * What this listener does right now: its own switches and the duties
     * DutyOwners gives it.
     *
     * @return list<string>
     */
    private function dutyNames(TelegramListener $listener): array
    {
        $owners = app(DutyOwners::class);

        $owned = [];

        if ($owners->ownedBy(TelegramListener::DUTY_PENALTY_INTAKE, $listener->key)) {
            $owned[] = TelegramListener::DUTY_PENALTY_INTAKE;
        }

        if ($owners->ownsPenalties($listener->key)) {
            $owned[] = TelegramListener::DUTY_PENALTIES . ' (' . implode(', ', $owners->penaltyRoles($listener->key)) . ')';
        }

        if ($owners->ownsFiles($listener->key)) {
            $owned[] = TelegramListener::DUTY_FILE_ROUTES;
        }

        return [...$listener->duties(), ...$owned];
    }

    private function listener(): ?TelegramListener
    {
        return self::$key === null
            ? null
            : TelegramListener::query()->where('key', self::$key)->with('account')->first();
    }
}
