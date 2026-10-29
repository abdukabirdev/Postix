<?php

use App\Http\Controllers\Api\Telegram\ClientCheckController;
use App\Http\Controllers\Api\Telegram\ContactAccountsController;
use App\Http\Controllers\Api\Telegram\ContactCatalogController;
use App\Http\Controllers\Api\Telegram\ContactSyncController;
use App\Http\Controllers\Api\Telegram\ContactSyncReportController;
use App\Http\Controllers\Api\Telegram\ContactSyncRunController;
use App\Http\Controllers\Api\Telegram\ContactSyncSettingsController;
use App\Http\Controllers\Api\Telegram\GroupInviteController;
use App\Http\Controllers\Api\Telegram\GroupJoinController;
use App\Http\Controllers\Api\Telegram\GroupJoinSettingsController;
use App\Http\Controllers\Api\Telegram\AutoReplyController;
use App\Http\Controllers\Api\Telegram\ClientCheckRulesController;
use App\Http\Controllers\Api\Telegram\DriverCheckChatController;
use App\Http\Controllers\Api\Telegram\DriverCheckExportController;
use App\Http\Controllers\Api\Telegram\DriverCheckQueueController;
use App\Http\Controllers\Api\Telegram\DriverCheckWatchdogController;
use App\Http\Controllers\Api\Telegram\FileRouteController;
use App\Http\Controllers\Api\Telegram\ListenerController;
use App\Http\Controllers\Api\Telegram\ListenerProcessController;
use App\Http\Controllers\Api\Telegram\SystemController;
use App\Http\Controllers\Api\Telegram\ScheduledMessageController;
use App\Http\Controllers\Api\Telegram\OperationUserController;
use App\Http\Controllers\Api\Telegram\PersonalAnswerController;
use App\Http\Controllers\Api\Telegram\OperatorController;
use App\Http\Controllers\Api\Telegram\ResolvedPhoneController;
use App\Http\Controllers\Api\Telegram\TelegramDriverController;
use App\Http\Controllers\Api\Telegram\TelegramSessionController;
use App\Http\Controllers\ExternalApi\WareHouseController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\View\Admin\CatalogController as AdminCatalogController;
use App\Http\Controllers\View\Admin\SuperAdminUserController;
use App\Http\Controllers\View\Admin\UserController as AdminUserController;
use App\Http\Controllers\View\AuthController;
use App\Http\Controllers\View\BanController;
use App\Http\Controllers\View\CatalogController;
use App\Http\Controllers\View\Department\DepartmentController;
use App\Http\Controllers\View\Department\UserDepartmenIndexController;
use App\Http\Controllers\View\Department\UserDepartmentController;
use App\Http\Controllers\View\DriverCheck\DriverCheckController;
use App\Http\Controllers\View\DriverCheck\DriverCheckProfileController;
use App\Http\Controllers\View\MessageGroupController;
use App\Http\Controllers\View\Messages\ShowController;
use App\Http\Controllers\View\ProfileController;
use App\Http\Controllers\View\TelegramController;
use App\Http\Controllers\View\UserController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/methods/web.php';

Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::post('/login', [AuthController::class, 'authenticate']);

Route::middleware(['auth', 'check.ban'])->group(function () {

    Route::middleware('role:superadmin')->group(function () {
        Route::get('/export', [WareHouseController::class, 'checkedQozoqPage'])->name('warehouse.checkedQozoqPage')->middleware('permission:nav:export'); // export
        Route::get('/checked-qozoq/export', [WareHouseController::class, 'chekedQozoqExport'])->name('warehouse.chekedQozoqExport');
        Route::get('/import', [WareHouseController::class, 'importQozoqPage'])->name('warehouse.importQozoqPage')->middleware('permission:nav:import');
        Route::get('/import-qozoq/export', [WareHouseController::class, 'importQozoqExport'])->name('warehouse.importQozoqExport');
        Route::get('/turkey', [WareHouseController::class, 'turkey'])->name('warehouse.turkey')->middleware('permission:nav:turkey');

    });

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DepartmentController::class, 'index'])->name('departments.index');

    Route::middleware('role:superadmin')->group(function () {
        Route::get('departments/deleted', [DepartmentController::class, 'deleted'])->name('departments.deleted');
        Route::get('/free', [DepartmentController::class, 'free'])->name('departments.free');
        Route::get('/free-banned', [DepartmentController::class, 'freeBanned'])->name('departments.free-banned');

        // Route::get('/free-users', [DepartmentController::class, 'freeUsers'])->name('departments.free-users');
        // Route::get('/free-users-banned', [DepartmentController::class, 'freeUsersBanned'])->name('departments.free-users-banned');

        // Route::get('/pro-users', [DepartmentController::class, 'proUsers'])->name('departments.pro-users');
        Route::get('/pro-users', [UserDepartmenIndexController::class, 'proUsers'])->name('departments.pro-users');

        Route::get('departments/create', [DepartmentController::class, 'create'])->name('departments.create');

        Route::get('/free-users', [UserDepartmenIndexController::class, 'freeUsers'])->name('departments.free-users');
        Route::get('/free-users-banned', [UserDepartmenIndexController::class, 'freeUsersBanned'])->name('departments.free-users-banned');

        Route::get('user-departments/create', [UserDepartmentController::class, 'create'])->name('user.departments.create');
        Route::post('user-departments/store', [UserDepartmentController::class, 'store'])->name('user.departments.store');

        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::post('/departments/{department}/upgrade', [DepartmentController::class, 'upgrade'])->name('departments.upgrade');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::put('/departments/restore/{department}', [DepartmentController::class, 'update'])->name('departments.restore');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])->name('departments.destroy');
        Route::get('/logs/{type}', [LogController::class, 'index'])->name('logs.index')->middleware('permission:nav:logs');
        Route::get('/logs/show/{audit}', [LogController::class, 'show'])->name('logs.show')->middleware('permission:nav:logs');
    });
    Route::get('/departments/{department}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::get('/departments/{id}/history', [DepartmentController::class, 'history'])->name('departments.history');
    Route::get('/departments/{id}/users', [DepartmentController::class, 'users'])->name('departments.users');
    Route::get('/departments/{department}/pending', [MessageGroupController::class, 'getPending'])->name('departments.getPending');
    Route::get('/departments/{id}/send-messages', [MessageGroupController::class, 'sendForm'])->name('telegram.send-form');
    Route::post('/send-messages', [MessageGroupController::class, 'sendMassMessage'])->name('telegram.send-messages');
    Route::put('/message-groups/{id}/update', [MessageGroupController::class, 'update'])->name('message-groups.update');

    // Route::get('/operations/{MessageGroup}', [MessageGroupController::class, 'show'])->name('operations.show');

    Route::get('/operations/{messageGroup}', [ShowController::class, 'show'])->name('operations.show');
    Route::get('/operations/{messageGroup}/peers', [ShowController::class, 'peers'])->name('operations.peers');
    Route::get('/operations/{messageGroup}/peer-messages', [ShowController::class, 'peerMessages'])->name('operations.peer-messages');
    Route::post('/catalogs/remove-peer', [ShowController::class, 'removePeer'])->name('admin.catalogs.remove-peer');
    Route::post('/catalogs/remove-peers', [ShowController::class, 'removePeers'])->name('admin.catalogs.remove-peers');

    Route::get('/departments/{department}/catalogs', [CatalogController::class, 'index'])->name('catalogs.index');
    Route::post('/departments/{department}/catalogs', [CatalogController::class, 'store'])->name('catalogs.store');
    Route::get('/departments/{department}/catalogs/{catalog}', [CatalogController::class, 'show'])->name('catalogs.show');
    Route::put('/departments/{department}/catalogs/{catalog}', [CatalogController::class, 'update'])->name('catalogs.update');
    Route::delete('/departments/{department}/catalogs/{catalog}', [CatalogController::class, 'destroy'])->name('catalogs.destroy');
    Route::middleware('role:admin,user')->group(function () {
        Route::get('/new-telegram-users', [AdminUserController::class, 'newTelegramUsers'])->name('user.telegram.new-users');
        Route::get('/users', [DepartmentController::class, 'users'])->name('departments.users.global');
        Route::get('/catalogs', [CatalogController::class, 'index'])->name('catalogs.index.global');
        Route::get('/send-messages', [MessageGroupController::class, 'sendForm'])->name('telegram.send-form.global');
        Route::get('/history', [DepartmentController::class, 'history'])->name('departments.history.global');
    });
    Route::get('/profile/{user}', [ProfileController::class, 'profile'])->name('users.profile');
    Route::post('/profile/{user}', [ProfileController::class, 'update'])->name('users.profile.update');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::get('/departments/{department}/new-telegram-users', [AdminUserController::class, 'newTelegramUsers'])->name('admin.telegram.new-users');
    Route::post('/admin/telegram/create-user/{department}', [AdminUserController::class, 'createTelegramUser'])->name('admin.telegram.create_user');
    Route::get('phones/', [TelegramController::class, 'showLoginForm'])->name('telegram.login');
    Route::post('phones/send', [TelegramController::class, 'sendPhone'])->name('telegram.sendPhone');
    Route::post('phones/verify', [TelegramController::class, 'sendCode'])->name('telegram.sendCode');
    Route::post('tg/password', [TelegramController::class, 'completeLogin'])->name('telegram.password');
    Route::post('/telegram/logout', [TelegramController::class, 'logout'])->name('telegram.logout');
    Route::post('tg/status', [TelegramController::class, 'status'])->name('telegram.status');
    Route::post('/message-groups/{group}/cancel', [TelegramController::class, 'cancel'])->name('message-groups.cancel');
    Route::middleware('role:superadmin,admin')->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/admin/ban-unban', [BanController::class, 'banUnban'])->name('admin.ban-unban');
    });
    Route::prefix('/admin')->group(function () {
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::post('/users/{user}/destroy', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        Route::post('/telegram/phone', [AdminUserController::class, 'sendPhone'])->name('admin.telegram.send');
        Route::post('/telegram/login', [AdminUserController::class, 'storeUserWithTelegram'])->name('admin.telegram.login');
        Route::post('/telegram/user-logout', [AdminUserController::class, 'logoutUserFromTelegram'])->name('admin.telegram.user-logout');
    });
    Route::prefix('superadmin')->middleware(['role:superadmin', 'permission:nav:users'])->group(function () {
        Route::get('/', [SuperAdminUserController::class, 'index'])->name('superadmin.index');
        Route::post('/', [SuperAdminUserController::class, 'store'])->name('superadmin.store');
        Route::get('/{user}', [SuperAdminUserController::class, 'show'])->name('superadmin.show');
        Route::put('/{user}', [SuperAdminUserController::class, 'update'])->name('superadmin.update');
        Route::delete('/{user}', [SuperAdminUserController::class, 'destroy'])->name('superadmin.destroy');
    });

});
Route::prefix('admin')->middleware(['auth', 'permission:nav:catalogs'])->group(function () {
    Route::get('/catalogs', [AdminCatalogController::class, 'index'])->name('admin.catalogs.index');
    Route::post('/catalogs', [AdminCatalogController::class, 'store'])->name('admin.catalogs.store');
    Route::get('/catalogs/{catalog}', [AdminCatalogController::class, 'show'])->name('admin.catalogs.show');
    Route::put('/catalogs/{catalog}', [AdminCatalogController::class, 'update'])->name('admin.catalogs.update');
    Route::delete('/catalogs/{catalog}', [AdminCatalogController::class, 'destroy'])->name('admin.catalogs.destroy');
});

Route::middleware(['auth', 'role:driverCheck,superadmin'])
    ->prefix('driver-check')
    ->group(function () {

        /*
         * /driver-check
         * Redirect to the first page.
         */
        Route::get('/', function () {
            return redirect()->route(
                'driver-check.operation-users'
            );
        })->name('driver-check.dashboard');

        /*
         * The signed-in person's own profile.
         */
        Route::get('/profile', [DriverCheckProfileController::class, 'show'])->name('driver-check.profile');
        Route::post('/profile', [DriverCheckProfileController::class, 'update'])->name('driver-check.profile.update');
        Route::post('/profile/password', [DriverCheckProfileController::class, 'password'])->name('driver-check.profile.password');

        /*
         * Operation Users
         */
        Route::get(
            '/operation-users',
            [DriverCheckController::class, 'operationUsers']
        )->name('driver-check.operation-users');

        Route::get(
            '/operation-users/{operationUser}',
            [DriverCheckController::class, 'operationUser']
        )->name('driver-check.operation-user');

        /*
         * Operators
         *
         * Management page: the Telegram contact of every operator is entered
         * by hand here, which is what makes the private-chat copy of a report
         * deliverable at all.
         */
        Route::get(
            '/operators',
            [DriverCheckController::class, 'operators']
        )->name('driver-check.operators');

        /*
         * Sales managers: the same people page, the other role. They come
         * from the CRM penalties, never from the driver-check groups.
         */
        Route::get(
            '/sales',
            [DriverCheckController::class, 'sales']
        )->name('driver-check.sales');

        /*
         * Everyone at once: operators and sales managers in one list.
         */
        Route::get(
            '/people',
            [DriverCheckController::class, 'people']
        )->name('driver-check.people');

        /*
         * CRM penalties (client checks): what was forwarded to whom, and
         * whether it arrived.
         */
        Route::get(
            '/penalties',
            [DriverCheckController::class, 'penalties']
        )->name('driver-check.penalties');

        Route::get(
            '/penalties/settings',
            [DriverCheckController::class, 'penaltySettings']
        )->name('driver-check.penalties.settings');

        /*
         * Auto replies: what an operator's or a sales manager's private
         * message is answered with. One JSON file, edited here.
         */
        Route::get(
            '/auto-replies',
            [DriverCheckController::class, 'autoReplies']
        )->name('driver-check.auto-replies');

        /*
         * Personal answers: a person's own voice messages, GIFs and texts
         * for a penalty level, an auto reply kind, a greeting.
         */
        Route::get(
            '/personal-answers',
            [DriverCheckController::class, 'personalAnswers']
        )->name('driver-check.personal-answers');

        /*
         * Watched chats
         *
         * The groups the listener follows. Editable here so a new group can
         * be added without touching .env or restarting the listener.
         */
        Route::get(
            '/chats',
            [DriverCheckController::class, 'chats']
        )->name('driver-check.chats');

        /*
         * File routes: the notifications bot's morning files, forwarded
         * to the groups their kind is set to.
         */
        Route::get(
            '/file-routes',
            [DriverCheckController::class, 'fileRoutes']
        )->name('driver-check.file-routes');

        /*
         * Scheduled messages: what the listener's account writes into
         * groups, and when.
         */
        Route::get(
            '/scheduled-messages',
            [DriverCheckController::class, 'scheduledMessages']
        )->name('driver-check.scheduled-messages');

        /*
         * Listeners: the extra accounts running their own event loop.
         */
        Route::get(
            '/listeners',
            [DriverCheckController::class, 'listeners']
        )->name('driver-check.listeners');

        /*
         * How it works: every independent logic, who does it now, the problems.
         */
        Route::get(
            '/system',
            [DriverCheckController::class, 'system']
        )->name('driver-check.system');

        /*
         * Monitoring: the listener under its watchdog, and the queue
         * every background step of the panel goes through.
         */
        Route::get(
            '/watchdog',
            [DriverCheckController::class, 'watchdog']
        )->name('driver-check.watchdog');

        Route::get(
            '/queue',
            [DriverCheckController::class, 'queue']
        )->name('driver-check.queue');

        /*
         * Contact sync: the groups, the accounts' contacts and the log.
         */
        Route::get(
            '/contact-sync',
            [DriverCheckController::class, 'contactSync']
        )->name('driver-check.contact-sync');

        /*
         * The catalog of everyone the sync found, and its daily report.
         */
        Route::get(
            '/contacts',
            [DriverCheckController::class, 'contacts']
        )->name('driver-check.contacts');

        Route::get(
            '/contact-report',
            [DriverCheckController::class, 'contactReport']
        )->name('driver-check.contact-report');

        Route::get(
            '/contact-settings',
            [DriverCheckController::class, 'contactSettings']
        )->name('driver-check.contact-settings');

        Route::get(
            '/contact-accounts',
            [DriverCheckController::class, 'contactAccounts']
        )->name('driver-check.contact-accounts');

        /*
         * Group invites: the accounts add their own contacts into a chosen
         * group. Separate from the contact sync and from Join group.
         */
        Route::get(
            '/group-invites',
            [DriverCheckController::class, 'groupInvites']
        )->name('driver-check.group-invites');

        /*
         * Join group: the marked accounts find groups and join them by
         * themselves. The status, the joined groups, the ones waiting,
         * the people read from them, the settings.
         */
        Route::get(
            '/group-join',
            [DriverCheckController::class, 'groupJoin']
        )->name('driver-check.group-join');

        Route::get(
            '/group-join/joined',
            [DriverCheckController::class, 'groupJoinJoined']
        )->name('driver-check.group-join.joined');

        Route::get(
            '/group-join/queue',
            [DriverCheckController::class, 'groupJoinQueue']
        )->name('driver-check.group-join.queue');

        Route::get(
            '/group-join/people',
            [DriverCheckController::class, 'groupJoinPeople']
        )->name('driver-check.group-join.people');

        Route::get(
            '/group-join/settings',
            [DriverCheckController::class, 'groupJoinSettings']
        )->name('driver-check.group-join.settings');

        /*
         * Telegram sessions
         *
         * Every MadelineProto account the listener and the resolver pool
         * run on: log one in (code, then 2FA), check it, log it out.
         */
        Route::get(
            '/sessions',
            [DriverCheckController::class, 'sessions']
        )->name('driver-check.sessions');

        /*
         * Drivers
         */
        Route::get(
            '/drivers',
            [DriverCheckController::class, 'drivers']
        )->name('driver-check.drivers');

        /*
         * Resolved Phones
         */
        Route::get(
            '/resolved-phones',
            [DriverCheckController::class, 'resolvedPhones']
        )->name('driver-check.resolved-phones');

        /*
         * Excel export.
         *
         * Session-authenticated (not the sanctum API) because these
         * links are opened directly from the browser (plain <a href>
         * downloads), not called via fetch() with a bearer token.
         */
        Route::get(
            '/export/operators',
            [DriverCheckExportController::class, 'operators']
        )->name('driver-check.export.operators');

        Route::get(
            '/export/details',
            [DriverCheckExportController::class, 'details']
        )->name('driver-check.export.details');
    });

/*
|--------------------------------------------------------------------------
| Driver Check Data API
|--------------------------------------------------------------------------
|
| Pages fetch data from these endpoints.
|
*/

Route::middleware(['auth', 'role:driverCheck,superadmin'])
    ->prefix('api/telegram')
    ->group(function () {

        /*
         * Operation Users
         */
        Route::get(
            '/operation-users',
            [OperationUserController::class, 'index']
        )->name('api.telegram.operation-users');

        Route::get(
            '/operation-users/{operationUser}',
            [OperationUserController::class, 'show']
        )->name('api.telegram.operation-users.show');
        Route::get(
            '/operation-users/{operationUser}/drivers',
            [OperationUserController::class, 'drivers']
        )->name('api.telegram.operation-users.drivers');

        /*
         * Operators (management CRUD)
         */
        Route::get(
            '/operators',
            [OperatorController::class, 'index']
        )->name('api.telegram.operators.index');

        Route::post(
            '/operators',
            [OperatorController::class, 'store']
        )->name('api.telegram.operators.store');

        Route::put(
            '/operators/{operationUser}',
            [OperatorController::class, 'update']
        )->name('api.telegram.operators.update');

        Route::delete(
            '/operators/{operationUser}',
            [OperatorController::class, 'destroy']
        )->name('api.telegram.operators.destroy');

        /*
         * CRM penalties (read only)
         */
        Route::get(
            '/client-checks',
            [ClientCheckController::class, 'index']
        )->name('api.telegram.client-checks.index');

        /*
         * The on/off switches of the penalty flow
         */
        Route::get(
            '/client-checks/settings',
            [ClientCheckController::class, 'settings']
        )->name('api.telegram.client-checks.settings');

        Route::put(
            '/client-checks/settings',
            [ClientCheckController::class, 'updateSettings']
        )->name('api.telegram.client-checks.settings.update');

        /*
         * Levels, conditions, phrases and timings of the penalty flow
         */
        Route::get(
            '/client-checks/rules',
            [ClientCheckRulesController::class, 'show']
        )->name('api.telegram.client-checks.rules');

        Route::put(
            '/client-checks/rules',
            [ClientCheckRulesController::class, 'update']
        )->name('api.telegram.client-checks.rules.update');

        Route::delete(
            '/client-checks/rules',
            [ClientCheckRulesController::class, 'destroy']
        )->name('api.telegram.client-checks.rules.reset');

        /*
         * The auto replies file
         */
        Route::get(
            '/auto-replies',
            [AutoReplyController::class, 'show']
        )->name('api.telegram.auto-replies');

        Route::put(
            '/auto-replies',
            [AutoReplyController::class, 'update']
        )->name('api.telegram.auto-replies.update');

        Route::delete(
            '/auto-replies',
            [AutoReplyController::class, 'destroy']
        )->name('api.telegram.auto-replies.reset');

        Route::get(
            '/auto-replies/download',
            [AutoReplyController::class, 'download']
        )->name('api.telegram.auto-replies.download');

        Route::post(
            '/auto-replies/media',
            [AutoReplyController::class, 'upload']
        )->name('api.telegram.auto-replies.media.upload');

        Route::get(
            '/auto-replies/media/{file}',
            [AutoReplyController::class, 'media']
        )->where('file', '[a-f0-9]{24}\.[a-z0-9]+')->name('api.telegram.auto-replies.media');

        /*
         * GIFs out of Telegram: the listener searches, the panel waits
         */
        Route::post(
            '/auto-replies/telegram-gifs',
            [AutoReplyController::class, 'searchTelegramGifs']
        )->name('api.telegram.auto-replies.telegram-gifs.search');

        Route::get(
            '/auto-replies/telegram-gifs/preview/{file}',
            [AutoReplyController::class, 'telegramGifPreview']
        )->where('file', '-?[0-9]{1,20}\.(mp4|gif)')->name('api.telegram.auto-replies.telegram-gifs.preview');

        Route::get(
            '/auto-replies/telegram-gifs/{id}',
            [AutoReplyController::class, 'telegramGifs']
        )->where('id', '[a-f0-9]{24}')->name('api.telegram.auto-replies.telegram-gifs');

        Route::post(
            '/auto-replies/telegram-gifs/{id}/pick',
            [AutoReplyController::class, 'pickTelegramGif']
        )->where('id', '[a-f0-9]{24}')->name('api.telegram.auto-replies.telegram-gifs.pick');

        /*
         * Personal answers, one person at a time
         */
        Route::get(
            '/personal-answers',
            [PersonalAnswerController::class, 'index']
        )->name('api.telegram.personal-answers');

        Route::get(
            '/personal-answers/{operationUser}',
            [PersonalAnswerController::class, 'show']
        )->name('api.telegram.personal-answers.show');

        Route::put(
            '/personal-answers/{operationUser}',
            [PersonalAnswerController::class, 'update']
        )->name('api.telegram.personal-answers.update');

        /*
         * Watched chats (management CRUD)
         */
        Route::get(
            '/chats',
            [DriverCheckChatController::class, 'index']
        )->name('api.telegram.chats.index');

        Route::post(
            '/chats',
            [DriverCheckChatController::class, 'store']
        )->name('api.telegram.chats.store');

        Route::put(
            '/chats/{chat}',
            [DriverCheckChatController::class, 'update']
        )->name('api.telegram.chats.update');

        Route::delete(
            '/chats/{chat}',
            [DriverCheckChatController::class, 'destroy']
        )->name('api.telegram.chats.destroy');

        /*
         * File routes (management CRUD)
         */
        Route::get(
            '/file-routes',
            [FileRouteController::class, 'index']
        )->name('api.telegram.file-routes.index');

        Route::post(
            '/file-routes',
            [FileRouteController::class, 'store']
        )->name('api.telegram.file-routes.store');

        Route::put(
            '/file-routes/{fileRoute}',
            [FileRouteController::class, 'update']
        )->name('api.telegram.file-routes.update');

        Route::delete(
            '/file-routes/{fileRoute}',
            [FileRouteController::class, 'destroy']
        )->name('api.telegram.file-routes.destroy');

        /*
         * Scheduled messages (management CRUD)
         */
        Route::get('/scheduled-messages', [ScheduledMessageController::class, 'index'])
            ->name('api.telegram.scheduled-messages.index');

        Route::post('/scheduled-messages', [ScheduledMessageController::class, 'store'])
            ->name('api.telegram.scheduled-messages.store');

        Route::put('/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'update'])
            ->name('api.telegram.scheduled-messages.update');

        Route::delete('/scheduled-messages/{scheduledMessage}', [ScheduledMessageController::class, 'destroy'])
            ->name('api.telegram.scheduled-messages.destroy');

        Route::post('/scheduled-messages/{scheduledMessage}/send', [ScheduledMessageController::class, 'send'])
            ->name('api.telegram.scheduled-messages.send');

        /*
         * Listeners: the accounts running their own event loop (CEO, Head Operation ...)
         */
        Route::get('/listeners', [ListenerController::class, 'index'])
            ->name('api.telegram.listeners.index');

        Route::get('/system', [SystemController::class, 'index'])
            ->name('api.telegram.system');

        Route::get('/listener-processes', [ListenerProcessController::class, 'index'])
            ->name('api.telegram.listener-processes.index');

        Route::post('/listener-processes/start', [ListenerProcessController::class, 'start'])
            ->name('api.telegram.listener-processes.start');

        Route::post('/listener-processes/restart', [ListenerProcessController::class, 'restart'])
            ->name('api.telegram.listener-processes.restart');

        Route::post('/listener-processes/stop', [ListenerProcessController::class, 'stop'])
            ->name('api.telegram.listener-processes.stop');

        Route::put('/listener-duties', [ListenerController::class, 'updateOwners'])
            ->name('api.telegram.listeners.owners');

        Route::post('/listeners', [ListenerController::class, 'store'])
            ->name('api.telegram.listeners.store');

        Route::put('/listeners/{listener}', [ListenerController::class, 'update'])
            ->name('api.telegram.listeners.update');

        Route::delete('/listeners/{listener}', [ListenerController::class, 'destroy'])
            ->name('api.telegram.listeners.destroy');

        /*
         * Watchdog
         */
        Route::prefix('/watchdog')
            ->controller(DriverCheckWatchdogController::class)
            ->group(function () {
                Route::get('/', 'show')->name('api.telegram.watchdog.show');
                Route::post('/start', 'start')->name('api.telegram.watchdog.start');
                Route::post('/restart-listener', 'restartListener')->name('api.telegram.watchdog.restart-listener');
                Route::post('/stop', 'stop')->name('api.telegram.watchdog.stop');
            });

        /*
         * Contact sync: group members into the accounts' contacts
         */
        Route::prefix('/contact-sync')
            ->controller(ContactSyncController::class)
            ->group(function () {
                Route::get('/', 'show')->name('api.telegram.contact-sync.show');
                Route::post('/run', 'run')->name('api.telegram.contact-sync.run');
                Route::get('/logs', 'logs')->name('api.telegram.contact-sync.logs');
                Route::get('/report', [ContactSyncReportController::class, 'show'])->name('api.telegram.contact-sync.report');
                Route::get('/runs', [ContactSyncRunController::class, 'index'])->name('api.telegram.contact-sync.runs');
                Route::get('/runs/{runId}', [ContactSyncRunController::class, 'show'])->whereUuid('runId')->name('api.telegram.contact-sync.runs.show');
                Route::get('/settings', [ContactSyncSettingsController::class, 'show'])->name('api.telegram.contact-sync.settings');
                Route::put('/settings', [ContactSyncSettingsController::class, 'update'])->name('api.telegram.contact-sync.settings.update');
                Route::delete('/settings', [ContactSyncSettingsController::class, 'reset'])->name('api.telegram.contact-sync.settings.reset');
                Route::get('/contacts', [ContactCatalogController::class, 'index'])->name('api.telegram.contact-sync.contacts');
                Route::post('/accounts/{account}/enable', 'enableAccount')->name('api.telegram.contact-sync.accounts.enable');
                Route::get('/accounts', [ContactAccountsController::class, 'index'])->name('api.telegram.contact-sync.accounts.list');
                Route::post('/accounts/import', [ContactAccountsController::class, 'importAll'])->name('api.telegram.contact-sync.accounts.import-all');
                Route::post('/accounts/clear-database', [ContactAccountsController::class, 'clearDatabaseAll'])->name('api.telegram.contact-sync.accounts.clear-database-all');
                Route::post('/accounts/{account}/import', [ContactAccountsController::class, 'import'])->name('api.telegram.contact-sync.accounts.import');
                Route::post('/accounts/{account}/clear-account', [ContactAccountsController::class, 'clearAccount'])->name('api.telegram.contact-sync.accounts.clear-account');
                Route::post('/accounts/{account}/clear-database', [ContactAccountsController::class, 'clearDatabase'])->name('api.telegram.contact-sync.accounts.clear-database');
                Route::post('/groups', 'storeGroup')->name('api.telegram.contact-sync.groups.store');
                Route::patch('/groups/{group}', 'updateGroup')->name('api.telegram.contact-sync.groups.update');
                Route::delete('/groups/{group}', 'destroyGroup')->name('api.telegram.contact-sync.groups.destroy');
                Route::post('/groups/{group}/fetch', 'fetchMembers')->name('api.telegram.contact-sync.groups.fetch');
            });

        /*
         * Join group: keywords, the groups found, the accounts joining them, the people
         */
        Route::prefix('/group-join')
            ->controller(GroupJoinController::class)
            ->group(function () {
                Route::get('/', 'show')->name('api.telegram.group-join.show');
                Route::get('/groups', 'groups')->name('api.telegram.group-join.groups');
                Route::patch('/groups/{group}', 'updateGroup')->name('api.telegram.group-join.groups.update');
                Route::get('/people', 'people')->name('api.telegram.group-join.people');
                Route::post('/keywords', 'storeKeyword')->name('api.telegram.group-join.keywords.store');
                Route::post('/keywords/search-now', 'searchNow')->name('api.telegram.group-join.keywords.search-now');
                Route::patch('/keywords/{keyword}', 'updateKeyword')->name('api.telegram.group-join.keywords.update');
                Route::delete('/keywords/{keyword}', 'destroyKeyword')->name('api.telegram.group-join.keywords.destroy');
                Route::post('/accounts/{account}/enable', 'enableAccount')->name('api.telegram.group-join.accounts.enable');
                Route::get('/logs', 'logs')->name('api.telegram.group-join.logs');
                Route::get('/settings', [GroupJoinSettingsController::class, 'show'])->name('api.telegram.group-join.settings');
                Route::put('/settings', [GroupJoinSettingsController::class, 'update'])->name('api.telegram.group-join.settings.update');
                Route::delete('/settings', [GroupJoinSettingsController::class, 'reset'])->name('api.telegram.group-join.settings.reset');
            });

        /*
         * Queue
         */
        Route::prefix('/queue')
            ->controller(DriverCheckQueueController::class)
            ->group(function () {
                Route::get('/', 'summary')->name('api.telegram.queue.summary');
                Route::get('/jobs', 'jobs')->name('api.telegram.queue.jobs');
                Route::post('/move', 'move')->name('api.telegram.queue.move');
                Route::get('/failed', 'failed')->name('api.telegram.queue.failed');
                Route::post('/failed/retry', 'retryAll')->name('api.telegram.queue.retry-all');
                Route::delete('/failed', 'flush')->name('api.telegram.queue.flush');
                Route::get('/failed/{uuid}', 'failedJob')->name('api.telegram.queue.failed.show');
                Route::post('/failed/{uuid}/retry', 'retry')->name('api.telegram.queue.retry');
                Route::delete('/failed/{uuid}', 'forget')->name('api.telegram.queue.forget');
            });

        /*
         * Telegram sessions
         *
         * Not /accounts: routes/api.php already serves the sanctum version
         * of that path, and the later registration would shadow this one.
         */
        Route::prefix('/sessions')
            ->controller(TelegramSessionController::class)
            ->group(function () {
                Route::get('/', 'index')->name('api.telegram.sessions.index');
                Route::post('/', 'store')->name('api.telegram.sessions.store');
                Route::get('/{account}', 'show')->name('api.telegram.sessions.show');
                Route::delete('/{account}', 'destroy')->name('api.telegram.sessions.destroy');
                Route::post('/{account}/code', 'code')->name('api.telegram.sessions.code');
                Route::post('/{account}/password', 'password')->name('api.telegram.sessions.password');
                Route::post('/{account}/check', 'check')->name('api.telegram.sessions.check');
                Route::post('/{account}/logout', 'logout')->name('api.telegram.sessions.logout');
                Route::put('/{account}/processes/{process}', 'process')->name('api.telegram.sessions.process');
            });

        /*
         * Group invites: contacts of the accounts into a chosen group.
         */
        Route::prefix('/group-invites')
            ->controller(GroupInviteController::class)
            ->group(function () {
                Route::get('/', 'show')->name('api.telegram.group-invites.show');
                Route::put('/settings', 'updateSettings')->name('api.telegram.group-invites.settings');
                Route::post('/run', 'run')->name('api.telegram.group-invites.run');
                Route::post('/check', 'check')->name('api.telegram.group-invites.check');
                Route::post('/stop', 'stop')->name('api.telegram.group-invites.stop');
                Route::get('/logs', 'logs')->name('api.telegram.group-invites.logs');
                Route::get('/results', 'results')->name('api.telegram.group-invites.results');
                Route::post('/retry-failed', 'retryFailed')->name('api.telegram.group-invites.retry');
                Route::post('/groups', 'storeGroup')->name('api.telegram.group-invites.groups.store');
                Route::patch('/groups/{inviteGroup}', 'updateGroup')->name('api.telegram.group-invites.groups.update');
                Route::delete('/groups/{inviteGroup}', 'destroyGroup')->name('api.telegram.group-invites.groups.destroy');
                Route::post('/accounts/{account}/enable', 'enableAccount')->name('api.telegram.group-invites.accounts.enable');
            });

        /*
         * Drivers
         */
        Route::get(
            '/drivers',
            [TelegramDriverController::class, 'index']
        )->name('api.telegram.drivers');

        /*
         * Resolved Phones
         */
        Route::get(
            '/resolved-phones',
            [ResolvedPhoneController::class, 'index']
        )->name('api.telegram.resolved-phones');
    });
