<?php

use App\Application\Telegram\Services\DutyOwners;
use App\Models\Telegram\TelegramFileRoute;
use App\Models\Telegram\TelegramListener;
use App\Models\Telegram\TelegramScheduledMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Two extra messages every working day (Mon-Fri) at 10:00 and 16:00 into
 * the group -5243539111, written by the account that forwards the files.
 *
 * Created empty: the text is pasted in on the Scheduled messages page, and
 * until then they never go out. Time, days, groups, account and the switch
 * are all changed there as well; more slots are added with "Add message".
 */
return new class extends Migration
{
    private const CHAT_ID = -5243539111;

    private const SLOTS = [
        'Доп. сообщение 10:00' => '10:00',
        'Доп. сообщение 16:00' => '16:00',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('telegram_scheduled_messages')) {
            return;
        }

        $sender = $this->fileSender();

        foreach (self::SLOTS as $name => $time) {
            TelegramScheduledMessage::query()->firstOrCreate(['name' => $name], [
                'text' => '',
                'send_at' => $time,
                'days' => [1, 2, 3, 4, 5],
                'chat_ids' => [self::CHAT_ID],
                'listener_key' => $sender,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_scheduled_messages')) {
            return;
        }

        /* Only the slots still waiting for their text; a filled one is someone's work. */
        TelegramScheduledMessage::query()
            ->whereIn('name', array_keys(self::SLOTS))
            ->where('text', '')
            ->delete();
    }

    /**
     * The account the files go out from (a listener key, null for Driver
     * Check): the one of a route into this group, else the one most routes
     * use, else the general owner of the files ("Who does what").
     */
    private function fileSender(): ?string
    {
        try {
            $owners = app(DutyOwners::class);

            $routes = TelegramFileRoute::query()->where('is_active', true)->orderBy('sort')->get();

            $route = $routes->first(
                fn (TelegramFileRoute $r): bool => in_array(self::CHAT_ID, $r->targets(), true),
            );

            if ($route !== null) {
                return $owners->fileOwner($route->listener_key);
            }

            if ($routes->isNotEmpty()) {
                $key = $routes
                    ->countBy(fn (TelegramFileRoute $r): string => $owners->fileOwner($r->listener_key) ?? '')
                    ->sortDesc()
                    ->keys()
                    ->first();

                return $key === '' || $key === null ? null : (string) $key;
            }

            return $owners->owner(TelegramListener::DUTY_FILE_ROUTES);
        } catch (Throwable) {
            return null;
        }
    }
};
