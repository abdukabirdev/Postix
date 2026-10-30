<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramGroupInvite;
use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramScrapedUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The people the group invite works with, as one base. Every person is in
 * exactly one of four:
 *  - can: added into one of our groups at least once (can_add_to_group =
 *    true). They go first into every new group;
 *  - cannot: Telegram refused because of the person - privacy, a deleted
 *    account, too many groups (false). Junk: kept in the base, never tried
 *    again;
 *  - tried: tried, neither added nor refused - not in the group after the
 *    call, already in it, not checked yet. Tried again in other groups;
 *  - untried: no account has tried them yet. Of those, unreachable ones are
 *    nobody's contact and have no @username: no account can invite them.
 */
final class GroupInviteBase
{
    public const CAN = 'can';

    public const CANNOT = 'cannot';

    public const TRIED = 'tried';

    public const UNTRIED = 'untried';

    public const FLAGS = [self::CAN, self::CANNOT, self::TRIED, self::UNTRIED];

    private const CACHE_KEY = 'group_invite.base_counts';

    private const CACHE_SECONDS = 30;

    /** @var list<int>|null */
    private ?array $ownIds = null;

    /**
     * Everybody the invite may ever try: not a bot, not one of our accounts.
     *
     * @return Builder<TelegramScrapedUser>
     */
    public function people(): Builder
    {
        $own = $this->ownIds();

        return TelegramScrapedUser::query()
            ->where('is_bot', false)
            ->when($own !== [], fn ($q) => $q->whereNotIn('telegram_user_id', $own));
    }

    /**
     * @param Builder<TelegramScrapedUser> $query
     *
     * @return Builder<TelegramScrapedUser>
     */
    public function whereFlag(Builder $query, string $flag): Builder
    {
        return match ($flag) {
            self::CAN => $query->where('can_add_to_group', true),
            self::CANNOT => $query->where('can_add_to_group', false),
            self::TRIED => $query->whereNull('can_add_to_group')->whereHas('groupInvites', fn ($q) => $q->whereIn('status', TelegramGroupInvite::SETTLED)),
            self::UNTRIED => $query->whereNull('can_add_to_group')->whereDoesntHave('groupInvites', fn ($q) => $q->whereIn('status', TelegramGroupInvite::SETTLED)),
            default => $query,
        };
    }

    /**
     * People some account can invite: one of its contacts, or anybody with
     * a public @username while the usernames are on.
     *
     * @param Builder<TelegramScrapedUser> $query
     *
     * @return Builder<TelegramScrapedUser>
     */
    public function whereReachable(Builder $query, bool $usernames, bool $reachable = true): Builder
    {
        $contacts = DB::table('telegram_account_contacts')
            ->whereIn('status', ['added', 'skipped'])
            ->select('scraped_user_id')
            ->union(DB::table('telegram_account_contact_links')->select('scraped_user_id'));

        if ($reachable) {
            return $query->where(fn ($q) => $q
                ->whereIn('id', $contacts)
                ->when($usernames, fn ($q) => $q->orWhere(fn ($q) => $q->withUsername())));
        }

        return $query
            ->whereNotIn('id', $contacts)
            ->when($usernames, fn ($q) => $q->withoutUsername());
    }

    /**
     * The whole base in numbers.
     *
     * @param array<string, mixed> $config
     *
     * @return array{total: int, processed: int, can: int, cannot: int, tried: int, untried: int, untried_reachable: int, untried_unreachable: int}
     */
    public function counts(array $config): array
    {
        $total = $this->people()->count();
        $can = $this->whereFlag($this->people(), self::CAN)->count();
        $cannot = $this->whereFlag($this->people(), self::CANNOT)->count();
        $tried = $this->triedCount();
        $untried = max(0, $total - $can - $cannot - $tried);
        $reachable = $this->whereReachable($this->whereFlag($this->people(), self::UNTRIED), (bool) $config['use_usernames'])->count();

        return [
            'total' => $total,
            'processed' => $can + $cannot + $tried,
            'can' => $can,
            'cannot' => $cannot,
            'tried' => $tried,
            'untried' => $untried,
            'untried_reachable' => $reachable,
            'untried_unreachable' => max(0, $untried - $reachable),
        ];
    }

    /**
     * The counts as the pages show them: counted again at most every half a
     * minute - the overview asks every few seconds while a run goes on, and
     * a big base is a few full scans.
     *
     * @param array<string, mixed> $config
     *
     * @return array{total: int, processed: int, can: int, cannot: int, tried: int, untried: int, untried_reachable: int, untried_unreachable: int}
     */
    public function cachedCounts(array $config): array
    {
        return Cache::remember(
            self::CACHE_KEY . ((bool) $config['use_usernames'] ? ':u' : ''),
            self::CACHE_SECONDS,
            fn () => $this->counts($config),
        );
    }

    /**
     * After the page changed who stands where (a reset, a retry).
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY . ':u');
    }

    /**
     * Who is still left to try for a group, among the people an account
     * can reach: those already added somewhere, and the rest not refused.
     * With the "proven" audience the rest waits.
     *
     * @param array<string, mixed> $config
     *
     * @return array{proven: int, other: int}
     */
    public function queue(TelegramInviteGroup $group, array $config): array
    {
        $left = fn () => $this->whereReachable($this->people(), (bool) $config['use_usernames'])
            ->whereDoesntHave('groupInvites', fn ($q) => $q->where('invite_group_id', $group->id));

        return [
            'proven' => $left()->where('can_add_to_group', true)->count(),
            'other' => $left()->whereNull('can_add_to_group')->count(),
        ];
    }

    /**
     * People tried in some group, neither added nor refused - counted from
     * the tries, not the whole base: those are a few thousand rows.
     */
    private function triedCount(): int
    {
        $own = $this->ownIds();

        return TelegramGroupInvite::query()
            ->join('telegram_scraped_users as u', 'u.id', '=', 'telegram_group_invites.scraped_user_id')
            ->whereIn('telegram_group_invites.status', TelegramGroupInvite::SETTLED)
            ->whereNull('u.can_add_to_group')
            ->where('u.is_bot', false)
            ->when($own !== [], fn ($q) => $q->whereNotIn('u.telegram_user_id', $own))
            ->distinct()
            ->count('telegram_group_invites.scraped_user_id');
    }

    /**
     * @return list<int>
     */
    private function ownIds(): array
    {
        return $this->ownIds ??= TelegramAccount::query()
            ->whereNotNull('telegram_user_id')
            ->pluck('telegram_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Where one person stands, from the flag and whether a try settled.
     */
    public static function flagOf(?bool $canAdd, bool $tried): string
    {
        return match (true) {
            $canAdd === true => self::CAN,
            $canAdd === false => self::CANNOT,
            $tried => self::TRIED,
            default => self::UNTRIED,
        };
    }
}
