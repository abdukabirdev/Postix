<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\SenderBotService;
use App\Application\Telegram\Services\SenderBotSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The sender bot's updates. The webhook is set in izicrm.uz (our server
 * does not get Telegram's webhooks); its TelegramPostixSenderBotController
 * forwards every update here with X-Postix-Secret:
 * Telegram -> https://izicrm.uz/api/telegram/postix-sender-webhook -> here.
 * This project never sets or deletes that webhook; it only answers by the token.
 */
final class SenderBotWebhookController extends Controller
{
    /** The same secret sits in the izicrm controller. */
    private const SECRET = 'c2283uJ2AdZxSQO-oD9XjDNesZ8CLkLnNUhg-K9xuws';

    /** One update is handled once (Telegram and izicrm may send it again), seconds. */
    private const SEEN_FOR = 86400;

    public function handle(Request $request, SenderBotService $bot, SenderBotSettings $settings): JsonResponse
    {
        if (! hash_equals(self::SECRET, (string) $request->header('X-Postix-Secret'))) {
            Log::warning('Sender bot webhook: wrong secret', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 403);
        }

        $update = $request->json()->all();

        if (! isset($update['update_id'])) {
            return response()->json(['ok' => false, 'error' => 'no update_id'], 422);
        }

        /* Off or not connected in the panel: taken and left, or Telegram keeps sending it. */
        if (! $settings->enabled()) {
            return response()->json(['ok' => true, 'skipped' => 'disabled']);
        }

        if (! Cache::add('sender_bot:update:' . $update['update_id'], true, self::SEEN_FOR)) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate']);
        }

        try {
            $bot->handleUpdate($update);
        } catch (Throwable $e) {
            /* Still 200: on an error Telegram would send this update again and again, holding back the next ones. */
            Log::error('Sender bot webhook: update failed', [
                'update_id' => $update['update_id'],
                'error' => $e->getMessage(),
            ]);

            $settings->setState(['error' => mb_substr($e->getMessage(), 0, 300), 'error_at' => now()->toIso8601String()]);

            return response()->json(['ok' => true, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }

        return response()->json(['ok' => true]);
    }
}
