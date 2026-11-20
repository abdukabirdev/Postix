<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use App\Application\Telegram\Services\DeveloperAlerts;
use App\Models\Telegram\TelegramAiCall;
use App\Models\Telegram\TelegramSetting;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Where the AI works and whether it may work right now.
 *
 * Each area (driver_check, penalties, replies) is off, shadow or live. On
 * top of that the AI as a whole can be paused - by money or key trouble
 * (AiClient), by the panel - and the month's spend can reach the cap. In
 * any of these cases the free rules work as they did before the AI.
 *
 * Kept in one TelegramSetting row and read from the database on every use:
 * the listeners are long-running, a switch of the panel must reach them on
 * the next message.
 */
final class AiSettings
{
    public const KEY = 'ai.settings';

    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_LIVE = 'live';

    public const MODES = [self::MODE_OFF, self::MODE_SHADOW, self::MODE_LIVE];

    public function __construct(
        private readonly DeveloperAlerts $alerts,
    ) {}

    /**
     * @return array{modes: array<string, string>, monthly_cap: float, paused: array|null}
     */
    public function all(): array
    {
        $stored = TelegramSetting::get(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];

        $modes = [];

        foreach ($this->areas() as $area) {
            $mode = $stored['modes'][$area] ?? self::MODE_OFF;
            $modes[$area] = in_array($mode, self::MODES, true) ? $mode : self::MODE_OFF;
        }

        return [
            'modes' => $modes,
            'monthly_cap' => (float) ($stored['monthly_cap'] ?? config('ai.monthly_cap', 10)),
            'paused' => is_array($stored['paused'] ?? null) ? $stored['paused'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function areas(): array
    {
        return array_values((array) config('ai.areas', ['driver_check', 'penalties', 'replies']));
    }

    public function mode(string $area): string
    {
        return $this->all()['modes'][$area] ?? self::MODE_OFF;
    }

    /**
     * The mode the area really works in now: shadow or live, or null when
     * it is off or the AI may not be asked (paused, cap, no key) - then
     * the free rules answer.
     */
    public function effective(string $area): ?string
    {
        $mode = $this->mode($area);

        if ($mode === self::MODE_OFF || $this->blocker() !== null) {
            return null;
        }

        return $mode;
    }

    /**
     * Why the AI may not be asked at all now, or null.
     *
     * no_key | paused | cap
     */
    public function blocker(): ?string
    {
        if ((string) config('ai.api_key') === '') {
            return 'no_key';
        }

        if ($this->activePause() !== null) {
            return 'paused';
        }

        if ($this->capReached()) {
            return 'cap';
        }

        return null;
    }

    /**
     * The pause still in force: {reason, at, until}; null once its time ran
     * out - the next call then tries the AI again.
     */
    public function activePause(): ?array
    {
        $paused = $this->all()['paused'];

        if ($paused === null) {
            return null;
        }

        $until = isset($paused['until']) ? Carbon::parse($paused['until']) : null;

        if ($until !== null && $until->isPast()) {
            return null;
        }

        return $paused;
    }

    /**
     * @param array<string, string> $modes
     */
    public function setModes(array $modes): void
    {
        $this->store(function (array $stored) use ($modes): array {
            foreach ($modes as $area => $mode) {
                if (in_array($area, $this->areas(), true) && in_array($mode, self::MODES, true)) {
                    $stored['modes'][$area] = $mode;
                }
            }

            return $stored;
        });
    }

    public function setCap(float $usd): void
    {
        $this->store(function (array $stored) use ($usd): array {
            $stored['monthly_cap'] = max(0, round($usd, 2));

            return $stored;
        });
    }

    /**
     * Money or key trouble: the free rules answer for pause_minutes, then
     * the AI is tried again. Told to the developer once per pause.
     */
    public function pause(string $reason, ?int $minutes = null): void
    {
        $minutes ??= (int) config('ai.pause_minutes', 60);
        $already = $this->activePause() !== null;

        $this->store(function (array $stored) use ($reason, $minutes): array {
            $stored['paused'] = [
                'reason' => mb_substr($reason, 0, 500),
                'at' => now()->toIso8601String(),
                'until' => $minutes > 0 ? now()->addMinutes($minutes)->toIso8601String() : null,
            ];

            return $stored;
        });

        if (! $already) {
            $this->alerts->report(
                'ai_paused',
                'ИИ на паузе: работают бесплатные правила',
                $reason,
                ['minutes' => $minutes],
            );
        }
    }

    public function resume(): void
    {
        $this->store(function (array $stored): array {
            $stored['paused'] = null;

            return $stored;
        });
    }

    public function monthSpend(): float
    {
        try {
            return (float) TelegramAiCall::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('cost_usd');
        } catch (Throwable) {
            return 0.0;
        }
    }

    public function capReached(): bool
    {
        $cap = $this->all()['monthly_cap'];

        return $cap > 0 && $this->monthSpend() >= $cap;
    }

    private function store(callable $change): void
    {
        $stored = TelegramSetting::get(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];

        TelegramSetting::set(self::KEY, $change($stored));
    }
}
