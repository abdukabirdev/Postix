<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Telegram\ContactSyncDevReportController;
use App\Http\Controllers\Api\Telegram\GroupInviteReportController;
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

// The contact sync's report for the developer, the same token.
Route::get('/telegram/report/contact-sync', ContactSyncDevReportController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.report.contact-sync');

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
