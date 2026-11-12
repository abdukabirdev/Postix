<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\ChatReader;
use App\Application\Telegram\Services\ChatReadQueue;
use App\Application\Telegram\Services\DutyOwners;
use App\Application\Telegram\Services\ListenerAccounts;
use App\Http\Controllers\Api\Telegram\Concerns\ChecksReaderToken;
use App\Http\Controllers\Controller;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramAccount;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramReadRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The developer's window into Telegram, read only: GET /api/telegram/reader/...
 * with the token of TELEGRAM_READER_TOKEN as a bearer token (or X-Reader-Token).
 * A missing or wrong token gets a 404.
 *
 *   accounts                 who can be read, and which process holds each (no Telegram call)
 *   people                   operators and sales: who they are, how to reach them, which account writes to them
 *   people/{id}/history      the private chat with one of them, from the account that writes to them
 *   dialogs|history|search|chat|members|user?account=ID&...   a read of Telegram itself
 *   requests/{id}            the answer of a read that was still going when the call returned
 *
 * Only the process that holds an account's session can talk to Telegram, so a
 * read is left for it (ChatReadQueue) and the call waits ?wait= seconds
 * (20, up to 50) for the answer: 200 with it, 202 and a request id when
 * Telegram is slower (ask requests/{id}), 502 when the read failed. What was
 * read is kept an hour. Every read is a row (the audit trail).
 */
final class ChatReaderController extends Controller
{
    use ChecksReaderToken;

    public function __construct(
        private readonly ChatReadQueue $queue,
    ) {
    }

    public function accounts(Request $request): JsonResponse
    {
        $this->checkReaderToken($request);

        $listeners = TelegramListener::query()->get()->keyBy('telegram_account_id');
        $owners = app(DutyOwners::class);
        $driverCheck = (int) config('services.telegram.driver_check_account_id');

        $accounts = TelegramAccount::query()
            ->whereIn('id', ListenerAccounts::ids())
            ->orderBy('id')
            ->get()
            ->map(function (TelegramAccount $account) use ($listeners, $owners, $driverCheck): array {
                $listener = $listeners->get($account->id);
                $key = $listener?->key;

                return [
                    'id' => $account->id,
                    'name' => trim(($account->first_name ?? '') . ' ' . ($account->last_name ?? '')) ?: null,
                    'username' => $account->username,
                    'held_by' => $account->id === $driverCheck ? 'driver_check' : ($listener === null ? null : 'listener:' . $key),
                    'listener' => $listener?->name,
                    'writes_penalties_to' => $account->id === $driverCheck
                        ? $owners->penaltyRoles(null)
                        : ($key === null ? [] : $owners->penaltyRoles($key)),
                    'authorized' => (bool) $account->is_authorized,
                    'status' => $account->status,
                    'last_checked_at' => $account->last_checked_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();

        return $this->json(['accounts' => $accounts]);
    }

    public function people(Request $request): JsonResponse
    {
        $this->checkReaderToken($request);

        $data = $request->validate([
            'role' => ['nullable', Rule::in(OperationUser::ROLES)],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $query = OperationUser::query()->orderBy('role')->orderBy('name');

        if (! empty($data['role'])) {
            $query->where('role', $data['role']);
        }

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%' . $data['q'] . '%');
        }

        $people = $query->get();

        $penalties = TelegramClientCheck::query()
            ->whereIn('operation_user_id', $people->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('operation_user_id, COUNT(*) as total')
            ->groupBy('operation_user_id')
            ->pluck('total', 'operation_user_id');

        return $this->json([
            'people' => $people->map(fn (OperationUser $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'role' => $person->roleOrDefault(),
                'language' => $person->messageLanguage(),
                'username' => $person->telegramUsername(),
                'telegram_id' => $person->telegram_id,
                'reachable' => $person->hasTelegramPeer(),
                'dm_enabled' => (bool) $person->dm_enabled,
                'account_id' => $this->accountFor($person),
                'penalties_30d' => (int) ($penalties[$person->id] ?? 0),
                'last_private_message_at' => $person->last_private_message_at?->toDateTimeString(),
            ])->values()->all(),
        ]);
    }

    /**
     * The private chat with a person of the people list.
     */
    public function personHistory(Request $request, int $id): JsonResponse
    {
        $this->checkReaderToken($request);

        $person = OperationUser::query()->findOrFail($id);

        $account = $this->accountFor($person);

        if ($account === null) {
            return $this->json(['error' => 'No account writes to this person.'], 422);
        }

        if (! $person->hasTelegramPeer()) {
            return $this->json(['error' => 'The person has no Telegram username or id saved.'], 422);
        }

        $request->merge([
            'account' => $account,
            'chat' => ($person->telegram_id ?? 0) > 0 ? (string) $person->telegram_id : '@' . $person->telegramUsername(),
        ]);

        return $this->read($request, 'history');
    }

    public function read(Request $request, string $op): JsonResponse
    {
        $this->checkReaderToken($request);

        abort_unless(in_array($op, ChatReader::OPS, true), 404);

        $params = $request->validate($this->rules($op));

        $account = (int) $params['account'];

        if (! in_array($account, ListenerAccounts::ids(), true)) {
            return $this->json(['error' => 'This account is not held by a running process; see /accounts.'], 422);
        }

        unset($params['account'], $params['wait']);

        foreach (['min_date', 'max_date'] as $key) {
            if (isset($params[$key])) {
                $params[$key] = Carbon::parse($params[$key], config('app.timezone'))->getTimestamp();
            }
        }

        $row = $this->queue->ask($account, $op, array_filter($params, static fn ($value): bool => $value !== null));

        return $this->answer($this->queue->wait($row, (float) $request->integer('wait', 20)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkReaderToken($request);

        $row = TelegramReadRequest::query()->findOrFail($id);

        return $this->answer($this->queue->wait($row, (float) min(50, max(0, $request->integer('wait', 0)))));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(string $op): array
    {
        $common = [
            'account' => ['required', 'integer', 'min:1'],
            'wait' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];

        /* An id, "me" or an @username: an invite link would be joined. */
        $chat = ['string', 'max:70', 'regex:/^(-?\d{1,20}|me|@?[A-Za-z][A-Za-z0-9_]{2,63})$/'];

        $dates = [
            'min_date' => ['nullable', 'date'],
            'max_date' => ['nullable', 'date'],
        ];

        return $common + match ($op) {
            'dialogs' => [
                'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
                'kind' => ['nullable', Rule::in(['any', 'private', 'group', 'channel', 'bot'])],
                'q' => ['nullable', 'string', 'max:80'],
                'unread' => ['nullable', 'boolean'],
            ],
            'history' => [
                'chat' => ['required', ...$chat],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
                'before_id' => ['nullable', 'integer', 'min:1'],
                'order' => ['nullable', Rule::in(['asc', 'desc'])],
            ] + $dates,
            'search' => [
                'q' => ['nullable', 'string', 'max:200'],
                'chat' => ['nullable', ...$chat],
                'from' => ['nullable', ...$chat],
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'before_id' => ['nullable', 'integer', 'min:1'],
            ] + $dates,
            'members' => [
                'chat' => ['required', ...$chat],
                'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
                'offset' => ['nullable', 'integer', 'min:0'],
            ],
            'user' => [
                'user' => ['required', ...$chat],
            ],
            default => [
                'chat' => ['required', ...$chat],
            ],
        };
    }

    /**
     * The account that writes to this person: the owner of their role's
     * penalties (a listener's), Driver Check's own when no listener does.
     */
    private function accountFor(OperationUser $person): ?int
    {
        $key = app(DutyOwners::class)->penaltyOwner($person->roleOrDefault());

        if ($key === null) {
            $id = (int) config('services.telegram.driver_check_account_id');

            return $id > 0 ? $id : null;
        }

        $account = TelegramListener::query()->where('key', $key)->value('telegram_account_id');

        return $account === null ? null : (int) $account;
    }

    private function answer(TelegramReadRequest $row): JsonResponse
    {
        $head = [
            'request_id' => $row->id,
            'op' => $row->op,
            'account_id' => $row->telegram_account_id,
            'status' => $row->status,
        ];

        if ($row->status === TelegramReadRequest::STATUS_FAILED) {
            return $this->json($head + ['error' => $row->error], 502);
        }

        if ($row->status === TelegramReadRequest::STATUS_DONE) {
            if ($row->result === null) {
                return $this->json($head + ['error' => 'The answer is no longer kept; ask again.'], 410);
            }

            return $this->json($head + [
                'took_seconds' => $row->started_at && $row->finished_at ? $row->started_at->diffInSeconds($row->finished_at) : null,
            ] + $row->result);
        }

        return $this->json($head + ['next' => "/api/telegram/reader/requests/{$row->id}?wait=30"], 202);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
