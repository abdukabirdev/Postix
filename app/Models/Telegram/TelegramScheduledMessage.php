<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A message the listener's account writes into groups at a set time on
 * set days.
 *
 * @property int            $id
 * @property string         $name
 * @property string         $text
 * @property list<string>|null $variants
 * @property string         $send_at
 * @property int            $jitter_minutes
 * @property bool           $skip_if_file_today
 * @property list<int>|null $days
 * @property list<int>|null $chat_ids
 * @property bool           $is_active
 * @property string|null    $last_status
 */
class TelegramScheduledMessage extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /** Every group was left out: the report it asks for was already there. */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * How long after its time a message may still go out: a listener that
     * was down at 17:00 catches up at 17:20, not at midnight.
     */
    public const CATCH_UP_MINUTES = 120;

    protected $fillable = [
        'name',
        'text',
        'variants',
        'send_at',
        'jitter_minutes',
        'days',
        'chat_ids',
        'listener_key',
        'is_active',
        'skip_if_file_today',
        'last_sent_on',
        'send_requested_at',
        'last_run_at',
        'last_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'variants' => 'array',
            'days' => 'array',
            'chat_ids' => 'array',
            'is_active' => 'boolean',
            'jitter_minutes' => 'integer',
            'skip_if_file_today' => 'boolean',
            'last_sent_on' => 'date:Y-m-d',
            'send_requested_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return list<int>
     */
    public function targets(): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter(
                (array) ($this->chat_ids ?? []),
                static fn (mixed $id): bool => is_numeric($id) && (int) $id !== 0,
            ),
        )));
    }

    /**
     * ISO weekdays it goes out on; all seven when none were chosen.
     *
     * @return list<int>
     */
    public function weekdays(): array
    {
        $days = array_values(array_unique(array_filter(
            array_map('intval', (array) ($this->days ?? [])),
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        )));

        sort($days);

        return $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days;
    }

    public function runsOn(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->weekdays(), true);
    }

    /**
     * Every text it may go out with: the main one first, then the variants.
     * Blank ones are left out.
     *
     * @return list<string>
     */
    public function texts(): array
    {
        $texts = array_map(
            static fn (mixed $text): string => trim((string) $text),
            [$this->text, ...array_values((array) ($this->variants ?? []))],
        );

        return array_values(array_filter($texts, static fn (string $text): bool => $text !== ''));
    }

    /**
     * One of the texts, picked at random: a group gets one wording, the next
     * group may get another.
     */
    public function pickText(): string
    {
        $texts = $this->texts();

        return $texts === [] ? '' : $texts[array_rand($texts)];
    }

    /**
     * A message may be saved without its text: a slot set up in advance
     * (time, days, groups, account), the text pasted in later. Until then
     * it never goes out.
     */
    public function hasText(): bool
    {
        return $this->texts() !== [];
    }

    /**
     * The minute it goes out at on $day: its time, moved by up to
     * jitter_minutes either way, differently each day and always the same
     * for the same day (the listener looks every few seconds).
     */
    public function runAt(CarbonInterface $day): CarbonInterface
    {
        $at = $day->copy()->setTimeFromTimeString($this->send_at);

        $jitter = max(0, (int) $this->jitter_minutes);

        if ($jitter === 0) {
            return $at;
        }

        $shift = crc32(((int) $this->id) . '|' . $day->toDateString()) % (2 * $jitter + 1) - $jitter;

        return $at->addMinutes($shift);
    }

    /**
     * Due from its time on, for CATCH_UP_MINUTES, until today's has gone out.
     */
    public function isDue(CarbonInterface $now): bool
    {
        if (! $this->is_active || ! $this->hasText() || $this->targets() === [] || ! $this->runsOn($now)) {
            return false;
        }

        if ($this->last_sent_on?->toDateString() === $now->toDateString()) {
            return false;
        }

        $at = $this->runAt($now);

        $late = (int) $at->diffInMinutes($now, false);

        return $late >= 0 && $late <= self::CATCH_UP_MINUTES;
    }

    /**
     * The next time it goes out, or null while it is off, has no text or no group.
     */
    public function nextRunAt(CarbonInterface $now): ?CarbonInterface
    {
        if (! $this->is_active || ! $this->hasText() || $this->targets() === []) {
            return null;
        }

        for ($offset = 0; $offset <= 7; $offset++) {
            $at = $this->runAt($now->copy()->addDays($offset));

            if ($at->greaterThan($now) && $this->runsOn($at)) {
                return $at;
            }
        }

        return null;
    }
}
