<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\DeveloperAlerts;
use App\Application\Telegram\Services\DutyOwners;
use App\Console\Commands\Telegram\TelegramDriverCheckCommand;
use App\Http\Controllers\Controller;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramAlert;
use App\Models\Telegram\TelegramFileRoute;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramScheduledMessage;
use App\Telegram\TelegramProcessLock;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The "How it works" page: every independent piece of logic of the Telegram
 * side, which account does it right now, and whether that account's process
 * is up; and the problems told to the developer lately. Read only - the
 * accounts are changed on the Listeners, Files and Scheduled messages pages.
 */
final class SystemController extends Controller
{
    private const RECENT_ALERTS = 20;

    public function __construct(private readonly DutyOwners $owners) {}

    public function index(): JsonResponse
    {
        try {
            $listeners = TelegramListener::query()->with('account')->orderBy('name')->get();
        } catch (Throwable) {
            /* The table is not there yet (migration pending): the page shows Driver Check alone. */
            $listeners = new \Illuminate\Database\Eloquent\Collection();
        }

        $names = ['' => 'Driver Check'];
        $running = ['' => TelegramProcessLock::isHeld(TelegramDriverCheckCommand::LOCK_NAME)];

        foreach ($listeners as $listener) {
            $names[$listener->key] = $listener->name;
            $running[$listener->key] = TelegramProcessLock::isHeld($listener->lockName());
        }

        $account = fn (?string $key): array => [
            'key' => $key,
            'name' => $names[$key ?? ''] ?? (string) $key,
            'running' => $running[$key ?? ''] ?? false,
        ];

        $logics = [
            /* What stays with Driver Check whoever else works. */
            ['key' => 'driver_checks', 'movable' => false, 'accounts' => [$account(null)], 'link' => '/driver-check/chats'],

            ['key' => 'penalty_intake', 'movable' => true, 'accounts' => [
                $account($this->owners->owner(TelegramListener::DUTY_PENALTY_INTAKE)),
            ], 'link' => '/driver-check/listeners'],

            /* One logic per role: the delivery, the comment, the nudge and the answers to their replies. */
            ...array_map(fn (string $role): array => [
                'key' => 'penalties_' . $role,
                'movable' => true,
                'accounts' => [$account($this->owners->penaltyOwner($role))],
                'link' => '/driver-check/listeners',
            ], [OperationUser::ROLE_SALES, OperationUser::ROLE_OPERATION]),

            ['key' => 'file_routes', 'movable' => true, 'accounts' => $this->fileAccounts($account), 'link' => '/driver-check/file-routes'],

            ['key' => 'scheduled_messages', 'movable' => true, 'accounts' => $this->scheduledAccounts($account), 'link' => '/driver-check/scheduled-messages'],

            /* Their own accounts, not the listeners': a pool the panel manages. */
            ['key' => 'contact_sync', 'movable' => false, 'accounts' => [], 'link' => '/driver-check/contact-report'],
            ['key' => 'phone_resolver', 'movable' => false, 'accounts' => [], 'link' => '/driver-check/sessions'],
            ['key' => 'group_join', 'movable' => false, 'accounts' => [], 'link' => '/driver-check/group-join'],
        ];

        return response()->json([
            'logics' => $logics,
            'listeners' => $listeners->map(fn (TelegramListener $l): array => [
                'key' => $l->key,
                'name' => $l->name,
                'account' => $l->account?->phone,
                'is_enabled' => (bool) $l->is_enabled,
                'running' => $running[$l->key] ?? false,
            ])->all(),
            'alerts' => [
                'configured' => app(DeveloperAlerts::class)->configured(),
                'recent' => $this->recentAlerts(),
            ],
        ]);
    }

    /**
     * The accounts the kinds of files go out from, with how many kinds each.
     *
     * @param callable(?string): array<string, mixed> $account
     *
     * @return list<array<string, mixed>>
     */
    private function fileAccounts(callable $account): array
    {
        $counts = [];

        foreach (TelegramFileRoute::query()->where('is_active', true)->get() as $route) {
            $key = $this->owners->fileOwner($route->listener_key) ?? '';

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts === []) {
            $counts[$this->owners->owner(TelegramListener::DUTY_FILE_ROUTES) ?? ''] = 0;
        }

        return array_map(
            fn (string $key, int $count): array => $account($key === '' ? null : $key) + ['count' => $count],
            array_map('strval', array_keys($counts)),
            array_values($counts),
        );
    }

    /**
     * The accounts the active scheduled messages are written from.
     *
     * @param callable(?string): array<string, mixed> $account
     *
     * @return list<array<string, mixed>>
     */
    private function scheduledAccounts(callable $account): array
    {
        $counts = [];

        try {
            foreach (TelegramScheduledMessage::query()->where('is_active', true)->get(['listener_key']) as $message) {
                $key = (string) ($message->listener_key ?? '');

                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        } catch (Throwable) {
            /* The table is not there yet (migration pending). */
        }

        if ($counts === []) {
            $counts[''] = 0;
        }

        return array_map(
            fn (string $key, int $count): array => $account($key === '' ? null : $key) + ['count' => $count],
            array_map('strval', array_keys($counts)),
            array_values($counts),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentAlerts(): array
    {
        try {
            return TelegramAlert::query()
                ->orderByDesc('last_seen_at')
                ->limit(self::RECENT_ALERTS)
                ->get()
                ->map(fn (TelegramAlert $alert): array => [
                    'id' => $alert->id,
                    'kind' => $alert->kind,
                    'title' => $alert->title,
                    'details' => $alert->details,
                    'listener_key' => $alert->listener_key,
                    'occurrences' => $alert->occurrences,
                    'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
                    'notified' => $alert->notified_at !== null,
                    'notify_error' => $alert->notify_error,
                ])
                ->all();
        } catch (Throwable) {
            /* The table is not there yet (migration pending). */
            return [];
        }
    }
}
