/*
|--------------------------------------------------------------------------
| Group invites
|--------------------------------------------------------------------------
|
| Groups the accounts invite their own contacts into - and people with a
| @username once those run out - how many at a time and how many times a
| day, how each account stands after a flood, and who was invited or failed.
|
*/

import { formatNumber } from './format';
import { requestJson } from './monitoring';

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const FIELDS = ['enabled', 'schedule_at', 'batch_size', 'batches_per_day', 'delay_min', 'delay_max', 'use_usernames', 'usernames_per_day', 'excluded_accounts'];

export function groupInvitesPage(config) {
    const t = config.translations;

    return {
        t,
        ui: config.ui,
        endpoints: config.endpoints,

        loading: false,
        busy: false,
        error: null,
        running: false,

        settings: null,
        stored: null,
        limits: {},
        groups: [],
        accounts: [],
        totals: { invited: 0, failed: 0, pending: 0, today: 0, batches_today: 0, by_username: 0, refused: 0 },

        link: '',
        status: '',
        groupId: '',
        results: [],
        meta: { current_page: 1, last_page: 1, total: 0 },

        poll: null,

        init() {
            this.load();
            this.loadResults();
        },

        destroy() {
            this.stopPoll();
        },

        number(value) {
            return formatNumber(value);
        },

        async load() {
            this.loading = this.groups.length === 0 && !this.settings;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.show);
                this.running = !!json.running;
                this.groups = json.groups ?? [];
                this.accounts = json.accounts ?? [];
                this.totals = json.totals ?? this.totals;
                this.limits = json.limits ?? {};
                this.stored = { ...json.settings };
                if (!this.dirty) {
                    this.settings = { ...json.settings, excluded_accounts: [...(json.settings.excluded_accounts ?? [])] };
                }
                this.running ? this.startPoll() : this.stopPoll();
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
        },

        get dirty() {
            return this.settings !== null
                && this.stored !== null
                && FIELDS.some((key) => JSON.stringify(this.settings[key]) !== JSON.stringify(this.stored[key]));
        },

        perAccount() {
            const size = Number(this.settings?.batch_size ?? 0);
            const times = Number(this.settings?.batches_per_day ?? 0);

            return size > 0 && times > 0 ? size * times : 0;
        },

        isExcluded(account) {
            return account.is_primary || this.settings.excluded_accounts.includes(account.id);
        },

        toggleExcluded(account) {
            if (account.is_primary) {
                return;
            }

            const set = new Set(this.settings.excluded_accounts);

            set.has(account.id) ? set.delete(account.id) : set.add(account.id);

            this.settings.excluded_accounts = [...set].sort((a, b) => a - b);
        },

        takingPart() {
            return this.accounts.filter((account) => !this.isExcluded(account)).length;
        },

        async save() {
            if (this.busy || !this.settings) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.settings, 'PUT', {
                    ...this.settings,
                    batch_size: Number(this.settings.batch_size),
                    batches_per_day: Number(this.settings.batches_per_day),
                    delay_min: Number(this.settings.delay_min),
                    delay_max: Number(this.settings.delay_max),
                    usernames_per_day: Number(this.settings.usernames_per_day),
                });

                this.stored = { ...json.settings };
                this.settings = { ...json.settings, excluded_accounts: [...(json.settings.excluded_accounts ?? [])] };
                notify(json.message);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async addGroup() {
            const link = this.link.trim();

            if (link === '' || this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.groups, 'POST', { link });
                this.link = '';
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async toggleGroup(group) {
            try {
                await requestJson(`${this.endpoints.groups}/${group.id}`, 'PATCH', { is_active: !group.is_active });
                group.is_active = !group.is_active;
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        async deleteGroup(group) {
            if (!window.confirm(t.groups.delete_confirm)) {
                return;
            }

            try {
                const json = await requestJson(`${this.endpoints.groups}/${group.id}`, 'DELETE');
                notify(json.message);
                await this.load();
                await this.loadResults();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        async run() {
            if (this.busy || this.running) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.run, 'POST', {});
                notify(json.message);
                this.running = true;
                this.startPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async enable(account) {
            try {
                const json = await requestJson(`${this.endpoints.accounts}/${account.id}/enable`, 'POST', {});
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        async retryFailed() {
            if (!window.confirm(t.results.retry_confirm)) {
                return;
            }

            try {
                const json = await requestJson(this.endpoints.retry, 'POST', {});
                notify(json.message);
                await this.load();
                await this.loadResults(1);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        async loadResults(page = this.meta.current_page || 1) {
            const params = new URLSearchParams({ page: String(page), per_page: '20' });

            if (this.status) {
                params.set('status', this.status);
            }

            if (this.groupId) {
                params.set('group_id', this.groupId);
            }

            try {
                const json = await requestJson(`${this.endpoints.results}?${params}`);
                this.results = json.data ?? [];
                this.meta = json.meta ?? this.meta;
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        statusLabel(status) {
            return t.results[status] ?? status;
        },

        /*
         * Telegram's code in words; a group's own @name has its own text.
         */
        errorLabel(code, forGroup = false) {
            if (!code) {
                return '';
            }

            const wait = /^FLOOD_(?:PREMIUM_)?WAIT_(\d+)/.exec(code);

            if (wait) {
                return t.errors.flood_wait.replace(':n', wait[1]);
            }

            return (forGroup && t.errors[`${code}_GROUP`]) || t.errors[code] || code;
        },

        time(iso) {
            const date = new Date(iso);

            if (Number.isNaN(date.getTime())) {
                return '';
            }

            const pad = (n) => String(n).padStart(2, '0');
            const clock = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

            return date.toDateString() === new Date().toDateString()
                ? clock
                : `${pad(date.getDate())}.${pad(date.getMonth() + 1)} ${clock}`;
        },

        /*
         * Why the account sits out: on hold after errors, or resting after a
         * flood until the time Telegram set.
         */
        accountState(account) {
            if (account.held) {
                return t.accounts.held + (account.held_reason ? ` · ${this.errorLabel(account.held_reason)}` : '');
            }

            if (account.rest_until) {
                const kind = t.accounts.rest_kinds[account.rest_kind] ?? this.errorLabel(account.rest_reason);

                return `${t.accounts.resting.replace(':time', this.time(account.rest_until))} · ${kind}`;
            }

            return '';
        },

        canEnable(account) {
            return account.held || !!account.rest_until || account.cap_lowered;
        },

        startPoll() {
            if (this.poll) {
                return;
            }

            this.poll = setInterval(() => {
                this.load();

                if ((this.meta.current_page || 1) === 1) {
                    this.loadResults(1);
                }
            }, 8000);
        },

        stopPoll() {
            if (this.poll) {
                clearInterval(this.poll);
                this.poll = null;
            }
        },
    };
}
