<?php

//routes/console.php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::command('groups:check-pending')->everyMinute()->runInBackground();
Schedule::command('bans:sync')->everyMinute();

// Group members into the accounts' contacts, once a day. The time and the
// switch live in the panel (Contact sync -> Settings), so the scheduler asks
// every minute whether this one is the minute.
Schedule::command('telegram:sync-contacts --trigger=schedule')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\ContactSyncSettings::class)->dueNow())
    ->runInBackground()
    ->withoutOverlapping();

// The accounts invite their own contacts into the groups of the panel.
// The time, the batch and the daily cap live there; the scheduler only asks
// whether this minute is the one. Accounts already open in another process
// are left alone.
Schedule::command('telegram:group-invites --trigger=schedule')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\GroupInviteSettings::class)->dueNow())
    ->runInBackground()
    ->withoutOverlapping();

// The Join group watchdog: one worker per account marked in the panel, each
// finding the groups of our topic and joining them by itself. Asked every
// minute while it is on, so it is back by itself after a reboot or a crash
// (its lock turns a second copy away). Off until it is switched on in the
// panel (Join group -> Settings).
Schedule::command('telegram:group-join-watchdog')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\GroupJoinSettings::class)->enabled())
    ->runInBackground();
