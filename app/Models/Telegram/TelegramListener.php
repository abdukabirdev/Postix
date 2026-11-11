<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account that runs its own listener next to Driver Check: one process,
 * one MadelineProto session, a set of duties (what it does).
 *
 * @property int            $id
 * @property string         $key
 * @property string         $name
 * @property int|null       $telegram_account_id
 * @property bool           $is_enabled
 * @property list<string>|null $duties
 */
class TelegramListener extends Model
{
    /** A change to a listener ends what the request kept of "who listens" (ListenerAccounts). */
    protected static function booted(): void
    {
        $forget = static fn () => \App\Application\Telegram\Services\ListenerAccounts::forgetMemo();

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * The scheduled messages (Driver check -> Scheduled messages) set to
     * be written from this listener's account.
     */
    public const DUTY_SCHEDULED_MESSAGES = 'scheduled_messages';

    /**
     * What a listener can be switched on for, any number of them at once.
     *
     * @var list<string>
     */
    public const DUTIES = [
        self::DUTY_SCHEDULED_MESSAGES,
    ];

    /**
     * Reading the groups the bot posts penalties into, and recording them.
     * Apart from delivering them: one account may take them in and another
     * (per role, DutyOwners) write to the people. Driver Check's own reading
     * of the driver checks stays with it whatever this says.
     */
    public const DUTY_PENALTY_INTAKE = 'penalty_intake';

    /**
     * Penalties: forwarded to the person responsible, the comment after the
     * burst, the nudge for the ones nobody answered, and the answers to
     * their private replies ("+", "ok") - one conversation, so one account.
     * The recording of a penalty from the group stays with Driver Check.
     */
    public const DUTY_PENALTIES = 'penalties';

    /**
     * The notifications bots' morning files, forwarded to the groups their
     * kind is set to. The account must be in the bot's chat.
     */
    public const DUTY_FILE_ROUTES = 'file_routes';

    /**
     * What is done by exactly one account, chosen in the panel (DutyOwners).
     *
     * @var list<string>
     */
    public const EXCLUSIVE_DUTIES = [
        self::DUTY_PENALTY_INTAKE,
        self::DUTY_PENALTIES,
        self::DUTY_FILE_ROUTES,
    ];

    protected $fillable = [
        'key',
        'name',
        'telegram_account_id',
        'is_enabled',
        'duties',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'duties' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'telegram_account_id');
    }

    /**
     * @return list<string>
     */
    public function duties(): array
    {
        return array_values(array_intersect(self::DUTIES, (array) ($this->duties ?? [])));
    }

    public function hasDuty(string $duty): bool
    {
        return in_array($duty, $this->duties(), true);
    }

    /**
     * Held by the listener process while it runs (TelegramProcessLock).
     */
    public function lockName(): string
    {
        return 'listener-' . $this->key;
    }

    /**
     * Held by the watchdog that keeps the listener running.
     */
    public function watchdogLockName(): string
    {
        return 'listener-watchdog-' . $this->key;
    }
}
