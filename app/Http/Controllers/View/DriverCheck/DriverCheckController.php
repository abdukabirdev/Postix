<?php

declare(strict_types=1);

namespace App\Http\Controllers\View\DriverCheck;

use App\Helpers\DriverCheckNav;
use App\Http\Controllers\Controller;
use App\Models\Telegram\OperationUser;

/**
 * Access to this whole controller is enforced by the `role:driverCheck,
 * superadmin` middleware on the route group (see routes/web.php), so no
 * per-method authorization check is needed here.
 */
final class DriverCheckController extends Controller
{
    /**
     * The home page: the whole map of the panel, zone by zone.
     */
    public function home()
    {
        return view(
            'pages.driver-check.home',
            ['zones' => DriverCheckNav::zones()]
        );
    }

    /**
     * Every start and stop of the panel on one page; each block is the same
     * one its own section's page shows.
     */
    public function control()
    {
        return view(
            'pages.driver-check.control'
        );
    }

    /*
     * Some pages are one view showing one `part` of it: the same data and
     * the same component, one job per page.
     */

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
            'pages.driver-check.penalty-settings',
            ['part' => 'texts']
        );
    }

    public function penaltySending()
    {
        return view(
            'pages.driver-check.penalty-settings',
            ['part' => 'sending']
        );
    }

    public function autoReplies()
    {
        return view(
            'pages.driver-check.auto-replies',
            ['part' => 'main']
        );
    }

    public function autoRepliesKeywords()
    {
        return view(
            'pages.driver-check.auto-replies',
            ['part' => 'keywords']
        );
    }

    public function autoRepliesGreetings()
    {
        return view(
            'pages.driver-check.auto-replies',
            ['part' => 'greetings']
        );
    }

    public function autoRepliesReminders()
    {
        return view(
            'pages.driver-check.auto-replies',
            ['part' => 'reminders']
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
            'pages.driver-check.file-routes',
            ['part' => 'kinds']
        );
    }

    public function fileRoutesLog()
    {
        return view(
            'pages.driver-check.file-routes',
            ['part' => 'log']
        );
    }

    public function listeners()
    {
        return view(
            'pages.driver-check.listeners',
            ['part' => 'list']
        );
    }

    public function listenerDuties()
    {
        return view(
            'pages.driver-check.listeners',
            ['part' => 'duties']
        );
    }

    public function system()
    {
        return view(
            'pages.driver-check.system',
            ['part' => 'map']
        );
    }

    public function alerts()
    {
        return view(
            'pages.driver-check.system',
            ['part' => 'alerts']
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
            'pages.driver-check.watchdog',
            ['part' => 'all']
        );
    }

    public function watchdogDriverCheck()
    {
        return view(
            'pages.driver-check.watchdog',
            ['part' => 'driver-check']
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
            'pages.driver-check.contact-report',
            ['part' => 'overview']
        );
    }

    public function contactReportRuns()
    {
        return view(
            'pages.driver-check.contact-report',
            ['part' => 'runs']
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

    public function groupInvitesGroups()
    {
        return view(
            'pages.driver-check.group-invites-groups'
        );
    }

    public function groupInvitesPeople()
    {
        return view(
            'pages.driver-check.group-invites-people'
        );
    }

    public function groupInvitesAccounts()
    {
        return view(
            'pages.driver-check.group-invites-accounts'
        );
    }

    public function groupInvitesLog()
    {
        return view(
            'pages.driver-check.group-invites-log'
        );
    }

    public function groupInvitesAnalysis()
    {
        return view(
            'pages.driver-check.group-invites-analysis'
        );
    }

    public function groupInvitesRanking()
    {
        return view(
            'pages.driver-check.group-invites-ranking'
        );
    }

    public function groupInvitesDrivers()
    {
        return view(
            'pages.driver-check.group-invites-drivers'
        );
    }

    public function groupInvitesSettings()
    {
        return view(
            'pages.driver-check.group-invites-settings'
        );
    }

    public function groupInvitesBot()
    {
        return view(
            'pages.driver-check.group-invites-bot',
            ['part' => 'connection']
        );
    }

    public function groupInvitesBotReports()
    {
        return view(
            'pages.driver-check.group-invites-bot',
            ['part' => 'reports']
        );
    }

    public function groupInvitesBotRequests()
    {
        return view(
            'pages.driver-check.group-invites-bot',
            ['part' => 'requests']
        );
    }

    /* Who came into our groups, who left, who was removed and by whom. */
    public function groupInvitesBotMembers()
    {
        return view('pages.driver-check.group-invites-members');
    }

    public function groupInvitesSpam()
    {
        return view(
            'pages.driver-check.group-invites-spam',
            ['part' => 'list']
        );
    }

    public function groupInvitesSpamMessages()
    {
        return view(
            'pages.driver-check.group-invites-spam',
            ['part' => 'messages']
        );
    }

    /* The admins of the anti-spam: their links stay, they may forward spam; invite links for them. */
    public function groupInvitesSpamAdmins()
    {
        return view('pages.driver-check.group-invites-spam-admins');
    }

    public function groupJoin()
    {
        return view(
            'pages.driver-check.group-join',
            ['part' => 'status']
        );
    }

    public function groupJoinLog()
    {
        return view(
            'pages.driver-check.group-join',
            ['part' => 'log']
        );
    }

    public function groupJoinKeywords()
    {
        return view(
            'pages.driver-check.group-join',
            ['part' => 'keywords']
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

    /** The rejected groups and the ones gone, apart from the queue. */
    public function groupJoinRejected()
    {
        return view(
            'pages.driver-check.group-join-groups',
            ['view' => 'other']
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
