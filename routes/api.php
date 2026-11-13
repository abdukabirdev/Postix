<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Telegram\AccountsReportController;
use App\Http\Controllers\Api\Telegram\ChatReaderController;
use App\Http\Controllers\Api\Telegram\ContactSyncDevReportController;
use App\Http\Controllers\Api\Telegram\GroupInviteReportController;
use App\Http\Controllers\Api\Telegram\SilentPeopleReportController;
use App\Http\Controllers\Api\Telegram\TelegramAccountController;
use App\Http\Controllers\Api\Telegram\TelegramBotWebhookController;
use App\Http\Controllers\Api\Telegram\TelegramDriverCheckController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/test', [TestController::class, 'test']);

// Auth
Route::post('/login', [AuthController::class, 'login']);

// Driver check bot: button presses (see telegram:bot-webhook)
Route::post('/telegram/webhook', TelegramBotWebhookController::class)
    ->name('telegram.bot.webhook');

// The group invites' report for the developer: TELEGRAM_REPORT_TOKEN as a bearer token, read only.
Route::get('/telegram/report/group-invites', GroupInviteReportController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.report.group-invites');

// Where every account stands (which process holds it, why the invites pass it by) and the drivers into a group, the same token.
Route::get('/telegram/report/accounts', AccountsReportController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.report.accounts');

// Who never answers the penalties (no word, no reaction), the same token.
Route::get('/telegram/report/silent-people', SilentPeopleReportController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.report.silent-people');

// The contact sync's report for the developer, the same token.
Route::get('/telegram/report/contact-sync', ContactSyncDevReportController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.report.contact-sync');

// The developer's read-only window into Telegram (chats, messages, people), TELEGRAM_READER_TOKEN: see ChatReaderController.
Route::prefix('telegram/reader')->middleware('throttle:60,1')->group(function () {
    Route::get('/accounts', [ChatReaderController::class, 'accounts'])->name('telegram.reader.accounts');
    Route::get('/people', [ChatReaderController::class, 'people'])->name('telegram.reader.people');
    Route::get('/people/{id}/history', [ChatReaderController::class, 'personHistory'])->whereNumber('id')->name('telegram.reader.person');
    Route::get('/requests/{id}', [ChatReaderController::class, 'show'])->whereNumber('id')->name('telegram.reader.request');
    Route::get('/{op}', [ChatReaderController::class, 'read'])->where('op', 'dialogs|history|search|chat|members|user')->name('telegram.reader.read');
});

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::prefix('telegram/accounts')->group(function () {
        Route::get('/', [TelegramAccountController::class, 'index']);
        Route::post('/auth', [TelegramAccountController::class, 'auth']);
        Route::post('/verify-code', [TelegramAccountController::class, 'verifyCode']);
        Route::post('/password', [TelegramAccountController::class, 'password']);
        Route::post('/logout', [TelegramAccountController::class, 'logout']);
        Route::get('/{id}', [TelegramAccountController::class, 'show']);
        Route::post('/manage-failures', [TelegramAccountController::class, 'manageFailures']);
    });
    Route::post(
        '/admin/telegram/driver-check/start',
        [TelegramDriverCheckController::class, 'start']
    )->name('admin.telegram.driver-check.start');

});
