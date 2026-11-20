<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Ai\AiClient;
use App\Application\Telegram\Ai\AiOverview;
use App\Application\Telegram\Ai\AiPrompts;
use App\Application\Telegram\Ai\AiSettings;
use App\Application\Telegram\Ai\DriverNameJudge;
use App\Application\Telegram\Services\TelegramNameMatcher;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAiCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Behind Driver Check > ИИ: the modes of each area, the monthly cap, the
 * pause, the journal of calls, the prompts (edit, reset) and a test of the
 * driver-name prompt on any names.
 *
 * Access: the `role:driverCheck,superadmin` route group.
 */
final class AiController extends Controller
{
    public function show(AiOverview $overview): JsonResponse
    {
        return response()->json(['data' => $overview->state()]);
    }

    public function update(Request $request, AiSettings $settings, AiOverview $overview): JsonResponse
    {
        $validated = $request->validate([
            'modes' => ['sometimes', 'array'],
            'modes.*' => ['string', Rule::in(AiSettings::MODES)],
            'monthly_cap' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
        ]);

        if (isset($validated['modes'])) {
            $settings->setModes($validated['modes']);
        }

        if (array_key_exists('monthly_cap', $validated)) {
            $settings->setCap((float) $validated['monthly_cap']);
        }

        return response()->json(['data' => $overview->state()]);
    }

    public function resume(AiSettings $settings, AiOverview $overview): JsonResponse
    {
        $settings->resume();

        return response()->json(['data' => $overview->state()]);
    }

    public function calls(Request $request, AiOverview $overview): JsonResponse
    {
        $validated = $request->validate([
            'area' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in([TelegramAiCall::STATUS_OK, TelegramAiCall::STATUS_FAILED, TelegramAiCall::STATUS_REFUSED])],
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'data' => $overview->calls(
                $validated['area'] ?? null,
                $validated['status'] ?? null,
                (int) ($validated['limit'] ?? 50),
                isset($validated['before']) ? (int) $validated['before'] : null,
            ),
        ]);
    }

    public function prompts(AiOverview $overview): JsonResponse
    {
        return response()->json(['data' => $overview->prompts()]);
    }

    public function savePrompt(Request $request, string $key, AiPrompts $prompts, AiOverview $overview): JsonResponse
    {
        abort_unless(in_array($key, $prompts->keys(), true), 404);

        $validated = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:' . AiPrompts::MAX_LENGTH],
        ]);

        $prompts->save($key, (string) ($validated['text'] ?? ''));

        return response()->json(['data' => $overview->prompts()]);
    }

    public function resetPrompt(string $key, AiPrompts $prompts, AiOverview $overview): JsonResponse
    {
        abort_unless(in_array($key, $prompts->keys(), true), 404);

        $prompts->reset($key);

        return response()->json(['data' => $overview->prompts()]);
    }

    /**
     * The driver-name prompt on any names, whatever the area's mode: the
     * code's verdict and Claude's answer side by side. Written into the
     * journal as area "test"; money counts as usual.
     */
    public function testDriver(
        Request $request,
        TelegramNameMatcher $matcher,
        DriverNameJudge $judge,
        AiClient $client,
        AiPrompts $prompts,
    ): JsonResponse {
        $validated = $request->validate([
            'expected' => ['required', 'string', 'max:200'],
            'first_name' => ['nullable', 'string', 'max:200'],
            'last_name' => ['nullable', 'string', 'max:200'],
            'username' => ['nullable', 'string', 'max:100'],
        ]);

        $match = $matcher->match(
            $validated['expected'],
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null,
            $validated['username'] ?? null,
        );

        $question = $judge->question($match, $validated['first_name'] ?? null, $validated['last_name'] ?? null, $validated['username'] ?? null);

        $answer = $client->ask(
            area: 'test',
            kind: 'driver_check',
            mode: AiSettings::MODE_SHADOW,
            system: [$prompts->get('driver_check')],
            user: $question,
            schema: [
                'type' => 'object',
                'properties' => [
                    'verdict' => ['type' => 'string', 'enum' => ['match', 'mismatch']],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['verdict', 'reason'],
                'additionalProperties' => false,
            ],
        );

        return response()->json([
            'data' => [
                'code' => [
                    'matched' => (bool) ($match['matched'] ?? false),
                    'score' => round((float) ($match['score'] ?? 0), 1),
                    'level' => $match['level'] ?? null,
                ],
                'question' => $question,
                'ai' => $answer->data,
                'error' => $answer->error,
                'cost_usd' => $answer->call?->cost_usd,
                'duration_ms' => $answer->call?->duration_ms,
            ],
        ]);
    }
}
