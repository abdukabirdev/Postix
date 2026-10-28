<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\ContactSyncSettings;
use App\Http\Controllers\Controller;
use App\Models\Telegram\TelegramAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The contact sync's settings page: when it runs, how many contacts each
 * account may add a day, the pauses, the nick the contacts are saved under. Access is the `role:driverCheck,superadmin` middleware of the
 * route group.
 */
final class ContactSyncSettingsController extends Controller
{
    public function __construct(private readonly ContactSyncSettings $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->settings->current()));
    }

    public function update(Request $request): JsonResponse
    {
        [$limitMin, $limitMax] = ContactSyncSettings::LIMITS['daily_limit'];
        [$delayMin, $delayMax] = ContactSyncSettings::LIMITS['delay_min'];

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'schedule_at' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'daily_limit' => ['required', 'integer', "min:{$limitMin}", "max:{$limitMax}"],
            'skip_usernames' => ['required', 'boolean'],
            'delay_min' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}"],
            'delay_max' => ['required', 'integer', "min:{$delayMin}", "max:{$delayMax}", 'gte:delay_min'],
            'nick_base' => ['nullable', 'string', 'max:60'],
            'excluded_accounts' => ['nullable', 'array'],
            'excluded_accounts.*' => ['integer', 'exists:telegram_accounts,id'],
        ]);

        $data['nick_base'] = (string) ($data['nick_base'] ?? '');
        $data['excluded_accounts'] = $data['excluded_accounts'] ?? [];

        return response()->json($this->payload($this->settings->save($data)) + [
            'message' => __('driver_check.sync.messages.settings_saved'),
        ]);
    }

    /**
     * Back to .env and the defaults.
     */
    public function reset(): JsonResponse
    {
        return response()->json($this->payload($this->settings->reset()) + [
            'message' => __('driver_check.sync.messages.settings_reset'),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function payload(array $values): array
    {
        return [
            'data' => $values,
            'defaults' => $this->settings->defaults(),
            'saved' => $this->settings->isSaved(),
            'limits' => ContactSyncSettings::LIMITS,
            'listener_ids' => ContactSyncSettings::listenerIds(),
            'accounts' => TelegramAccount::query()
                ->where('is_authorized', true)
                ->orderBy('id')
                ->get(['id', 'phone', 'username'])
                ->map(fn (TelegramAccount $account) => [
                    'id' => $account->id,
                    'phone' => $account->phone,
                    'username' => $account->username,
                    'is_primary' => $account->isPrimary(),
                ])
                ->values()
                ->all(),
        ];
    }
}
