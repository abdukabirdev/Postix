/*
|--------------------------------------------------------------------------
| Contacts: the catalog of everyone the sync found
|--------------------------------------------------------------------------
|
| A plain list page on ContactCatalogController. (The runs and their report
| are contact-report.js.)
|
*/

import { createListPage } from './list-page';
import { EM_DASH } from './format';

const STATUS_TONES = {
    added: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    pending: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    failed: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    skipped: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    unassigned: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    by_link: 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
};

const STATUS_DOTS = {
    added: 'bg-success-500',
    pending: 'bg-blue-light-500',
    failed: 'bg-error-500',
    skipped: 'bg-gray-400',
    unassigned: 'bg-warning-500',
    by_link: 'bg-brand-500',
};

export function contactsPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        t,
        ui,

        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                status: '',
                group_id: '',
                account_id: '',
                presence: '',
                bots: false,
                sort: 'created_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },

            onLoaded(json) {
                if (json.stats) {
                    this.stats = json.stats;
                }

                if (json.options) {
                    this.options = json.options;
                }
            },
        }),

        stats: {
            people: 0, added: 0, pending: 0, failed: 0, skipped: 0, unassigned: 0, by_link: 0, today: 0,
        },

        /** What the group and account filters choose from, sent with the list. */
        options: { groups: [], accounts: [] },

        statuses: ['added', 'pending', 'failed', 'skipped', 'unassigned', 'by_link'],

        init() {
            this.initList();
        },

        /** People still without a saved contact: nobody claimed them, or the claim is on its way. */
        waiting() {
            return Number(this.stats.unassigned) + Number(this.stats.pending);
        },

        statusLabel(status) {
            return t.status?.[status] ?? status;
        },

        statusClass(status) {
            return STATUS_TONES[status] ?? STATUS_TONES.skipped;
        },

        statusDot(status) {
            return STATUS_DOTS[status] ?? STATUS_DOTS.skipped;
        },

        /** The first two groups by name, "+n" for the rest. */
        groupsLine(row) {
            const names = (row.groups ?? []).map((group) => group.title);

            if (names.length <= 2) {
                return names.join(', ') || EM_DASH;
            }

            return `${names.slice(0, 2).join(', ')} +${names.length - 2}`;
        },

        /**
         * The account half of "where the person is": on the account that
         * saved them, not on it, or not known because it was never read.
         * (The database half is always yes - they are a row of it.)
         */
        whereAccount(row) {
            const w = t.where;

            if (row.on_account === true) {
                return { dot: 'bg-success-500', label: w.account, title: `${w.account}: ${w.account_yes}`, state: 'yes' };
            }

            if (row.on_account === false) {
                return { dot: 'bg-error-500', label: w.account, title: `${w.account}: ${w.account_no}`, state: 'no' };
            }

            return {
                dot: 'bg-gray-300 dark:bg-gray-600',
                label: w.account,
                title: row.status === 'unassigned' || row.status === 'by_link' ? w.no_owner : `${w.account}: ${w.account_unknown}`,
                state: 'unknown',
            };
        },

        displayName(row) {
            return row.name || (row.username ? `@${row.username}` : String(row.telegram_user_id));
        },
    };
}
