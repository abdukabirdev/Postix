<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Models\Telegram\TelegramSenderBotEvent;
use App\Models\Telegram\TelegramSenderBotGroup;
use App\Models\Telegram\TelegramSenderBotPost;
use App\Models\Telegram\TelegramSenderBotUser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the sender bot's history. A line that cannot be written never
 * stops what it describes.
 */
final class SenderBotHistory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function add(
        string $type,
        string $actor = TelegramSenderBotEvent::ACTOR_SYSTEM,
        TelegramSenderBotUser|int|null $user = null,
        TelegramSenderBotPost|int|null $post = null,
        TelegramSenderBotGroup|int|null $group = null,
        array $data = [],
    ): void {
        $id = static fn ($model): ?int => $model === null ? null : (is_int($model) ? $model : (int) $model->getKey());

        if ($user === null && $post instanceof TelegramSenderBotPost) {
            $user = $post->sender_bot_user_id;
        }

        try {
            TelegramSenderBotEvent::query()->create([
                'type' => $type,
                'actor' => $actor,
                'sender_bot_user_id' => $id($user),
                'sender_bot_post_id' => $id($post),
                'sender_bot_group_id' => $id($group),
                'data' => $data === [] ? null : $data,
            ]);
        } catch (Throwable $e) {
            Log::warning('Sender bot: a history line was not written', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
