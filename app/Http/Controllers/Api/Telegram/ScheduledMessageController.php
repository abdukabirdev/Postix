<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramDriverCheckChat;
use App\Models\Telegram\TelegramFileRoute;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramScheduledMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Scheduled messages page: what the listener's account writes into
 * which groups, and when. The listener reads the rows every few seconds,
 * so a change applies at once.
 */
final class ScheduledMessageController extends Controller
{
    public function index(): JsonResponse
    {
        $messages = TelegramScheduledMessage::query()->orderBy('send_at')->orderBy('id')->get();

        return response()->json([
            'messages' => $messages->map(fn (TelegramScheduledMessage $m): array => $this->row($m))->all(),
            'groups' => $this->groups($messages),
            'listeners' => $this->listeners(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $message = new TelegramScheduledMessage();

        $this->fill($message, $request);

        return response()->json(['data' => $this->row($message)], 201);
    }

    public function update(Request $request, TelegramScheduledMessage $scheduledMessage): JsonResponse
    {
        $this->fill($scheduledMessage, $request);

        return response()->json(['data' => $this->row($scheduledMessage)]);
    }

    public function destroy(TelegramScheduledMessage $scheduledMessage): JsonResponse
    {
        $scheduledMessage->delete();

        return response()->json(['message' => __('telegram.scheduled.messages.deleted')]);
    }

    /**
     * Asks the listener to send it now; it does within a few seconds.
     */
    public function send(TelegramScheduledMessage $scheduledMessage): JsonResponse
    {
        if (! $scheduledMessage->hasText()) {
            return response()->json(['message' => __('telegram.scheduled.errors.no_text')], 422);
        }

        $scheduledMessage->update(['send_requested_at' => now()]);

        return response()->json(['data' => $this->row($scheduledMessage)]);
    }

    private function fill(TelegramScheduledMessage $message, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /* Empty is a slot waiting for its text: it is kept, and not sent. */
            'text' => ['nullable', 'string', 'max:4000'],
            'send_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'days' => ['present', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'chat_ids' => ['present', 'array', 'max:50'],
            'chat_ids.*' => ['regex:/^-?\d{5,20}$/'],
            'listener_key' => ['nullable', 'string', Rule::exists('telegram_listeners', 'key')],
            'is_active' => ['required', 'boolean'],
        ]);

        $wasEmpty = $message->exists && trim((string) $message->getOriginal('text')) === '';

        $message->fill([
            'name' => trim($data['name']),
            'text' => trim((string) ($data['text'] ?? '')),
            'send_at' => $data['send_at'],
            'days' => array_values(array_unique(array_map('intval', $data['days']))),
            'chat_ids' => array_values(array_unique(array_map('intval', $data['chat_ids']))),
            'listener_key' => $data['listener_key'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ]);

        /*
         * Saved after its time today: today is over for it. Without this a
         * message made at 18:00 for 17:00 would go out within seconds. A
         * slot getting its text is the same: pasted at 11:00 for 10:00, it
         * starts tomorrow.
         */
        $filled = $wasEmpty && $message->hasText();

        if (! $message->exists || $filled || $message->isDirty(['send_at', 'is_active', 'days'])) {
            $message->last_sent_on = $message->send_at <= now()->format('H:i') ? now()->toDateString() : null;
        }

        $message->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TelegramScheduledMessage $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'text' => (string) $m->text,
            'has_text' => $m->hasText(),
            'send_at' => $m->send_at,
            'days' => $m->weekdays(),
            'chat_ids' => $m->targets(),
            'listener_key' => $m->listener_key,
            'is_active' => (bool) $m->is_active,
            'queued' => $m->send_requested_at !== null,
            'next_at' => $m->nextRunAt(now())?->toIso8601String(),
            'last_run_at' => $m->last_run_at?->toIso8601String(),
            'last_status' => $m->last_status,
            'last_error' => $m->last_error,
        ];
    }

    /**
     * Whose account a message can be written from: Driver Check, and every
     * listener that is on and has an account.
     *
     * @return list<array{key: string|null, name: string}>
     */
    private function listeners(): array
    {
        $extra = TelegramListener::query()
            ->where('is_enabled', true)
            ->whereNotNull('telegram_account_id')
            ->orderBy('name')
            ->get()
            ->map(fn (TelegramListener $l): array => ['key' => $l->key, 'name' => $l->name])
            ->values()
            ->all();

        return [['key' => null, 'name' => 'Driver Check'], ...$extra];
    }

    /**
     * The groups to pick from: where the files go, and wherever a message
     * already goes; with the name when the watch list knows it.
     *
     * @param iterable<TelegramScheduledMessage> $messages
     *
     * @return list<array{id: int, title: string|null}>
     */
    private function groups(iterable $messages): array
    {
        $ids = TelegramFileRoute::query()->get()->flatMap(fn (TelegramFileRoute $r): array => $r->targets());

        foreach ($messages as $message) {
            $ids = $ids->merge($message->targets());
        }

        $ids = $ids->unique()->values();

        $titles = TelegramDriverCheckChat::query()
            ->whereIn('chat_id', $ids)
            ->pluck('title', 'chat_id');

        return $ids->map(fn (int $id): array => [
            'id' => $id,
            'title' => ($titles[$id] ?? null) ?: null,
        ])->all();
    }
}
