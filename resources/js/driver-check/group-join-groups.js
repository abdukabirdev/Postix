/*
|--------------------------------------------------------------------------
| Join group: the groups
|--------------------------------------------------------------------------
|
| One page, two lists: the groups our accounts are in (view "joined") and
| the ones waiting for an account (view "queue"; the rejected and the gone
| ones behind a switch). Nobody adds groups here - the workers find them;
| a person can only approve or reject one, and say whether a joined group's
| people go into contacts or only into the database.
|
*/

import { requestJson } from './monitoring';
import { EM_DASH, formatDate, formatNumber, formatPhone, formatRelative } from './format';

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const STATUS_TONES = {
    new: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    approved: 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
    joined: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    rejected: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    dead: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
};

export function groupJoinGroupsPage(config) {
    const t = config.translations;

    return {
        t,

        endpoints: config.endpoints,

        /** joined | queue; "other" (the rejected ones) is the queue with `other` set. */
        baseView: config.view,

        showOther: !!config.other,

        groups: [],

        meta: null,

        loading: false,

        busy: null,

        filters: { status: '', type: '', source: '', search: '', sort: '' },

        dash: EM_DASH,

        init() {
            this.load();
        },

        get view() {
            return this.baseView === 'queue' && this.showOther ? 'other' : this.baseView;
        },

        url(page = 1) {
            const params = new URLSearchParams({ view: this.view, page: String(page), per_page: '50' });

            Object.entries(this.filters).forEach(([key, value]) => {
                if (value !== '' && value !== null) {
                    params.set(key, value);
                }
            });

            return `${this.endpoints.groups}?${params}`;
        },

        async load(more = false) {
            if (this.loading) {
                return;
            }

            this.loading = true;

            try {
                const page = more && this.meta ? this.meta.current_page + 1 : 1;
                const json = await requestJson(this.url(page));

                this.groups = more ? [...this.groups, ...json.data] : json.data;
                this.meta = { current_page: json.current_page, last_page: json.last_page, total: json.total };
            } catch (error) {
                notify(error?.message || t.errors.load_failed, false);
            } finally {
                this.loading = false;
            }
        },

        toggleOther() {
            this.showOther = !this.showOther;
            this.filters.status = '';
            this.load();
        },

        setStatus(value) {
            this.filters.status = value;
            this.load();
        },

        get hasMore() {
            return this.meta !== null && this.meta.current_page < this.meta.last_page;
        },

        async act(group, action) {
            if (this.busy) {
                return;
            }

            this.busy = group.id;

            try {
                const json = await requestJson(`${this.endpoints.groups}/${group.id}`, 'PATCH', { action });

                if (json.message) {
                    notify(json.message);
                }

                Object.assign(group, json.data ?? {});
            } catch (error) {
                notify(error?.message || t.errors.action, false);
            } finally {
                this.busy = null;
            }
        },

        statusTone(group) {
            return STATUS_TONES[group.join_status] ?? STATUS_TONES.rejected;
        },

        statusText(group) {
            return t.statuses[group.join_status] ?? group.join_status;
        },

        reasonText(group) {
            return group.reject_reason ? (t.reasons[group.reject_reason] ?? group.reject_reason) : '';
        },

        sourceText(group) {
            const source = t.sources[group.source] ?? group.source;

            return group.found_via ? `${source}: ${group.found_via}` : source;
        },

        groupName(group) {
            return group?.title || group?.link || EM_DASH;
        },

        groupUrl(group) {
            return `https://t.me/${group.link}`;
        },

        peopleUrl(group) {
            return `${this.endpoints.people_page}?group_id=${group.id}`;
        },

        accountsIn(group) {
            return (group.memberships ?? [])
                .filter((m) => m.status === 'joined' || m.status === 'requested')
                .map((m) => formatPhone(m.account?.phone) || `#${m.telegram_account_id}`);
        },

        scriptText(group) {
            return group.script_share === null || group.script_share === undefined ? EM_DASH : `${group.script_share}%`;
        },

        number: formatNumber,
        relative: formatRelative,
        date: formatDate,
    };
}
