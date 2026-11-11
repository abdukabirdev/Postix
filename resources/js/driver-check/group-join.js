/*
|--------------------------------------------------------------------------
| Join group: the status page
|--------------------------------------------------------------------------
|
| The watchdog and the worker of every marked account (up or not, what it is
| doing, its pause or flood wait), the keywords the workers search by (the
| learned ones among them) and the log. The groups and the people have
| pages of their own (group-join-groups.js, group-join-people.js).
|
| The workers change things on their own, so the page polls the way the
| watchdog page does.
|
*/

import { requestJson } from './monitoring';
import { EM_DASH, formatDate, formatNumber, formatPhone, formatRelative } from './format';

const POLL_MS = 10000;

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const ACCOUNT_DOTS = {
    working: 'animate-pulse text-success-500',
    pause: 'text-success-500',
    flood: 'text-warning-500',
    full: 'text-gray-400',
    limit: 'text-gray-400',
    disabled: 'text-error-500',
    down: 'text-error-500',
    unauthorized: 'text-error-500',
};

/** "12:04:31" today, "21 окт. 12:04:31" another day. */
export function clockTime(value) {
    const date = value ? new Date(value) : null;

    if (!date || Number.isNaN(date.getTime())) {
        return EM_DASH;
    }

    const sameDay = date.toDateString() === new Date().toDateString();
    const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    return sameDay ? time : `${formatDate(value, false)} ${time}`;
}

export function groupJoinPage(config) {
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

        timer: null,

        logs: [],

        keywordForm: { keyword: '', topic: '', expand: false },

        showLearned: false,

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
                /* Each page asks for what it shows: the log page the log, the others the status and the keywords. */
                if (config.part === 'log') {
                    const logs = await requestJson(`${this.endpoints.logs}?per_page=50`);

                    this.status ??= {};
                    this.logs = logs.data ?? [];
                } else {
                    this.status = (await requestJson(this.endpoints.show)).data;
                }

                this.error = null;
            } catch (error) {
                this.error = error?.message || t.errors.load_failed;
            } finally {
                this.loading = false;

                this.timer = setTimeout(() => this.tickLoad(), POLL_MS);
            }
        },

        /* The timer's turn: a tab nobody looks at does not ask. */
        tickLoad() {
            if (document.visibilityState === 'visible') {
                this.load();
            } else {
                this.timer = setTimeout(() => this.tickLoad(), POLL_MS);
            }
        },

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

        /*
        |----------------------------------------------------------------
        | Keywords
        |----------------------------------------------------------------
        */

        get keywords() {
            return (this.status?.keywords ?? []).filter((keyword) => this.showLearned || keyword.source !== 'learned');
        },

        async addKeywords() {
            if (this.keywordForm.keyword.trim() === '') {
                return;
            }

            if (await this.act('keyword-add', this.endpoints.keywords, 'POST', { ...this.keywordForm })) {
                this.keywordForm.keyword = '';
            }
        },

        toggleKeyword(keyword) {
            return this.act(`keyword-${keyword.id}`, `${this.endpoints.keywords}/${keyword.id}`, 'PATCH', {
                is_active: !keyword.is_active,
            });
        },

        toggleExpand(keyword) {
            return this.act(`keyword-${keyword.id}`, `${this.endpoints.keywords}/${keyword.id}`, 'PATCH', {
                expand: !keyword.expand,
            });
        },

        removeKeyword(keyword) {
            if (!window.confirm(t.keywords.confirm_delete)) {
                return null;
            }

            return this.act(`keyword-${keyword.id}`, `${this.endpoints.keywords}/${keyword.id}`, 'DELETE');
        },

        searchNow() {
            return this.act('search-now', `${this.endpoints.keywords}/search-now`, 'POST');
        },

        enableAccount(account) {
            return this.act(`account-${account.id}`, `${this.endpoints.accounts}/${account.id}/enable`, 'POST');
        },

        keywordLine(keyword) {
            return t.keywords.progress
                .replace(':done', formatNumber(keyword.searched_count))
                .replace(':all', formatNumber(keyword.searches_count))
                .replace(':groups', formatNumber(keyword.groups_count));
        },

        /*
        |----------------------------------------------------------------
        | Accounts
        |----------------------------------------------------------------
        */

        /** What the worker of the account is in now, the first that applies. */
        accountState(account) {
            if (!account.authorized) {
                return 'unauthorized';
            }

            if (account.disabled) {
                return 'disabled';
            }

            if (!account.running) {
                return 'down';
            }

            if (account.flood_until) {
                return 'flood';
            }

            if (['join', 'members', 'search', 'check'].includes(account.doing)) {
                return 'working';
            }

            if (account.full || account.held >= this.status.limits.max_groups_per_account) {
                return 'full';
            }

            if (account.today >= this.status.limits.joins_per_day) {
                return 'limit';
            }

            return 'pause';
        },

        accountDot(account) {
            return ACCOUNT_DOTS[this.accountState(account)];
        },

        accountLine(account) {
            const state = this.accountState(account);
            const states = t.accounts.states;

            if (state === 'working') {
                return t.accounts.doing[account.doing] ?? account.doing;
            }

            if (state === 'flood') {
                return states.flood
                    .replace(':time', clockTime(account.flood_until))
                    .replace(':rpc', account.flood_rpc || 'FLOOD_WAIT');
            }

            if (state === 'pause') {
                return account.next_join_at
                    ? states.pause.replace(':time', clockTime(account.next_join_at))
                    : states.idle;
            }

            if (state === 'disabled') {
                return states.disabled.replace(':reason', account.disabled_reason || EM_DASH);
            }

            if (state === 'down' && account.last_exit?.message) {
                return `${states.down} — ${account.last_exit.message}`;
            }

            return states[state];
        },

        /*
        |----------------------------------------------------------------
        | Log
        |----------------------------------------------------------------
        */

        eventText(log) {
            return t.events[log.event] ?? log.event;
        },

        eventTone(log) {
            return {
                joined: 'text-success-600 dark:text-success-400',
                members: 'text-success-600 dark:text-success-400',
                found: 'text-brand-600 dark:text-brand-400',
                learned: 'text-brand-600 dark:text-brand-400',
                requested: 'text-blue-light-600 dark:text-blue-light-400',
                flood: 'text-warning-600 dark:text-warning-400',
                failed: 'text-error-600 dark:text-error-400',
                disabled: 'text-error-600 dark:text-error-400',
                dead: 'text-error-600 dark:text-error-400',
            }[log.event] ?? 'text-gray-500 dark:text-gray-400';
        },

        clock: clockTime,
        number: formatNumber,
        relative: formatRelative,
        phone: formatPhone,
    };
}
