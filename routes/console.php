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
    /* An hour, not the default day: the command's own lock keeps one run at a time, and a mutex left by a reboot would skip the next day's run. */
    ->withoutOverlapping(60);

// The accounts invite their own contacts into the groups of the panel.
// The time, the batch and the daily cap live there; the scheduler only asks
// whether this minute is the one. Accounts already open in another process
// are left alone.
Schedule::command('telegram:group-invites --trigger=schedule')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\GroupInviteSettings::class)->dueNow())
    ->runInBackground()
    ->withoutOverlapping();

// Drivers into the group picked in the panel (Group invites -> Drivers into
// a group). Every minute the drivers checked meanwhile go into the queue,
// and a run starts when an account may add now; the command's own lock
// keeps one run at a time, and asks quietly when there is nothing to do.
Schedule::command('telegram:driver-group-add --trigger=schedule')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\DriverGroupSettings::class)->active())
    ->runInBackground();

// Our bot approves the requests to join our groups and writes who asked
// into the base. Each start asks Telegram for under a minute and ends; the
// token and the switch live in the panel (Group invites -> Join bot).
Schedule::command('telegram:join-requests')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\JoinBotSettings::class)->enabled())
    ->runInBackground()
    ->withoutOverlapping(5);

// The Join group watchdog: one worker per account marked in the panel, each
// finding the groups of our topic and joining them by itself. Asked every
// minute while it is on, so it is back by itself after a reboot or a crash
// (its lock turns a second copy away). Off until it is switched on in the
// panel (Join group -> Settings).
Schedule::command('telegram:group-join-watchdog')
    ->everyMinute()
    ->when(fn () => app(\App\Application\Telegram\Services\GroupJoinSettings::class)->enabled())
    ->runInBackground();
