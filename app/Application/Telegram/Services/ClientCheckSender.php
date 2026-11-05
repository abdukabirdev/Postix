<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Enums\Telegram\TelegramClientCheckStatus;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramSetting;
use Amp\Sync\LocalKeyedMutex;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers penalties in two steps.
 *
 * 1. forward() - the bot's message in our words, the moment it arrives.
 * 2. flush()   - one comment per person, once the bot has stopped posting
 *                their penalties for a moment, to the peer the last forward
 *                reached.
 *
 * flush() also retries what Telegram refused, while it is still fresh.
 */
final class ClientCheckSender
{
    /* DeveloperAlerts and DutyOwners are in this namespace. */
    private const ERROR_LIMIT = 500;

    public function __construct(
        private readonly ClientCheckEscalation $escalation,
        private readonly AutoReplyDelivery $delivery,
        private readonly PenaltyOpening $opening,
        private readonly PenaltyFollowUp $followUp,
        private readonly PenaltyBurst $burst,
        private readonly PenaltyMessageText $text,
    ) {
    }

    private ?LocalKeyedMutex $mutex = null;

    /**
     * Same peer order as the operator DMs: username first, id as fallback.
     */
    public function forward(SimpleEventHandler $telegram, TelegramClientCheck $check): bool
    {
        $person = $check->operationUser;

        if (! $person instanceof OperationUser) {
            return false;
        }

        /*
         * One at a time per person: the penalty and its short comment
         * (PenaltyBurst) are done before the next penalty goes.
         */
        $lock = ($this->mutex ??= new LocalKeyedMutex())->acquire('person:' . $person->id);

        try {
            return $this->deliver($telegram, $check, $person);
        } finally {
            $lock->release();
        }
    }

    private function deliver(SimpleEventHandler $telegram, TelegramClientCheck $check, OperationUser $person): bool
    {
        $check->increment('attempts');

        $errors = [];

        foreach ($person->telegramPeerCandidates() as $peer) {
            try {
                /*
                 * The day's first penalty to them: we greet first.
                 */
                $this->opening->before($telegram, $peer, $check, $person);

                $this->write($telegram, $check, $peer);

                $check->update([
                    'status' => TelegramClientCheckStatus::Forwarded,
                    'reason' => null,
                    'error' => null,
                    'peer' => (string) $peer,
                    'forwarded_at' => now(),
                ]);

                $this->recordDelivery($person, null);

                $this->burst->after($telegram, $peer, $check, $person);

                return true;
            } catch (Throwable $e) {
                $errors[] = is_int($peer)
                    ? "id {$peer}: " . $e->getMessage()
                    : "{$peer}: " . $e->getMessage();
            }
        }

        $error = $errors !== [] ? implode(' | ', $errors) : 'no reachable Telegram peer';

        $this->fail(
            collect([$check]),
            TelegramClientCheck::REASON_FORWARD_FAILED,
            $error,
        );

        $this->recordDelivery($person, $error);

        $this->tellDeveloper($check, $person, 'penalty_forward_failed', $error);

        return false;
    }

    /**
     * The developer is told which person could not be reached and from
     * which account: usually somebody with no public username, whose id has
     * to be saved by hand (write to the account from the group, then put
     * the id on their card).
     */
    private function tellDeveloper(TelegramClientCheck $check, OperationUser $person, string $kind, string $error): void
    {
        $owner = app(DutyOwners::class)->penaltyOwner($person->roleOrDefault());

        app(DeveloperAlerts::class)->report(
            kind: $kind,
            title: ($kind === 'penalty_forward_failed' ? 'Штраф не доставлен: ' : 'Комментарий к штрафу не отправлен: ') . $person->name,
            details: $error . "\n\n" . 'Если у человека нет публичного username - сохраните его Telegram ID в карточке человека (операторы / продажники), '
                . 'предварительно написав ему с этого аккаунта. Если в ошибке «нет в базе» (peer database) - аккаунт-отправитель не состоит в группе, откуда приходит штраф.',
            context: [
                'Аккаунт' => $owner ?? 'Driver Check',
                'Роль' => $person->roleOrDefault(),
                'Человек (id)' => $person->id,
                'Username' => $person->telegramUsername() ?? '-',
                'Telegram ID' => $person->telegram_id ?? '-',
                'Заявка' => $check->request_number ?? '-',
                'Попыток' => $check->attempts,
            ],
            listenerKey: $owner,
            about: (string) $person->id,
        );
    }

    /**
     * The penalty goes to the person as our own message: the bot's text in
     * our words (PenaltyMessageText) - not a forward, since 2026-11-05 the
     * person must not read "штраф". Being our message, it also goes from an
     * account that is not in the group the bot posted it in.
     */
    private function write(SimpleEventHandler $telegram, TelegramClientCheck $check, int|string $peer): void
    {
        $telegram->messages->sendMessage([
            'peer' => $peer,
            'message' => $this->text->html((string) $check->message_text),
            'parse_mode' => 'html',
            'no_webpage' => true,
        ]);
    }

    /**
     * The person's row says how the last private message went, the same as
     * for the operator report copies: the people pages show it.
     */
    private function recordDelivery(OperationUser $person, ?string $error): void
    {
        try {
            $person->forceFill($error === null
                ? ['dm_last_sent_at' => now(), 'dm_last_error' => null]
                : ['dm_last_error' => mb_substr($error, 0, self::ERROR_LIMIT)])
                ->save();
        } catch (Throwable $e) {
            Log::warning(
                'Client check delivery could not be recorded on the person',
                [
                    'operation_user_id' => $person->id,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Runs on the listener's cron.
     */
    public function flush(SimpleEventHandler $telegram, ?string $key = null): void
    {
        /*
         * Only the penalties of the roles this account delivers (DutyOwners:
         * sales and operators may be written to from different accounts).
         */
        $roles = app(DutyOwners::class)->penaltyRoles($key);

        /*
         * Switched off: nothing more goes out - no retry, no comment. A
         * forward already delivered stays delivered; the comment it was
         * waiting for is called off rather than left to time out as an
         * error.
         */
        if (! TelegramSetting::clientChecksEnabled()) {
            TelegramClientCheck::query()
                ->where('status', TelegramClientCheckStatus::Forwarded)
                ->update([
                    'status' => TelegramClientCheckStatus::Sent,
                    'reason' => TelegramClientCheck::REASON_DISABLED,
                ]);

            return;
        }

        /*
         * A failed forward is not retried after hours: that would be a
         * penalty sent at 18:05. A comment still follows a forward that
         * went out in time, so a pair is never cut in half.
         */
        if ($roles === []) {
            return;
        }

        if ($this->escalation->rules()->withinWorkingHours()) {
            $this->retryForwards($telegram, $roles);
        }

        $this->expireStale();

        $this->sendComments($telegram, $roles);
    }

    /**
     * @param list<string> $roles
     */
    private function retryForwards(SimpleEventHandler $telegram, array $roles): void
    {
        $checks = $this->retryable(TelegramClientCheck::REASON_FORWARD_FAILED)
            ->forRoles($roles)
            ->with('operationUser')
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($checks as $check) {
            $this->forward($telegram, $check);
        }
    }

    /**
     * A comment more than retry_minutes late is worse than none.
     */
    private function expireStale(): void
    {
        TelegramClientCheck::query()
            ->where('status', TelegramClientCheckStatus::Forwarded)
            ->where('forwarded_at', '<', now()->subMinutes($this->retryMinutes()))
            ->update([
                'status' => TelegramClientCheckStatus::Failed,
                'reason' => TelegramClientCheck::REASON_COMMENT_FAILED,
                'error' => 'Batch comment was not sent in time.',
            ]);
    }

    /**
     * @param list<string> $roles
     */
    private function sendComments(SimpleEventHandler $telegram, array $roles): void
    {
        $pending = TelegramClientCheck::query()
            ->forRoles($roles)
            ->with('operationUser')
            ->whereNotNull('operation_user_id')
            ->whereNotNull('forwarded_at')
            ->where(function (Builder $q): void {
                $q->where('status', TelegramClientCheckStatus::Forwarded)
                    ->orWhere(function (Builder $retry): void {
                        $retry->whereIn('id', $this->retryable(TelegramClientCheck::REASON_COMMENT_FAILED)->select('id'));
                    });
            })
            ->orderBy('id')
            ->get();

        $quietFrom = now()->subSeconds($this->escalation->quietSeconds());

        /*
         * One comment per person per burst, whatever the requests: the bot
         * often posts several of one person's penalties at once, and the
         * same text twice in a row reads as a glitch (2026-10-06). The
         * strongest penalty speaks for the batch (ClientCheckEscalation).
         */
        $batches = $pending->groupBy('operation_user_id');

        foreach ($batches as $batch) {
            /** @var TelegramClientCheck $last */
            $last = $batch->last();

            /*
             * The bot may still be posting this person's penalties.
             */
            if ($last->created_at > $quietFrom) {
                continue;
            }

            $this->comment($telegram, $batch->values());
        }
    }

    /**
     * @param Collection<int, TelegramClientCheck> $batch oldest first
     */
    private function comment(SimpleEventHandler $telegram, Collection $batch): void
    {
        /** @var TelegramClientCheck $last */
        $last = $batch->last();

        $person = $last->operationUser;

        if (! $person instanceof OperationUser) {
            return;
        }

        $ids = $batch->pluck('id')->all();

        /*
         * Comments switched off: the forward was all this batch gets.
         */
        if (! TelegramSetting::clientCheckCommentsEnabled()) {
            TelegramClientCheck::query()->whereIn('id', $ids)->update([
                'status' => TelegramClientCheckStatus::Sent,
                'reason' => TelegramClientCheck::REASON_COMMENT_DISABLED,
                'error' => null,
                'sent_at' => now(),
            ]);

            return;
        }

        /*
         * The same, for this person only: their card says no comments.
         */
        if (! $person->allows('penalty_comments_enabled')) {
            TelegramClientCheck::query()->whereIn('id', $ids)->update([
                'status' => TelegramClientCheckStatus::Sent,
                'reason' => TelegramClientCheck::REASON_PERSON_COMMENTS_OFF,
                'error' => null,
                'sent_at' => now(),
            ]);

            return;
        }

        TelegramClientCheck::query()->whereIn('id', $ids)->increment('attempts');

        try {
            /*
             * The same level's comment went to them a moment ago: "это
             * тоже" rather than the same phrase again (PenaltyFollowUp).
             */
            $followUp = $this->followUp->comment($batch, $person);

            /*
             * Not "это тоже" this time: the forward was the whole message.
             */
            if ($followUp === false) {
                TelegramClientCheck::query()->whereIn('id', $ids)->update([
                    'status' => TelegramClientCheckStatus::Sent,
                    'reason' => TelegramClientCheck::REASON_QUIET,
                    'error' => null,
                    'sent_at' => now(),
                ]);

                return;
            }

            /*
             * The person's own answer for this level, when it comes up -
             * a voice with their name, say - otherwise the shared phrase.
             */
            $personal = $followUp === null ? $this->escalation->personal($batch, $person) : null;

            $comment = $followUp ?? ($personal === null ? $this->escalation->comment($batch, $person) : null);

            /*
             * No comment at this level for this role: the forward was the
             * whole message. Done, and said so on the row.
             */
            if ($personal === null && $comment === null && ! $this->escalation->commentAllowed($batch, $person)) {
                TelegramClientCheck::query()->whereIn('id', $ids)->update([
                    'status' => TelegramClientCheckStatus::Sent,
                    'reason' => TelegramClientCheck::REASON_FORWARD_ONLY,
                    'error' => null,
                    'sent_at' => now(),
                ]);

                return;
            }

            if ($personal !== null) {
                $sent = $this->delivery->send(
                    $telegram,
                    $this->peer($last),
                    ['language' => $personal['language'], 'items' => [$personal['item']]],
                    $personal['check'],
                    $person,
                );

                $personal['check']->update([
                    'phrase_index' => null,
                    'comment_level' => $personal['level'],
                    'comment' => $sent,
                    'batch_count' => $batch->count(),
                    'metrics' => ['personal' => true, 'language' => $personal['language']],
                ]);
            } elseif ($comment !== null) {
                $telegram->messages->sendMessage([
                    'peer' => $this->peer($last),
                    'message' => $comment,
                    'parse_mode' => 'html',
                    'no_webpage' => true,
                ]);
            }

            TelegramClientCheck::query()->whereIn('id', $ids)->update([
                'status' => TelegramClientCheckStatus::Sent,
                'reason' => null,
                'error' => null,
                'sent_at' => now(),
            ]);

            Log::info(
                'Client check comment sent',
                [
                    'operation_user_id' => $person->id,
                    'check_ids' => $ids,
                    'level' => $last->refresh()->comment_level,
                    'personal' => $personal !== null,
                    'follow_up' => $followUp !== null,
                ],
            );
        } catch (Throwable $e) {
            $this->fail(
                $batch,
                TelegramClientCheck::REASON_COMMENT_FAILED,
                $e->getMessage(),
            );

            $this->tellDeveloper($last, $person, 'penalty_comment_failed', $e->getMessage());
        }
    }

    /**
     * Failed for $reason, attempts left, still fresh.
     */
    private function retryable(string $reason): Builder
    {
        return TelegramClientCheck::query()
            ->where('status', TelegramClientCheckStatus::Failed)
            ->where('reason', $reason)
            ->where('attempts', '<', $this->escalation->rules()->maxAttempts)
            ->where('created_at', '>=', now()->subMinutes($this->retryMinutes()));
    }

    private function retryMinutes(): int
    {
        return $this->escalation->rules()->retryMinutes;
    }

    /**
     * The peer column is a string; a numeric one was a Telegram id.
     */
    private function peer(TelegramClientCheck $check): int|string
    {
        $peer = (string) $check->peer;

        return preg_match('/^-?\d+$/', $peer) === 1
            ? (int) $peer
            : $peer;
    }

    /**
     * @param Collection<int, TelegramClientCheck> $checks
     */
    private function fail(Collection $checks, string $reason, string $error): void
    {
        $ids = $checks->pluck('id')->all();

        TelegramClientCheck::query()->whereIn('id', $ids)->update([
            'status' => TelegramClientCheckStatus::Failed,
            'reason' => $reason,
            'error' => mb_substr($error, 0, self::ERROR_LIMIT),
        ]);

        foreach ($checks as $check) {
            $check->refresh();
        }

        Log::warning(
            'Client check not delivered',
            [
                'check_ids' => $ids,
                'reason' => $reason,
                'error' => $error,
            ],
        );
    }
}
