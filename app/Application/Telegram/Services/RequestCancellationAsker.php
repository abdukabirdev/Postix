<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramRequestCancellation;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks why a request was cancelled: a reply to the request's last penalty
 * in the person's private chat - "Почему отменили?" under
 * "🆘 Повторное напоминание №5 по запросу #EGS21301".
 *
 * Runs on the tick of the account that delivers the person's penalties
 * (DutyOwners): the penalty is in that account's chat with them. The texts
 * are in config/client_checks.php (cancellation), in the person's language.
 * Only within the working hours; what waits longer than `wait_hours` is
 * given up.
 */
final class RequestCancellationAsker
{
    public function __construct(
        private readonly ClientCheckEscalation $escalation,
    ) {
    }

    public function flush(SimpleEventHandler $telegram, ?string $key = null): void
    {
        if (! (bool) config('client_checks.cancellation.enabled', true)) {
            return;
        }

        $roles = app(DutyOwners::class)->penaltyRoles($key);

        if ($roles === []) {
            return;
        }

        $pending = fn (): Builder => TelegramRequestCancellation::query()
            ->where('status', TelegramRequestCancellation::STATUS_PENDING)
            ->whereHas('clientCheck', fn (Builder $check) => $check->forRoles($roles));

        if (! TelegramSetting::clientChecksEnabled()) {
            $pending()->update([
                'status' => TelegramRequestCancellation::STATUS_SKIPPED,
                'reason' => TelegramRequestCancellation::REASON_DISABLED,
            ]);

            return;
        }

        $pending()
            ->where('created_at', '<', now()->subHours(max(1, (int) config('client_checks.cancellation.wait_hours', 24))))
            ->update([
                'status' => TelegramRequestCancellation::STATUS_SKIPPED,
                'reason' => TelegramRequestCancellation::REASON_STALE,
            ]);

        if (! $this->escalation->rules()->withinWorkingHours()) {
            return;
        }

        $rows = $pending()
            ->with('clientCheck.operationUser')
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($rows as $row) {
            $this->ask($telegram, $row);
        }
    }

    private function ask(SimpleEventHandler $telegram, TelegramRequestCancellation $row): void
    {
        $check = $row->clientCheck;
        $person = $check?->operationUser;

        if (! $check instanceof TelegramClientCheck || ! $person instanceof OperationUser) {
            $row->update([
                'status' => TelegramRequestCancellation::STATUS_SKIPPED,
                'reason' => TelegramRequestCancellation::REASON_NO_PENALTY,
            ]);

            return;
        }

        if (! $person->allows('penalties_enabled')) {
            $row->update([
                'status' => TelegramRequestCancellation::STATUS_SKIPPED,
                'reason' => TelegramRequestCancellation::REASON_PERSON_OFF,
            ]);

            return;
        }

        /*
         * Taken by this tick: a slow send must not be picked up again by
         * the next one, two seconds later.
         */
        $claimed = TelegramRequestCancellation::query()
            ->whereKey($row->id)
            ->where('status', TelegramRequestCancellation::STATUS_PENDING)
            ->update([
                'status' => TelegramRequestCancellation::STATUS_SENDING,
                'attempts' => $row->attempts + 1,
            ]);

        if ($claimed === 0) {
            return;
        }

        /* The row as it is now in the table, so the updates below are not taken for "nothing changed". */
        $row->forceFill([
            'status' => TelegramRequestCancellation::STATUS_SENDING,
            'attempts' => $row->attempts + 1,
        ])->syncOriginal();

        $peer = $this->peer((string) $check->peer);

        try {
            $replyTo = $check->dm_message_id ?: $this->findPenalty($telegram, $peer, (string) $check->request_number);

            $language = $person->messageLanguage();

            $question = $this->escalation->render($this->phrase($language), $check, $person, $language);

            $telegram->messages->sendMessage([
                'peer' => $peer,
                'message' => $question,
                'parse_mode' => 'html',
                'no_webpage' => true,
                ...($replyTo !== null ? ['reply_to' => [
                    '_' => 'inputReplyToMessage',
                    'reply_to_msg_id' => $replyTo,
                ]] : []),
            ]);

            $row->update([
                'status' => TelegramRequestCancellation::STATUS_SENT,
                'reason' => null,
                'error' => null,
                'peer' => (string) $peer,
                'reply_to_message_id' => $replyTo,
                'question' => $question,
                'asked_at' => now(),
            ]);

            Log::info('Request cancellation: asked', [
                'cancellation_id' => $row->id,
                'request_number' => $row->request_number,
                'operation_user_id' => $person->id,
                'reply_to' => $replyTo,
            ]);
        } catch (Throwable $e) {
            $attempts = $row->attempts;
            $last = $attempts >= max(1, (int) config('client_checks.cancellation.max_attempts', 3));

            $row->update([
                'status' => $last ? TelegramRequestCancellation::STATUS_FAILED : TelegramRequestCancellation::STATUS_PENDING,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('Request cancellation: not asked', [
                'cancellation_id' => $row->id,
                'request_number' => $row->request_number,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
            ]);

            if ($last) {
                app(DeveloperAlerts::class)->report(
                    kind: 'cancellation_question_failed',
                    title: 'Не спросили про отмену запроса: ' . $person->name,
                    details: $e->getMessage(),
                    context: [
                        'Заявка' => $row->request_number ?? '-',
                        'Человек (id)' => $person->id,
                        'Peer' => (string) $peer,
                        'Попыток' => $attempts,
                    ],
                    listenerKey: app(DutyOwners::class)->penaltyOwner($person->roleOrDefault()),
                    about: (string) $row->id,
                );
            }
        }
    }

    /**
     * A penalty written before its message id was kept: the newest of our
     * messages in the chat that is this request's penalty.
     */
    private function findPenalty(SimpleEventHandler $telegram, int|string $peer, string $request): ?int
    {
        if ($request === '') {
            return null;
        }

        try {
            $history = $telegram->messages->getHistory(['peer' => $peer, 'limit' => 100]);
        } catch (Throwable $e) {
            Log::warning('Request cancellation: history not read', [
                'peer' => (string) $peer,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $pattern = '/по\h+запросу\h*#\h*' . preg_quote($request, '/') . '(?![A-Za-z0-9])/iu';

        foreach ((array) ($history['messages'] ?? []) as $message) {
            if (
                is_array($message)
                && ($message['out'] ?? false) === true
                && preg_match($pattern, (string) ($message['message'] ?? '')) === 1
            ) {
                return (int) $message['id'];
            }
        }

        return null;
    }

    private function phrase(string $language): string
    {
        $phrases = (array) config('client_checks.cancellation.phrases', []);

        $list = array_values(array_filter(
            (array) ($phrases[$language] ?? []) ?: (array) ($phrases[OperationUser::LANGUAGE_RU] ?? []),
            static fn ($phrase): bool => is_string($phrase) && trim($phrase) !== '',
        ));

        return $list === [] ? 'Почему отменили запрос #{request}?' : $list[array_rand($list)];
    }

    /** The peer column is a string; a numeric one was a Telegram id. */
    private function peer(string $peer): int|string
    {
        return preg_match('/^-?\d+$/', $peer) === 1 ? (int) $peer : $peer;
    }
}
