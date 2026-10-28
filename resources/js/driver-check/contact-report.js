/*
|--------------------------------------------------------------------------
| Contact sync: the report page
|--------------------------------------------------------------------------
|
| Where a run is started and where it is read afterwards: the schedule and
| its state, the history of runs (one row each, opening into the accounts
| that took part and the groups they read), and the day-by-day totals.
|
| The history is a createListPage list, so it pages by scrolling like every
| other list. While a run is going the page looks again every few seconds.
|
*/

import { createListPage } from './list-page';
import { requestJson } from './monitoring';
import { EM_DASH, formatDate, formatNumber, formatPhone, formatRelative, initials, telegramHandle } from './format';

const POLL_MS = 4000;

/** After "Run again" the run needs a moment to appear: look this long. */
const WATCH_MS = 90000;

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const RUN_TONES = {
    ok: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    running: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    interrupted: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
};

const RUN_DOTS = {
    ok: 'bg-success-500',
    running: 'animate-pulse bg-blue-light-500',
    interrupted: 'bg-error-500',
};

const EVENT_TONES = {
    started: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    members: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    added: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    skipped: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    joined: 'bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/10 dark:text-blue-light-400',
    disabled: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    failed: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    flood: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    finished: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
};

export function contactReportPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        t,
        ui,

        /* The history of runs is the list; the rest of the page is the report. */
        ...createListPage({
            endpoint: config.endpoints.runs,
            translations: t,
            defaults: { per_page: 15, page: 1 },
        }),

        endpoints: config.endpoints,

        events: config.events ?? {},

        report: null,

        reportError: null,

        reportLoading: false,

        timer: null,

        watchUntil: 0,

        tab: 'runs',

        /** Which group "Run again" is for; empty is all of them. */
        scope: '',

        starting: false,

        detailOpen: false,

        detail: null,

        detailLoading: false,

        detailError: null,

        logOpen: false,

        log: [],

        logPage: { current: 0, last: 1 },

        logLoading: false,

        dash: EM_DASH,

        init() {
            this.initList();
            this.loadReport();
        },

        destroy() {
            clearTimeout(this.timer);
        },

        /* The URL carries nothing here: no filters to share. */
        syncUrl() {},

        /*
        |----------------------------------------------------------------
        | The report
        |----------------------------------------------------------------
        */

        async loadReport() {
            clearTimeout(this.timer);

            this.reportLoading = true;
            this.reportError = null;

            try {
                this.report = (await requestJson(this.endpoints.report)).data;
            } catch (error) {
                this.reportError = error?.message || t.error;
            } finally {
                this.reportLoading = false;
            }

            /* A run in progress, or one just asked for, keeps the page looking. */
            if ((this.schedule?.running || Date.now() < this.watchUntil) && !document.hidden) {
                this.timer = setTimeout(() => this.tick(), POLL_MS);
            }
        },

        /** One look: the report, and the history unless the reader scrolled down it. */
        async tick() {
            if (!this.appended && !this.loading) {
                await this.load();
            }

            await this.loadReport();
        },

        get schedule() {
            return this.report?.schedule ?? null;
        },

        get days() {
            return this.report?.days ?? [];
        },

        get today() {
            return this.days[0] ?? null;
        },

        get groups() {
            return (this.report?.groups ?? []).filter((group) => group.is_active);
        },

        /** What the banner says: the one state that needs a person. */
        get tone() {
            const schedule = this.schedule;

            if (!schedule) {
                return 'muted';
            }

            if (schedule.overdue || schedule.status === 'interrupted') {
                return 'error';
            }

            if (schedule.status === 'running') {
                return 'waiting';
            }

            return schedule.status === 'ok' ? 'success' : 'muted';
        },

        bannerClass() {
            return {
                success: 'border-success-200 bg-success-25 dark:border-success-500/30 dark:bg-success-500/[0.07]',
                waiting: 'border-warning-200 bg-warning-25 dark:border-warning-500/30 dark:bg-warning-500/[0.07]',
                error: 'border-error-200 bg-error-25 dark:border-error-500/30 dark:bg-error-500/[0.07]',
                muted: 'border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]',
            }[this.tone];
        },

        dotClass() {
            return {
                success: 'text-success-500',
                waiting: 'text-warning-500',
                error: 'text-error-500',
                muted: 'text-gray-400',
            }[this.tone];
        },

        statusText() {
            return t.schedule.status[this.schedule?.status ?? 'never'];
        },

        /** A day with no run is a hole worth seeing, except today before the schedule time. */
        isHole(day, index) {
            return day.runs === 0 && !(index === 0 && !this.schedule?.overdue);
        },

        dayLabel(day, index) {
            const text = formatDate(`${day.date}T00:00:00`, false);

            return index === 0 ? `${text} · ${t.days.today}` : text;
        },

        /*
        |----------------------------------------------------------------
        | Starting a run
        |----------------------------------------------------------------
        */

        get busy() {
            return this.starting || !!this.schedule?.running;
        },

        async run() {
            if (this.busy) {
                return;
            }

            this.starting = true;

            try {
                const json = await requestJson(
                    this.endpoints.run,
                    'POST',
                    this.scope ? { group_id: Number(this.scope) } : null,
                );

                notify(json.message);

                this.watchUntil = Date.now() + WATCH_MS;
                this.tab = 'runs';

                await this.tick();
            } catch (error) {
                notify(error?.message || t.error, false);
            } finally {
                this.starting = false;
            }
        },

        /*
        |----------------------------------------------------------------
        | The history of runs
        |----------------------------------------------------------------
        */

        triggerLabel(run) {
            if (run.mode === 'members') {
                return t.runs.trigger_members;
            }

            return {
                schedule: t.runs.trigger_schedule,
                manual: t.runs.trigger_manual,
            }[run.trigger] ?? t.runs.trigger_unknown;
        },

        runLabel(status) {
            return {
                ok: t.runs.ok,
                running: t.runs.running_now,
                interrupted: t.runs.interrupted,
            }[status] ?? status;
        },

        runClass(status) {
            return RUN_TONES[status] ?? RUN_TONES.ok;
        },

        runDot(status) {
            return RUN_DOTS[status] ?? RUN_DOTS.ok;
        },

        duration(seconds) {
            if (seconds === null || seconds === undefined) {
                return EM_DASH;
            }

            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const rest = seconds % 60;

            if (hours > 0) {
                return `${hours} h ${minutes} min`;
            }

            return minutes > 0 ? `${minutes} min ${rest} s` : `${rest} s`;
        },

        /*
        |----------------------------------------------------------------
        | One run in detail
        |----------------------------------------------------------------
        */

        async openRun(row) {
            this.detailOpen = true;
            this.detail = null;
            this.detailError = null;
            this.detailLoading = true;
            this.logOpen = false;
            this.log = [];
            this.logPage = { current: 0, last: 1 };

            try {
                this.detail = (await requestJson(`${this.endpoints.runs}/${row.run_id}`)).data;
            } catch (error) {
                this.detailError = error?.message || t.detail.unavailable;
            } finally {
                this.detailLoading = false;
            }
        },

        closeRun() {
            this.detailOpen = false;
        },

        /** The run's own settings, in a sentence. */
        settingsLine() {
            const settings = this.detail?.run?.settings ?? {};

            return t.detail.settings_text
                .replace(':limit', settings.daily_limit ?? EM_DASH)
                .replace(':nick', settings.nick_base || t.detail.own_names);
        },

        async toggleLog() {
            this.logOpen = !this.logOpen;

            if (this.logOpen && this.log.length === 0) {
                await this.moreLog();
            }
        },

        async moreLog() {
            if (this.logLoading || !this.detail || this.logPage.current >= this.logPage.last) {
                return;
            }

            this.logLoading = true;

            try {
                const page = this.logPage.current + 1;
                const query = new URLSearchParams({ run_id: this.detail.run.run_id, per_page: 100, page });
                const json = await requestJson(`${this.endpoints.logs}?${query}`);

                /* The log endpoint is newest first; a run reads top to bottom. */
                this.log = this.log.concat(json.data);
                this.logPage = { current: json.current_page, last: json.last_page };
            } catch (error) {
                notify(error?.message || t.detail.unavailable, false);
            } finally {
                this.logLoading = false;
            }
        },

        eventLabel(event) {
            return this.events[event] ?? event;
        },

        eventClass(event) {
            return EVENT_TONES[event] ?? EVENT_TONES.members;
        },

        logContext(row) {
            const context = row.context ?? {};

            return context.contact_name
                ? context.contact_name
                : (context.username ? `@${context.username}` : (context.telegram_user_id ?? ''));
        },

        groupName(group) {
            return group?.title || group?.link || EM_DASH;
        },

        number: formatNumber,
        date: formatDate,
        relative: formatRelative,
        phone: formatPhone,
        initials,
        handle: telegramHandle,
    };
}
