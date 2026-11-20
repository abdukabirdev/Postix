<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use App\Models\Telegram\TelegramAiCall;
use Illuminate\Support\Carbon;

/**
 * What the AI is doing, for the panel and the developer's report: the
 * modes, whether it may work now and why not, the money, the counts, the
 * latest calls - and the prompts exactly as each area sends them.
 */
final class AiOverview
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiPrompts $prompts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $all = $this->settings->all();
        $pause = $this->settings->activePause();

        return [
            'model' => (string) config('ai.model'),
            'effort' => (string) config('ai.effort'),
            'key_set' => (string) config('ai.api_key') !== '',
            'modes' => $all['modes'],
            'effective' => collect($this->settings->areas())
                ->mapWithKeys(fn (string $area) => [$area => $this->settings->effective($area) ?? 'off'])
                ->all(),
            'blocker' => $this->settings->blocker(),
            'paused' => $pause,
            'monthly_cap' => $all['monthly_cap'],
            'spend' => [
                'today' => $this->spendSince(now()->startOfDay()),
                'month' => $this->settings->monthSpend(),
            ],
            'today' => $this->counts(now()->startOfDay()),
        ];
    }

    /**
     * Calls per area and status since a moment.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(Carbon $since): array
    {
        $rows = TelegramAiCall::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('area, status, used, COUNT(*) as total')
            ->groupBy('area', 'status', 'used')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $area = (string) $row->area;
            $counts[$area] ??= ['total' => 0, 'ok' => 0, 'failed' => 0, 'refused' => 0, 'used' => 0];
            $counts[$area]['total'] += (int) $row->total;
            $counts[$area][(string) $row->status] = ($counts[$area][(string) $row->status] ?? 0) + (int) $row->total;

            if ((bool) $row->used) {
                $counts[$area]['used'] += (int) $row->total;
            }
        }

        return $counts;
    }

    public function spendSince(Carbon $since): float
    {
        return round((float) TelegramAiCall::query()->where('created_at', '>=', $since)->sum('cost_usd'), 6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(?string $area = null, ?string $status = null, int $limit = 50, ?int $before = null): array
    {
        return TelegramAiCall::query()
            ->with('operationUser:id,name')
            ->when($area, fn ($q) => $q->where('area', $area))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->latest('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (TelegramAiCall $call) => [
                'id' => $call->id,
                'at' => $call->created_at?->toIso8601String(),
                'area' => $call->area,
                'kind' => $call->kind,
                'mode' => $call->mode,
                'status' => $call->status,
                'used' => $call->used,
                'person' => $call->operationUser?->name,
                'subject' => $call->subject_type ? $call->subject_type . ' #' . $call->subject_id : null,
                'request' => $call->request,
                'answer' => $call->answer,
                'free_text' => $call->free_text,
                'error' => $call->error,
                'tokens' => [
                    'input' => $call->input_tokens,
                    'output' => $call->output_tokens,
                    'cache_read' => $call->cache_read_tokens,
                    'cache_write' => $call->cache_write_tokens,
                ],
                'cost_usd' => $call->cost_usd,
                'duration_ms' => $call->duration_ms,
            ])
            ->all();
    }

    /**
     * The prompts, and for each area the parts its system prompt is made
     * of, in the order they are sent - what "the whole prompt" means.
     *
     * @return array<string, mixed>
     */
    public function prompts(): array
    {
        return [
            'items' => $this->prompts->all(),
            'areas' => [
                'driver_check' => [
                    'system' => ['driver_check'],
                    'user' => app(DriverNameJudge::class)->question(
                        [
                            'expected_name' => 'GIYASOV KURBONALI ABDURAHIMOVICH',
                            'score' => 38.5,
                            'matched_parts' => [['from' => 'ALI', 'to' => 'KURBONALI', 'score' => 38.5]],
                        ],
                        'Али',
                        null,
                        null,
                    ),
                ],
                'penalties' => [
                    'system' => ['common', 'examples', 'penalties'],
                    'user' => null,
                ],
                'replies' => [
                    'system' => ['common', 'examples', 'replies'],
                    'user' => null,
                ],
            ],
        ];
    }
}
