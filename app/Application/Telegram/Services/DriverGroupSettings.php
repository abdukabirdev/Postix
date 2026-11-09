<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramInviteGroup;
use App\Models\Telegram\TelegramSetting;

/**
 * Drivers into a group: which of our groups (one of "Our groups" - it does
 * not have to be the current one of the invites), whether every driver
 * checked from now on goes into it, how many drivers one account adds a
 * day and the pause between two. Kept in the panel, read fresh by every run.
 */
final class DriverGroupSettings
{
    public const KEY = 'driver_group.settings';

    public const LIMITS = [
        'per_account_day' => [1, 200],
        'delay_min' => [0, 600],
        'delay_max' => [0, 600],
    ];

    /**
     * @return array{group_id: int|null, on_resolve: bool, on_resolve_since: string|null, per_account_day: int, delay_min: int, delay_max: int, paused: bool, paused_at: string|null}
     */
    public function defaults(): array
    {
        return [
            'group_id' => null,
            /* A driver checked (resolved) while this is on goes into the group, the name matched or not. */
            'on_resolve' => true,
            /* Since when it is on: drivers checked before are the "add every driver" button's. */
            'on_resolve_since' => null,
            /*
             * One call adds one driver; an account adds this many a day,
             * people it found by the number - not its contacts, so Telegram
             * holds it tighter than the invites of the base.
             */
            'per_account_day' => 25,
            'delay_min' => 20,
            'delay_max' => 40,
            /*
             * Stopped from the panel: the scheduler starts no run until
             * "Continue" (or another start button) is pressed. The queue
             * loses nothing meanwhile - the drivers checked while it stood
             * are read from the checks when it goes on.
             */
            'paused' => false,
            'paused_at' => null,
        ];
    }

    /**
     * @return array{group_id: int|null, on_resolve: bool, on_resolve_since: string|null, per_account_day: int, delay_min: int, delay_max: int, paused: bool, paused_at: string|null}
     */
    public function current(): array
    {
        $saved = TelegramSetting::get(self::KEY);

        return $this->normalise(is_array($saved) ? $saved : []);
    }

    /**
     * Turning "add on resolve" on (or picking the group with it on) starts
     * its clock: the drivers checked from then on.
     *
     * @param array<string, mixed> $input
     *
     * @return array{group_id: int|null, on_resolve: bool, on_resolve_since: string|null, per_account_day: int, delay_min: int, delay_max: int, paused: bool, paused_at: string|null}
     */
    public function save(array $input): array
    {
        $before = $this->current();
        $values = $this->normalise([...$before, ...$input]);

        if (! $values['on_resolve']) {
            $values['on_resolve_since'] = null;
        } elseif (! $before['on_resolve'] || $before['on_resolve_since'] === null || $before['group_id'] === null) {
            $values['on_resolve_since'] = now()->toIso8601String();
        } else {
            $values['on_resolve_since'] = $before['on_resolve_since'];
        }

        TelegramSetting::set(self::KEY, $values);

        return $values;
    }

    /**
     * The panel's "Stop": no run starts by itself until resume().
     */
    public function pause(): void
    {
        if (! $this->current()['paused']) {
            TelegramSetting::set(self::KEY, [...$this->current(), 'paused' => true, 'paused_at' => now()->toIso8601String()]);
        }
    }

    /**
     * A start button pressed: the scheduler starts the runs again.
     *
     * @return bool whether it was stopped
     */
    public function resume(): bool
    {
        if (! $this->current()['paused']) {
            return false;
        }

        TelegramSetting::set(self::KEY, [...$this->current(), 'paused' => false, 'paused_at' => null]);

        return true;
    }

    public function paused(): bool
    {
        return $this->current()['paused'];
    }

    /**
     * The group the drivers go into; null when none is picked, or it was archived.
     */
    public function group(): ?TelegramInviteGroup
    {
        $id = $this->current()['group_id'];

        return $id === null ? null : TelegramInviteGroup::query()->find($id);
    }

    /**
     * Whether there is anything for the scheduler to ask about: a group
     * picked, and not stopped from the panel.
     */
    public function active(): bool
    {
        return ! $this->paused() && $this->group() !== null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{group_id: int|null, on_resolve: bool, on_resolve_since: string|null, per_account_day: int, delay_min: int, delay_max: int, paused: bool, paused_at: string|null}
     */
    private function normalise(array $input): array
    {
        $values = array_replace($this->defaults(), array_intersect_key($input, $this->defaults()));

        foreach (self::LIMITS as $key => [$min, $max]) {
            $values[$key] = max($min, min($max, (int) $values[$key]));
        }

        $values['delay_max'] = max($values['delay_min'], $values['delay_max']);
        $values['on_resolve'] = filter_var($values['on_resolve'], FILTER_VALIDATE_BOOLEAN);
        $values['group_id'] = is_numeric($values['group_id']) && (int) $values['group_id'] > 0 ? (int) $values['group_id'] : null;
        $values['on_resolve_since'] = is_string($values['on_resolve_since']) && strtotime($values['on_resolve_since']) !== false
            ? $values['on_resolve_since']
            : null;
        $values['paused'] = filter_var($values['paused'], FILTER_VALIDATE_BOOLEAN);
        $values['paused_at'] = $values['paused'] && is_string($values['paused_at']) && strtotime($values['paused_at']) !== false
            ? $values['paused_at']
            : null;

        return $values;
    }
}
