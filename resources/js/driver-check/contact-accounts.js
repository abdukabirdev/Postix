/*
|--------------------------------------------------------------------------
| Contacts: the accounts page
|--------------------------------------------------------------------------
|
| For each account two numbers side by side - what the database lists for it
| and what the account itself holds in Telegram - and the buttons that move
| them: read the account into the database, delete from the account, forget
| in the database. Reading goes through the accounts one after the other (a
| background command); the page looks again every few seconds while it does.
|
*/

import { requestJson } from './monitoring';
import { EM_DASH, formatDate, formatNumber, formatPhone, formatRelative } from './format';

const POLL_MS = 4000;

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const AGREE_TONES = {
    match: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    database_less: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    account_less: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    unknown: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
};

const AGREE_DOTS = {
    match: 'bg-success-500',
    database_less: 'bg-warning-500',
    account_less: 'bg-warning-500',
    unknown: 'bg-gray-400',
};

export function contactAccountsPage(config) {
    const t = config.translations;

    return {
        t,

        endpoints: config.endpoints,

        data: null,

        loading: false,

        error: null,

        busy: null,

        timer: null,

        dash: EM_DASH,

        init() {
            this.load();
        },

        destroy() {
            clearTimeout(this.timer);
        },

        async load() {
            clearTimeout(this.timer);

            this.loading = true;

            try {
                this.data = (await requestJson(this.endpoints.list)).data;
                this.error = null;
            } catch (error) {
                this.error = error?.message || t.error;
            } finally {
                this.loading = false;
            }

            /* While anything is waiting or running, keep looking. */
            if ((this.working || this.data?.running) && !document.hidden) {
                this.timer = setTimeout(() => this.load(), POLL_MS);
            }
        },

        get accounts() {
            return this.data?.accounts ?? [];
        },

        /** A read or a clean-up is queued or running somewhere. */
        get working() {
            return this.accounts.some((account) => ['queued', 'running'].includes(account.status));
        },

        /** The buttons wait while anything works on the contacts. */
        get locked() {
            return !!this.busy || this.working || !!this.data?.running;
        },

        canWork(account) {
            return account.usable && !this.locked;
        },

        /*
        |----------------------------------------------------------------
        | Actions
        |----------------------------------------------------------------
        */

        async post(key, url, body = null) {
            if (this.busy) {
                return false;
            }

            this.busy = key;

            try {
                const json = await requestJson(url, 'POST', body);

                notify(json.message);

                this.busy = null;

                await this.load();

                return true;
            } catch (error) {
                notify(error?.message || t.error, false);

                return false;
            } finally {
                this.busy = null;
            }
        },

        sync(account) {
            return this.post(`sync-${account.id}`, `${this.endpoints.base}/${account.id}/import`);
        },

        syncAll() {
            return this.post('sync-all', `${this.endpoints.base}/import`);
        },

        clearAccount(account) {
            const message = t.confirm_clear_account
                .replace(':phone', this.phone(account.phone))
                .replace(':n', this.number(account.in_database));

            if (!window.confirm(message)) {
                return null;
            }

            return this.post(`clear-account-${account.id}`, `${this.endpoints.base}/${account.id}/clear-account`, { confirm: true });
        },

        clearDatabase(account) {
            if (!window.confirm(t.confirm_clear_db.replace(':phone', this.phone(account.phone)))) {
                return null;
            }

            return this.post(`clear-db-${account.id}`, `${this.endpoints.base}/${account.id}/clear-database`, { confirm: true });
        },

        clearDatabaseAll() {
            if (!window.confirm(t.confirm_clear_db_all)) {
                return null;
            }

            return this.post('clear-db-all', `${this.endpoints.base}/clear-database`, { confirm: true });
        },

        enable(account) {
            return this.post(`enable-${account.id}`, `${this.endpoints.base}/${account.id}/enable`);
        },

        /*
        |----------------------------------------------------------------
        | Presentation
        |----------------------------------------------------------------
        */

        agreeClass(account) {
            return AGREE_TONES[account.agree] ?? AGREE_TONES.unknown;
        },

        agreeDot(account) {
            return AGREE_DOTS[account.agree] ?? AGREE_DOTS.unknown;
        },

        agreeLabel(account) {
            return t.agree[account.agree] ?? account.agree;
        },

        /** How much the two numbers differ, in the hint's words. */
        agreeHint(account) {
            const diff = Math.abs((account.in_account ?? 0) - account.in_database);

            return (t.agree_hint[account.agree] ?? '').replace(':n', this.number(diff));
        },

        statusLabel(account) {
            const action = t.action_name[account.action] ?? '';

            return account.status === 'failed'
                ? t.status.failed
                : `${t.status[account.status] ?? ''}${action ? ' · ' + action : ''}`;
        },

        number: formatNumber,
        relative: formatRelative,

        date: formatDate,
        phone: formatPhone,
    };
}
