<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use Illuminate\Support\Collection;

/**
 * The people the penalties never reach an answer from: forwarded to them
 * `min_penalties` times in the last `days` days and not once answered - no
 * word, no reaction (ProcessAutoReply keeps both on the penalty as
 * replied_at) - and no private message of theirs in that time either.
 *
 * Found on 2026-11-13: three people had 500 of our messages in their chat
 * and nothing back. Writing to them again changes nothing, so they are
 * listed (report /api/telegram/report/silent-people, `telegram:silent-people`)
 * and, only when asked (`--mute`), switched off for private messages.
 */
final class SilentPeople
{
    public const DEFAULT_DAYS = 14;

    public const DEFAULT_MIN_PENALTIES = 30;

    /**
     * @return Collection<int, array{person: OperationUser, penalties: int, replies: int}>
     */
    public function find(?int $days = null, ?int $minPenalties = null): Collection
    {
        $days = $days ?? (int) config('client_checks.silent_people.days', self::DEFAULT_DAYS);
        $minPenalties = $minPenalties ?? (int) config('client_checks.silent_people.min_penalties', self::DEFAULT_MIN_PENALTIES);

        $since = now()->subDays(max(1, $days));

        $stats = TelegramClientCheck::query()
            ->whereNotNull('operation_user_id')
            ->whereNotNull('forwarded_at')
            ->where('forwarded_at', '>=', $since)
            ->selectRaw('operation_user_id, COUNT(*) as penalties, SUM(CASE WHEN replied_at IS NULL THEN 0 ELSE 1 END) as replies')
            ->groupBy('operation_user_id')
            ->get()
            ->keyBy('operation_user_id');

        $silent = $stats->filter(
            fn ($row): bool => (int) $row->penalties >= $minPenalties && (int) $row->replies === 0,
        );

        if ($silent->isEmpty()) {
            return collect();
        }

        return OperationUser::query()
            ->whereIn('id', $silent->keys())
            ->orderBy('name')
            ->get()
            ->filter(fn (OperationUser $person): bool => $person->last_private_message_at === null
                || $person->last_private_message_at->lessThan($since))
            ->map(fn (OperationUser $person): array => [
                'person' => $person,
                'penalties' => (int) $silent[$person->id]->penalties,
                'replies' => 0,
            ])
            ->values();
    }
}
