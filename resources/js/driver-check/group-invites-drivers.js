/*
|--------------------------------------------------------------------------
| Group invites: drivers into a group
|--------------------------------------------------------------------------
|
| The group and the rules (add on resolve, per account a day, the pause),
| what the run does now, the drivers in numbers, who adds and how each
| account stands, the last lines of the run, every driver of the group.
| The account that found the driver by the number adds them; one that
| cannot work any more leaves its drivers to another. Polls while a run
| goes on. The control desk runs the same component in a card.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

/* The parts of the bar, in a fixed order. */
const PARTS = ['invited', 'already', 'not_added', 'failed', 'sent', 'pending', 'not_queued'];

const FILLS = {
    invited: 'bg-success-500',
    already: 'bg-brand-400',
    not_added: 'bg-warning-500',
    failed: 'bg-error-500',
    sent: 'bg-gray-500',
    pending: 'bg-gray-300 dark:bg-gray-600',
    not_queued: 'bg-gray-100 dark:bg-white/[0.08]',
};

const TONES = {
    pending: 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300',
    invited: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    already: 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
    not_added: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    failed: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    sent: 'bg-gray-200 text-gray-700 dark:bg-white/10 dark:text-gray-200',
};

const STATE_TONES = {
    ready: 'text-success-600 dark:text-success-400',
    capped: 'text-gray-500',
    resting: 'text-warning-600 dark:text-warning-400',
    busy: 'text-gray-500',
    gone: 'text-error-600 dark:text-error-400',
};

export function groupInvitesDriversPage(config) {
    const t = config.translations;
    const d = config.drivers;

    return {
        ...inviteHelpers(t),

        t,
        d,
        endpoints: config.endpoints,
        compact: !!config.compact,

        loading: false,
        busy: false,
        saving: false,
        error: null,
        data: null,
        running: false,
        now: null,
        form: null,
        savedForm: '',
        poll: null,

        rows: [],
        meta: null,
        listLoading: false,
        filters: { status: 'all', q: '' },

        init() {
            this.load();
        },

        destroy() {
            this.stopPoll();
        },

        async load() {
            this.loading = this.data === null;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.show);

                this.data = json;
                this.running = !!json.running;
                this.now = json.now ?? null;

                /* The form follows the server until it is touched. */
                if (this.form === null || !this.dirty()) {
                    this.form = this.formFrom(json.settings);
                    this.savedForm = JSON.stringify(this.form);
                }

                this.running ? this.startPoll() : this.stopPoll();

                if (!this.compact) {
                    this.loadRows();
                }
            } catch (error) {
                this.error = error?.message || d.load_failed;
            } finally {
                this.loading = false;
            }
        },

        startPoll() {
            this.poll ??= setInterval(() => this.load(), 4000);
        },

        stopPoll() {
            if (this.poll) {
                clearInterval(this.poll);
                this.poll = null;
            }
        },

        /*
        |--------------------------------------------------------------
        | The buttons
        |--------------------------------------------------------------
        */

        async post(endpoint, confirmText = null) {
            if (this.busy || (confirmText && !window.confirm(confirmText))) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(endpoint, 'POST', {});
                notify(json.message);
                this.startPoll();
                setTimeout(() => this.load(), 1500);
            } catch (error) {
                notify(error?.message || d.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        addAll() {
            this.post(this.endpoints.all, d.add_all_confirm
                .replace(':n', this.number(this.data?.counts?.not_queued ?? 0))
                .replace(':group', this.data?.group?.title ?? ''));
        },

        run() {
            this.post(this.endpoints.run);
        },

        retry() {
            this.post(this.endpoints.retry, d.retry_confirm.replace(':n', this.number(this.data?.counts?.retryable ?? 0)));
        },

        async stop() {
            if (this.busy || !this.running || !window.confirm(d.stop_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.stop, 'POST', {});
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || d.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        /*
        |--------------------------------------------------------------
        | The settings
        |--------------------------------------------------------------
        */

        formFrom(settings) {
            return {
                group_id: settings?.group_id ? String(settings.group_id) : '',
                on_resolve: !!settings?.on_resolve,
                per_account_day: settings?.per_account_day ?? 25,
                delay_min: settings?.delay_min ?? 20,
                delay_max: settings?.delay_max ?? 40,
            };
        },

        dirty() {
            return this.form !== null && JSON.stringify(this.form) !== this.savedForm;
        },

        async save() {
            if (this.saving || !this.form) {
                return;
            }

            const before = this.data?.settings?.group_id ? String(this.data.settings.group_id) : '';

            if (before !== '' && this.form.group_id !== before && (this.data?.counts?.pending ?? 0) > 0 && !window.confirm(d.settings.group_change_confirm)) {
                return;
            }

            this.saving = true;

            try {
                const json = await requestJson(this.endpoints.settings, 'PUT', {
                    group_id: this.form.group_id === '' ? null : Number(this.form.group_id),
                    on_resolve: !!this.form.on_resolve,
                    per_account_day: Number(this.form.per_account_day),
                    delay_min: Number(this.form.delay_min),
                    delay_max: Number(this.form.delay_max),
                });

                notify(json.message);
                this.savedForm = JSON.stringify(this.form);
                await this.load();
            } catch (error) {
                notify(error?.message || d.load_failed, false);
            } finally {
                this.saving = false;
            }
        },

        groupOption(group) {
            return group.title + (group.is_current ? ` · ${d.settings.current}` : '');
        },

        sinceLabel() {
            const since = this.data?.settings?.on_resolve_since;

            return since && this.data?.settings?.on_resolve ? d.settings.on_resolve_since.replace(':time', this.time(since)) : '';
        },

        /*
        |--------------------------------------------------------------
        | What runs now
        |--------------------------------------------------------------
        */

        doingLabel() {
            return d.now.doing[this.now?.doing] ?? d.now.doing.starting;
        },

        whereLabel() {
            const parts = [];

            if (this.now?.account) {
                parts.push(this.phone(this.now.account) + (this.now.fallback ? ` (${d.now.fallback})` : ''));
            }

            if (this.now?.driver) {
                parts.push(this.now.driver);
            }

            const until = this.now?.step?.until;

            if (until) {
                parts.push(d.now.until.replace(':time', this.clock(until)));
            }

            return parts.join(' · ');
        },

        lastRunLabel() {
            const now = this.now;

            if (!now?.started_at) {
                return d.now.never;
            }

            if (now.broken) {
                return d.now.broken.replace(':time', this.time(now.started_at));
            }

            const stopped = (now.lines ?? []).some((line) => line.event === 'finished' && line.stopped);

            return (stopped ? d.now.last_stopped : d.now.last).replace(':time', this.time(now.finished_at || now.started_at));
        },

        summary(totals) {
            const n = totals ?? {};
            let text = d.now.summary
                .replace(':invited', this.number(n.invited ?? 0))
                .replace(':already', this.number(n.already ?? 0))
                .replace(':not_added', this.number(n.not_added ?? 0))
                .replace(':failed', this.number(n.failed ?? 0));

            if ((n.sent ?? 0) > 0) {
                text += d.now.unchecked.replace(':sent', this.number(n.sent));
            }

            return text;
        },

        groupError() {
            const code = this.now?.error;

            return code ? d.now.group_error.replace(':reason', this.errorText(code)) : '';
        },

        /*
        |--------------------------------------------------------------
        | The drivers in numbers
        |--------------------------------------------------------------
        */

        counts() {
            return this.data?.counts ?? { found: 0, queued: 0, not_queued: 0, pending: 0, invited: 0, already: 0, not_added: 0, failed: 0, sent: 0, retryable: 0 };
        },

        /* Everybody found in Telegram: the queue and its ends, and who is not in it yet. */
        parts() {
            const counts = this.counts();
            const all = Math.max(counts.found, counts.queued);

            return PARTS.map((key) => ({
                key,
                value: counts[key] ?? 0,
                share: all > 0 ? (100 * (counts[key] ?? 0)) / all : 0,
            }));
        },

        partFill(key) {
            return FILLS[key] ?? FILLS.pending;
        },

        partTitle(part) {
            return `${d.statuses[part.key]}: ${this.number(part.value)}`;
        },

        showPart(key) {
            if (key === 'not_queued') {
                return;
            }

            this.filters.status = key;
            this.loadRows();
            this.$refs.list?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        statusText(status) {
            return d.statuses[status] ?? status;
        },

        statusTone(status) {
            return TONES[status] ?? TONES.pending;
        },

        errorText(code) {
            if (!code) {
                return '';
            }

            return d.errors[code] ?? this.errorLabel(code);
        },

        /*
        |--------------------------------------------------------------
        | Who adds
        |--------------------------------------------------------------
        */

        stateText(account) {
            const states = d.accounts.states;

            if (account.state === 'resting') {
                return states.resting.replace(':time', this.time(account.until)) + (account.reason ? ` · ${this.errorText(account.reason)}` : '');
            }

            if (account.state === 'gone') {
                const [reason, code] = String(account.reason ?? '').split(':');
                const why = d.accounts.reasons[reason] ?? reason;

                return `${states.gone} · ${why}${code ? ` (${this.errorText(code)})` : ''}`;
            }

            return states[account.state] ?? account.state;
        },

        stateTone(account) {
            return STATE_TONES[account.state] ?? 'text-gray-500';
        },

        /*
        |--------------------------------------------------------------
        | The last run's lines
        |--------------------------------------------------------------
        */

        lines() {
            return (this.now?.lines ?? []).slice(0, this.compact ? 4 : 40);
        },

        lineText(line) {
            const e = d.log.events;
            const account = this.phone(line.account);

            switch (line.event) {
                case 'started':
                    return e.started.replace(':group', line.group ?? '');
                case 'account':
                    return (line.fallback ? e.account_fallback : e.account)
                        .replace(':account', account)
                        .replace(':size', this.number(line.size ?? 0));
                case 'joined':
                    return e.joined.replace(':account', account);
                case 'driver':
                    return e.driver
                        .replace(':driver', line.driver || this.phone(line.phone))
                        .replace(':status', this.statusText(line.status) + (line.code ? ` (${this.errorText(line.code)})` : ''))
                        + (line.re_resolved ? ` · ${d.log.re_resolved}` : '');
                case 'rest':
                    return e.rest
                        .replace(':account', account)
                        .replace(':reason', this.errorText(line.code) + (line.until ? ` · ${d.now.until.replace(':time', this.time(line.until))}` : ''));
                case 'denied':
                    return e.denied.replace(':account', account).replace(':reason', this.errorText(line.code));
                case 'group_gone':
                    return e.group_gone.replace(':reason', this.errorText(line.code));
                case 'group_denied':
                    return e.group_denied.replace(':n', line.accounts ?? 3).replace(':reason', this.errorText(line.code));
                case 'finished':
                    return (line.stopped ? e.stopped : e.finished).replace(':summary', this.summary(line));
                default:
                    return e[line.event] ?? line.event;
            }
        },

        lineTone(line) {
            if (line.event === 'driver') {
                return { invited: 'text-success-500', already: 'text-brand-400', not_added: 'text-warning-500', failed: 'text-error-500' }[line.status] ?? 'text-gray-400';
            }

            if (['rest', 'denied'].includes(line.event)) {
                return 'text-warning-500';
            }

            if (['group_gone', 'group_denied'].includes(line.event)) {
                return 'text-error-500';
            }

            return 'text-gray-300 dark:text-gray-600';
        },

        /*
        |--------------------------------------------------------------
        | The list
        |--------------------------------------------------------------
        */

        statusChips() {
            const counts = this.counts();

            return ['all', 'pending', 'invited', 'already', 'not_added', 'failed', 'sent'].map((value) => ({
                value,
                label: value === 'all'
                    ? `${d.list.all} · ${this.number(counts.queued)}`
                    : `${d.statuses[value]} · ${this.number(counts[value] ?? 0)}`,
            }));
        },

        setStatus(value) {
            this.filters.status = value;
            this.loadRows();
        },

        async loadRows(more = false) {
            if (!this.data?.group) {
                this.rows = [];
                this.meta = null;

                return;
            }

            this.listLoading = true;

            try {
                const page = more ? (this.meta?.page ?? 1) + 1 : 1;
                const params = new URLSearchParams({ status: this.filters.status, page: String(page) });

                if (this.filters.q.trim() !== '') {
                    params.set('q', this.filters.q.trim());
                }

                const json = await requestJson(`${this.endpoints.rows}?${params}`);

                this.rows = more ? [...this.rows, ...json.data] : json.data;
                this.meta = json.meta;
            } catch (error) {
                notify(error?.message || d.load_failed, false);
            } finally {
                this.listLoading = false;
            }
        },

        hasMore() {
            return !!this.meta && this.meta.page < this.meta.pages;
        },

        whoLine(row) {
            const parts = [];

            if (row.resolver) {
                parts.push(d.list.found_by.replace(':account', this.phone(row.resolver)));
            }

            if (row.account && row.account_id !== row.resolver_id) {
                parts.push(d.list.added_by.replace(':account', this.phone(row.account)) + (row.re_resolved ? ` (${d.list.re_resolved})` : ''));
            }

            return parts.join(' · ') || d.list.unknown;
        },

        telegramLine(row) {
            return [row.telegram_name, row.username ? '@' + row.username : null].filter(Boolean).join(' · ') || `ID ${row.telegram_user_id ?? '—'}`;
        },

        whenLine(row) {
            const parts = [];

            if (row.attempted_at) {
                parts.push(this.time(row.attempted_at));
            }

            if (row.attempts > 1) {
                parts.push(d.list.tried.replace(':n', row.attempts));
            }

            parts.push(d.list.source[row.source] ?? row.source);

            return parts.join(' · ');
        },
    };
}
