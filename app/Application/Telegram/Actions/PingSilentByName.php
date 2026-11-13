<?php

declare(strict_types=1);

namespace App\Application\Telegram\Actions;

use App\Application\Telegram\Services\AutoReplyDelivery;
use App\Application\Telegram\Services\ClientCheckEscalation;
use App\Application\Telegram\Services\DutyOwners;
use App\Application\Telegram\Services\PersonalAnswers;
use App\Models\Telegram\OperationUser;
use App\Models\Telegram\TelegramClientCheck;
use App\Models\Telegram\TelegramSetting;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The call by name: a penalty nobody answered for `client_checks.name_ping.
 * after_minutes` gets the person's name alone - "Зуба", "Рушана апа",
 * "Укажоним?" - the way the owner wakes people up by hand (45% answered
 * within 10 minutes, 79% within the hour; the nudges get 15%).
 *
 * The name comes from the card: "Личные ответы" -> "Оклик по имени", else
 * how the card says to call them ("Ali aka"), with a "?" now and then. A
 * person with neither is never called: their full name from the CRM
 * ("HUSANOV ZUBAYR BOBIR O'G'LI") is no call.
 *
 * It must stay rare to keep working: once per penalty, at most
 * `max_per_day` a day per person and `gap_minutes` apart, in the penalties'
 * working hours, not after a nudge, not when they wrote anything since the
 * penalty, not when the card switches the private chat or the nudges off.
 *
 * Runs on the listener's cron, after the nudge.
 */
final class PingSilentByName
{
    /** How long after its comment a penalty may still be called about. */
    private const WINDOW_MINUTES = 180;

    public function __construct(
        private readonly ClientCheckEscalation $escalation,
        private readonly AutoReplyDelivery $delivery,
        private readonly PersonalAnswers $personal,
    ) {
    }

    public function execute(SimpleEventHandler $telegram, ?string $key = null): void
    {
        $config = (array) config('client_checks.name_ping', []);

        /* Only the roles this account writes to (DutyOwners). */
        $roles = app(DutyOwners::class)->penaltyRoles($key);

        if (
            ! ($config['enabled'] ?? false)
            || $roles === []
            || ! TelegramSetting::clientChecksEnabled()
            || ! $this->escalation->rules()->withinWorkingHours()
        ) {
            return;
        }

        $after = max(1, (int) ($config['after_minutes'] ?? 20));

        /* Each person's latest comment, unanswered, old enough and not too old. */
        $checks = TelegramClientCheck::query()
            ->forRoles($roles)
            ->with('operationUser')
            ->whereNotNull('operation_user_id')
            ->whereNotNull('comment')
            ->whereNotNull('peer')
            ->whereNull('replied_at')
            ->whereNull('nudged_at')
            ->whereNull('pinged_at')
            ->whereBetween('sent_at', [now()->subMinutes(self::WINDOW_MINUTES), now()->subMinutes($after)])
            ->whereNotExists(function (QueryBuilder $later): void {
                $later->selectRaw('1')
                    ->from('telegram_client_checks as later')
                    ->whereColumn('later.operation_user_id', 'telegram_client_checks.operation_user_id')
                    ->whereNotNull('later.comment')
                    ->whereColumn('later.sent_at', '>', 'telegram_client_checks.sent_at');
            })
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($checks as $check) {
            $person = $check->operationUser;

            if ($person instanceof OperationUser) {
                $this->consider($telegram, $check, $person, $config);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function consider(SimpleEventHandler $telegram, TelegramClientCheck $check, OperationUser $person, array $config): void
    {
        /* They wrote something since (a word not tied to the penalty), or may not be written to: never for this penalty. */
        $wrote = $person->last_private_message_at !== null && $check->sent_at !== null
            && $person->last_private_message_at->greaterThanOrEqualTo($check->sent_at);

        if ($wrote || ! $person->allows('nudge_enabled')) {
            $check->update(['pinged_at' => now()]);

            return;
        }

        $gap = max(1, (int) ($config['gap_minutes'] ?? 120));
        $mine = TelegramClientCheck::query()->where('operation_user_id', $person->id);

        $today = (clone $mine)->whereNotNull('ping_text')->where('pinged_at', '>=', now()->startOfDay())->count();
        $lastPing = (clone $mine)->whereNotNull('ping_text')->max('pinged_at');
        $lastNudge = (clone $mine)->max('nudged_at');

        /* Not now: the day's calls are used up, or one (or a nudge) went lately. It may still go later in the window. */
        if (
            $today >= max(1, (int) ($config['max_per_day'] ?? 2))
            || ($lastPing !== null && now()->subMinutes($gap)->lessThan($lastPing))
            || ($lastNudge !== null && now()->subMinutes($gap)->lessThan($lastNudge))
        ) {
            return;
        }

        $language = $person->messageLanguage();
        $names = $this->names($person, $language);

        /* Nobody wrote how to call them: never their full name. */
        if ($names === []) {
            $check->update(['pinged_at' => now()]);

            return;
        }

        /* Set first: whatever happens below, this penalty is not called about twice. */
        $check->update(['pinged_at' => now()]);

        $peer = (string) $check->peer;

        try {
            $text = $this->delivery->send(
                $telegram,
                preg_match('/^-?\d+$/', $peer) === 1 ? (int) $peer : $peer,
                ['language' => $language, 'items' => $names],
                $check,
                $person,
            );
        } catch (Throwable $e) {
            Log::warning('Client check call by name was not sent', [
                'check_id' => $check->id,
                'operation_user_id' => $person->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $check->update(['ping_text' => mb_substr($text, 0, 255)]);

        Log::info('Client check called by name', ['check_id' => $check->id, 'operation_user_id' => $person->id]);
    }

    /**
     * What to call them: their own "Оклик по имени" texts, else the card's
     * address with and without a "?".
     *
     * @return list<array{type: string, text: string, language: string}>
     */
    private function names(OperationUser $person, string $language): array
    {
        $own = array_values(array_filter(
            $this->personal->slot($person, PersonalAnswers::PING)['items'],
            static fn (array $item): bool => $item['type'] === PersonalAnswers::TYPE_TEXT,
        ));

        if ($own !== []) {
            return $own;
        }

        $address = $person->addressFor($language);

        if ($address === null) {
            return [];
        }

        return [
            ['type' => PersonalAnswers::TYPE_TEXT, 'text' => $address, 'language' => $language],
            ['type' => PersonalAnswers::TYPE_TEXT, 'text' => $address . '?', 'language' => $language],
        ];
    }
}
