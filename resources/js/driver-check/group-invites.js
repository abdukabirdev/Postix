/*
|--------------------------------------------------------------------------
| Group invites
|--------------------------------------------------------------------------
|
| Groups the accounts invite their own contacts into - and people with a
| @username once those run out - how many at a time and how many times a
| day, how each account stands after a flood, and where each person ended:
| added, could be but was not, cannot be (junk), already in, unchecked.
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
        totals: { invited: 0, not_added: 0, already: 0, sent: 0, failed: 0, pending: 0, today: 0, batches_today: 0, by_username: 0, refused: 0 },

        link: '',
        status: '',
        groupId: '',
        search: '',
        results: [],
        meta: { current_page: 1, last_page: 1, total: 0 },

        /* What the run is doing now (or how the last one ended), and when the page heard it. */
        now: null,
        fetchedAt: Date.now(),
        tick: Date.now(),

        logRuns: [],
        logRunId: '',
        logRunPicked: false,
        logLines: [],
        logFilter: 'all',

        poll: null,
        ticker: null,

        init() {
            this.load();
            this.loadLogs();
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
                const wasRunning = this.running;
                this.running = !!json.running;
                this.now = json.now ?? null;
                this.fetchedAt = Date.now();
                this.tick = this.fetchedAt;

                /* Just ended: the last lines and results once more. */
                if (wasRunning && !this.running) {
                    this.loadLogs();
                    this.loadResults(1);
                }

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
                this.logRunPicked = false;
                this.startPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async check() {
            if (this.busy || this.running) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.check, 'POST', {});
                notify(json.message);
                this.running = true;
                this.logRunPicked = false;
                this.startPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async stop() {
            if (this.busy || !this.running || !window.confirm(t.stop_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.stop, 'POST', {});
                notify(json.message);
                await this.load();
                await this.loadLogs();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        /*
        |--------------------------------------------------------------
        | Now: the step, where, how far, until when, how long it runs
        |--------------------------------------------------------------
        */

        modeLabel(now) {
            return now?.check_only ? t.now.mode_check : t.now.mode_invite;
        },

        doingLabel() {
            /* The lock is held, the run has not written anything yet. */
            if (!this.now?.running) {
                return t.now.doing.starting;
            }

            const step = this.now.step || {};

            return (t.now.doing[this.now.doing] ?? this.now.doing ?? '')
                .replace(':done', this.number(step.done ?? 0))
                .replace(':size', this.number(step.size ?? 0));
        },

        whereLabel() {
            return this.now?.running ? [this.now.account, this.now.group].filter(Boolean).join(' · ') : '';
        },

        /* Seconds until the step's "until", counted down by the page's own clock. */
        secondsLeft() {
            const until = this.now?.running ? this.now.step?.until : null;

            if (!until) {
                return null;
            }

            return Math.max(0, Math.round((new Date(until).getTime() - this.tick) / 1000));
        },

        untilLabel() {
            const left = this.secondsLeft();

            return left === null ? '' : t.now.left.replace(':time', this.duration(left));
        },

        progress() {
            const step = this.now?.running ? this.now.step || {} : {};

            return step.size > 0 ? Math.min(100, Math.round((100 * (step.done ?? 0)) / step.size)) : null;
        },

        /* Seconds since the run last wrote what it does. */
        silentFor() {
            if (this.now?.silent_for === null || this.now?.silent_for === undefined) {
                return null;
            }

            return this.now.silent_for + Math.round((this.tick - this.fetchedAt) / 1000);
        },

        /* Three minutes without a word outside a known wait: it hangs. */
        silent() {
            const silent = this.silentFor();

            return this.running && this.now?.running && silent !== null && silent > 180 && !(this.secondsLeft() > 0);
        },

        silentLabel() {
            return t.now.silent
                .replace(':time', this.duration(this.silentFor() ?? 0))
                .replace(':pid', this.now?.pid ?? '?');
        },

        clockLine() {
            if (!this.now?.running) {
                return '';
            }

            const parts = [t.now.running_for.replace(':time', this.duration(this.secondsSince(this.now.started_at)))];
            const silent = this.silentFor();

            if (silent !== null) {
                parts.push(t.now.heartbeat.replace(':time', this.duration(silent)));
            }

            if (this.now.pid) {
                parts.push(`PID ${this.now.pid}`);
            }

            return parts.join(' · ');
        },

        lastRunLabel() {
            if (!this.now) {
                return t.now.never;
            }

            const parts = [t.now.last.replace(':start', this.time(this.now.started_at)), this.modeLabel(this.now)];

            if (this.now.finished_at) {
                const took = Math.max(0, Math.round((new Date(this.now.finished_at) - new Date(this.now.started_at)) / 1000));
                parts.push(t.now.took.replace(':time', this.duration(took)));
            } else {
                parts.push(t.now.broken);
            }

            if (this.now.stopped) {
                parts.push(t.now.last_stopped);
            }

            if (this.now.totals) {
                parts.push(this.summaryText(this.now.totals));
            }

            return parts.join(' · ');
        },

        /*
        |--------------------------------------------------------------
        | The run log, in words
        |--------------------------------------------------------------
        */

        async loadLogs() {
            const params = new URLSearchParams();

            if (this.logRunPicked && this.logRunId) {
                params.set('run_id', this.logRunId);
            }

            try {
                const json = await requestJson(`${this.endpoints.logs}?${params}`);
                this.logRuns = json.runs ?? [];
                this.logLines = json.lines ?? [];
                this.logRunId = json.run_id ?? '';
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        logFilters() {
            return Object.entries(t.log.filters).map(([value, label]) => ({ value, label }));
        },

        setLogFilter(value) {
            this.logFilter = value;
        },

        shownLines() {
            if (this.logFilter === 'work') {
                return this.logLines.filter((line) => ['batch', 'check', 'members'].includes(line.event));
            }

            if (this.logFilter === 'problems') {
                return this.logLines.filter((line) => ['bad', 'warn'].includes(this.lineTone(line)));
            }

            return this.logLines;
        },

        runLabel(run) {
            const mode = run.check_only ? t.now.mode_check : t.now.mode_invite;
            let label = t.log.run.replace(':date', this.time(run.started_at)).replace(':mode', mode);

            if (!run.finished_at && run.run_id === this.logRuns[0]?.run_id && this.running) {
                label += ` · ${t.log.run_running}`;
            } else if (run.stopped) {
                label += ` · ${t.log.run_stopped}`;
            }

            return label;
        },

        /* added 3 · not added 10 · cannot 2 ... - only what is not zero. */
        summaryText(counts) {
            const parts = ['invited', 'not_added', 'failed', 'already', 'sent']
                .filter((key) => Number(counts?.[key] ?? 0) > 0)
                .map((key) => t.log.summary[key].replace(':n', this.number(counts[key])));

            return parts.length ? parts.join(' · ') : t.log.summary.nothing;
        },

        lineText(line) {
            const c = line.context || {};
            const e = t.log.events;
            const trigger = c.trigger === 'schedule' ? t.now.trigger_schedule : t.now.trigger_manual;

            switch (line.event) {
                case 'started':
                    return (c.check_only ? e.started_check : e.started).replace(':trigger', trigger);
                case 'finished':
                    if (c.reason === 'no_groups') {
                        return e.no_groups;
                    }

                    return (c.stopped ? e.finished_stopped : e.finished).replace(':summary', this.summaryText(c));
                case 'skipped':
                    if (c.reason === 'no_accounts') {
                        return e.no_accounts;
                    }

                    if (c.reason === 'denied') {
                        return e.denied.replace(':reason', this.errorLabel(c.rpc));
                    }

                    if (c.reason === 'group_off') {
                        return e.group_off.replace(':reason', this.errorLabel(c.rpc, true));
                    }

                    if (c.reason === 'group_left') {
                        return e.group_left.replace(':n', c.accounts ?? '?').replace(':reason', this.errorLabel(c.rpc));
                    }

                    return line.message;
                case 'batch': {
                    const error = c.error ? String(c.error) : null;

                    if (error && error.endsWith(', split')) {
                        return e.batch_split.replace(':size', c.size ?? '?').replace(':error', this.errorLabel(error.replace(', split', '')));
                    }

                    const text = e.batch.replace(':size', c.size ?? '?').replace(':summary', this.summaryText(c));

                    return error ? `${text} · ${this.errorLabel(error)}` : text;
                }
                case 'check':
                    return e.check
                        .replace(':done', this.number(c.done ?? c.size ?? 0))
                        .replace(':of', this.number(c.of ?? c.size ?? 0))
                        .replace(':summary', this.summaryText(c));
                case 'members': {
                    const grew = c.grew === null || c.grew === undefined ? '' : ` (${c.grew > 0 ? '+' : ''}${c.grew})`;

                    return e.members.replace(':before', c.before ?? '?').replace(':after', c.after ?? '?') + grew;
                }
                case 'wait':
                    return e.wait.replace(':n', c.seconds ?? '?');
                case 'flood':
                    return e.flood.replace(':time', this.time(c.until)).replace(':reason', this.errorLabel(c.rpc));
                case 'rest':
                    return e.rest.replace(':time', this.time(c.until));
                case 'disabled':
                    return e.disabled.replace(':reason', this.errorLabel(c.rpc || line.message));
                case 'failed':
                    return e.failed.replace(':reason', line.message);
                case 'stop':
                    return e.stop;
                default:
                    return line.message;
            }
        },

        lineTone(line) {
            const c = line.context || {};

            switch (line.event) {
                case 'failed':
                case 'disabled':
                    return 'bad';
                case 'skipped':
                    return c.reason === 'group_off' ? 'bad' : 'warn';
                case 'flood':
                case 'rest':
                case 'wait':
                case 'stop':
                    return 'warn';
                case 'batch':
                case 'check':
                    if (Number(c.invited ?? 0) > 0) {
                        return 'ok';
                    }

                    return Number(c.not_added ?? 0) > 0 || (line.event === 'batch' && c.error) ? 'warn' : 'muted';
                case 'members':
                    return Number(c.grew ?? 0) > 0 ? 'ok' : 'muted';
                case 'finished':
                    return c.stopped ? 'warn' : 'ok';
                default:
                    return 'muted';
            }
        },

        toneClass(tone) {
            return {
                ok: 'text-success-500',
                warn: 'text-warning-500',
                bad: 'text-error-500',
            }[tone] ?? 'text-gray-300 dark:text-gray-600';
        },

        /*
        |--------------------------------------------------------------
        | Time
        |--------------------------------------------------------------
        */

        secondsSince(iso) {
            const at = new Date(iso).getTime();

            return Number.isNaN(at) ? 0 : Math.max(0, Math.round((this.tick - at) / 1000));
        },

        /* 45 s, 12 min, 1 h 5 min */
        duration(seconds) {
            const u = t.now.units;
            const s = Math.max(0, Math.round(Number(seconds) || 0));

            if (s < 60) {
                return `${s} ${u.s}`;
            }

            if (s < 3600) {
                return `${Math.floor(s / 60)} ${u.m}`;
            }

            const m = Math.floor((s % 3600) / 60);

            return `${Math.floor(s / 3600)} ${u.h}` + (m ? ` ${m} ${u.m}` : '');
        },

        /* HH:MM:SS for a log line. */
        clock(iso) {
            const date = new Date(iso);

            if (Number.isNaN(date.getTime())) {
                return '';
            }

            const pad = (n) => String(n).padStart(2, '0');

            return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        },

        /*
         * The member count when the last run came to the group and when it
         * was done; only the first while the run is still in it.
         */
        membersLine(group) {
            const before = group.members_before === null ? '?' : this.number(group.members_before);
            const after = group.members_after === null ? '…' : this.number(group.members_after);

            return t.groups.members.replace(':before', before).replace(':after', after);
        },

        membersGrew(group) {
            return group.members_before === null || group.members_after === null
                ? null
                : group.members_after - group.members_before;
        },

        /*
         * The retry clears the bucket the list is filtered to, both when it
         * shows everybody.
         */
        retryBucket() {
            return ['failed', 'not_added'].includes(this.status) ? this.status : null;
        },

        retryable() {
            const bucket = this.retryBucket();

            return bucket ? this.totals[bucket] : this.totals.failed + this.totals.not_added;
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
            const bucket = this.retryBucket();

            if (!window.confirm(bucket ? t.results[`retry_confirm_${bucket}`] : t.results.retry_confirm)) {
                return;
            }

            try {
                const json = await requestJson(this.endpoints.retry, 'POST', bucket ? { status: bucket } : {});
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

            if (this.search.trim() !== '') {
                params.set('search', this.search.trim());
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

        statusClass(status) {
            return {
                invited: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
                not_added: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
                failed: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
            }[status] ?? 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300';
        },

        /*
         * Telegram's code in words; a group's own @name has its own text, a
         * check that could not be made says why.
         */
        errorLabel(code, forGroup = false) {
            if (!code) {
                return '';
            }

            const wait = /^FLOOD_(?:PREMIUM_)?WAIT_(\d+)/.exec(code);

            if (wait) {
                return t.errors.flood_wait.replace(':n', wait[1]);
            }

            if (code.startsWith('CHECK_') && !t.errors[code]) {
                return t.errors.check_failed.replace(':reason', this.errorLabel(code.slice(6)));
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
                this.loadLogs();

                if ((this.meta.current_page || 1) === 1) {
                    this.loadResults(1);
                }
            }, 4000);

            /* The countdowns and "running for" move every second between polls. */
            this.ticker = setInterval(() => {
                this.tick = Date.now();
            }, 1000);
        },

        stopPoll() {
            if (this.poll) {
                clearInterval(this.poll);
                this.poll = null;
            }

            if (this.ticker) {
                clearInterval(this.ticker);
                this.ticker = null;
            }
        },
    };
}
