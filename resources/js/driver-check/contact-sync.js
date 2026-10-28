/*
|--------------------------------------------------------------------------
| Contact sync: the management page
|--------------------------------------------------------------------------
|
| What the sync works with: the groups it reads and the accounts that save
| the contacts. How a run behaves is the settings page (contact-settings.js);
| starting a run and reading the history of runs is the report page
| (contact-report.js).
|
| State changes on its own while a run is going, so the summary polls the
| way the watchdog page does.
|
*/

import { requestJson } from './monitoring';
import { EM_DASH, formatNumber, formatPhone, formatRelative } from './format';

const POLL_MS = 5000;

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

export function contactSyncPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        t,
        ui,

        endpoints: config.endpoints,

        status: null,

        loading: false,

        error: null,

        busy: null,

        newLink: '',

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
                const json = await requestJson(this.endpoints.show);

                this.status = json.data;
                this.error = null;
            } catch (error) {
                this.error = error?.message || t.errors.load_failed;
            } finally {
                this.loading = false;

                this.timer = setTimeout(() => this.load(), POLL_MS);
            }
        },

        /*
        |----------------------------------------------------------------
        | Groups and accounts
        |----------------------------------------------------------------
        */

        async act(key, url, method = 'POST', body = null) {
            if (this.busy) {
                return false;
            }

            this.busy = key;

            try {
                const json = await requestJson(url, method, body);

                if (json.message) {
                    notify(json.message);
                }

                await this.load();

                return true;
            } catch (error) {
                notify(error?.message || t.errors.action, false);

                return false;
            } finally {
                this.busy = null;
            }
        },

        async addGroup() {
            const link = this.newLink.trim();

            if (link === '') {
                return;
            }

            if (await this.act('add', this.endpoints.groups, 'POST', { link })) {
                this.newLink = '';
            }
        },

        enableAccount(account) {
            return this.act(`enable-${account.id}`, `${this.endpoints.accounts}/${account.id}/enable`, 'POST');
        },

        toggleGroup(group) {
            return this.act(`toggle-${group.id}`, `${this.endpoints.groups}/${group.id}`, 'PATCH', {
                is_active: !group.is_active,
            });
        },

        /** Who is in the group, into the database only: nobody is saved into contacts. */
        fetchMembers(group) {
            return this.act(`fetch-${group.id}`, `${this.endpoints.groups}/${group.id}/fetch`, 'POST');
        },

        removeGroup(group) {
            if (!window.confirm(t.groups.confirm_delete)) {
                return null;
            }

            return this.act(`delete-${group.id}`, `${this.endpoints.groups}/${group.id}`, 'DELETE');
        },

        /*
        |----------------------------------------------------------------
        | Presentation
        |----------------------------------------------------------------
        */

        groupName(group) {
            return group?.title || group?.link || EM_DASH;
        },

        /** How many accounts, at the daily limit each, would save everybody still waiting in one day. */
        accountsNeeded() {
            const waiting = Number(this.status?.totals?.unassigned ?? 0);
            const limit = Number(this.status?.daily_limit ?? 0);

            if (waiting <= 0 || limit <= 0) {
                return '';
            }

            return t.totals.accounts_needed
                .replace(':n', formatNumber(Math.ceil(waiting / limit)))
                .replace(':limit', formatNumber(limit));
        },

        number: formatNumber,
        relative: formatRelative,
        phone: formatPhone,
    };
}
