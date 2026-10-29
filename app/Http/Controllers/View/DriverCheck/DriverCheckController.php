<?php

declare(strict_types=1);

namespace App\Http\Controllers\View\DriverCheck;

use App\Http\Controllers\Controller;
use App\Models\Telegram\OperationUser;

/**
 * Access to this whole controller is enforced by the `role:driverCheck,
 * superadmin` middleware on the route group (see routes/web.php), so no
 * per-method authorization check is needed here.
 */
final class DriverCheckController extends Controller
{
    public function operationUsers()
    {
        return view(
            'pages.driver-check.operation-users'
        );
    }

    public function operationUser(OperationUser $operationUser)
    {
        return view(
            'pages.driver-check.operation-user',
            compact('operationUser'),
        );
    }

    public function operators()
    {
        return view(
            'pages.driver-check.operators',
            ['role' => OperationUser::ROLE_OPERATION],
        );
    }

    /**
     * Same page as operators(), fixed to the other role.
     */
    /**
     * Operators and sales managers in one list.
     */
    public function people()
    {
        return view(
            'pages.driver-check.operators',
            ['role' => 'all'],
        );
    }

    public function sales()
    {
        return view(
            'pages.driver-check.operators',
            ['role' => OperationUser::ROLE_SALES],
        );
    }

    public function penalties()
    {
        return view(
            'pages.driver-check.penalties'
        );
    }

    public function penaltySettings()
    {
        return view(
            'pages.driver-check.penalty-settings'
        );
    }

    public function autoReplies()
    {
        return view(
            'pages.driver-check.auto-replies'
        );
    }

    public function personalAnswers()
    {
        return view(
            'pages.driver-check.personal-answers'
        );
    }

    public function chats()
    {
        return view(
            'pages.driver-check.chats'
        );
    }

    public function fileRoutes()
    {
        return view(
            'pages.driver-check.file-routes'
        );
    }

    public function listeners()
    {
        return view(
            'pages.driver-check.listeners'
        );
    }

    public function system()
    {
        return view(
            'pages.driver-check.system'
        );
    }

    public function scheduledMessages()
    {
        return view(
            'pages.driver-check.scheduled-messages'
        );
    }

    public function sessions()
    {
        return view(
            'pages.driver-check.sessions'
        );
    }

    public function watchdog()
    {
        return view(
            'pages.driver-check.watchdog'
        );
    }

    public function contactSync()
    {
        return view(
            'pages.driver-check.contact-sync'
        );
    }

    public function contacts()
    {
        return view(
            'pages.driver-check.contacts'
        );
    }

    public function contactReport()
    {
        return view(
            'pages.driver-check.contact-report'
        );
    }

    public function contactSettings()
    {
        return view(
            'pages.driver-check.contact-settings'
        );
    }

    public function contactAccounts()
    {
        return view(
            'pages.driver-check.contact-accounts'
        );
    }

    public function groupInvites()
    {
        return view(
            'pages.driver-check.group-invites'
        );
    }

    public function groupJoin()
    {
        return view(
            'pages.driver-check.group-join'
        );
    }

    public function groupJoinJoined()
    {
        return view(
            'pages.driver-check.group-join-groups',
            ['view' => 'joined']
        );
    }

    public function groupJoinQueue()
    {
        return view(
            'pages.driver-check.group-join-groups',
            ['view' => 'queue']
        );
    }

    public function groupJoinPeople()
    {
        return view(
            'pages.driver-check.group-join-people'
        );
    }

    public function groupJoinSettings()
    {
        return view(
            'pages.driver-check.group-join-settings'
        );
    }

    public function queue()
    {
        return view(
            'pages.driver-check.queue'
        );
    }

    public function drivers()
    {
        return view(
            'pages.driver-check.drivers'
        );
    }

    public function resolvedPhones()
    {
        return view(
            'pages.driver-check.resolved-phones'
        );
    }
}
