/*
|--------------------------------------------------------------------------
| Monitoring: watchdog and queue
|--------------------------------------------------------------------------
|
| Both pages show state that changes on its own - a process dies, the
| worker picks a job up - so they poll instead of waiting for a click.
|
*/

import { createListPage } from './list-page';
import {
    EM_DASH,
    formatDate,
    formatNumber,
    formatPhone,
    formatRelative,
    telegramHandle,
} from './format';

const WATCHDOG_POLL_MS = 5000;

const QUEUE_POLL_MS = 10000;

const CLASSES_POLL_MS = 30000;

const TONES = {
    success: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20',
    progress: 'bg-blue-light-50 text-blue-light-700 ring-1 ring-inset ring-blue-light-600/20 dark:bg-blue-light-500/10 dark:text-blue-light-400 dark:ring-blue-light-500/20',
    waiting: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/20',
    error: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/20',
    muted: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-300/60 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10',
};

const DOTS = {
    success: 'bg-success-500',
    progress: 'bg-blue-light-500 animate-pulse',
    waiting: 'bg-warning-500',
    error: 'bg-error-500',
    muted: 'bg-gray-400',
};

/**
 * Banner colours for a whole-page state.
 */
const BANNERS = {
    success: 'border-success-200 bg-success-25 text-success-800 dark:border-success-500/30 dark:bg-success-500/[0.07] dark:text-success-300',
    waiting: 'border-warning-200 bg-warning-25 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/[0.07] dark:text-warning-300',
    error: 'border-error-200 bg-error-25 text-error-800 dark:border-error-500/30 dark:bg-error-500/[0.07] dark:text-error-300',
    muted: 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300',
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

export async function requestJson(url, method = 'GET', body = null) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        cache: 'no-store',
        body: body === null ? null : JSON.stringify(body),
    });

    let json = {};

    try {
        json = await response.json();
    } catch (_) {
        json = {};
    }

    if (!response.ok) {
        throw new Error(json.message || `HTTP ${response.status}`);
    }

    return json;
}

function notify(message, success = true) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
}

/**
 * "2 d 3 h", "5 min 12 s" - the two biggest units are plenty.
 */
export function formatDuration(seconds, units) {
    if (seconds === null || seconds === undefined || seconds === '') {
        return EM_DASH;
    }

    let rest = Math.max(0, Math.round(Number(seconds)));

    const parts = [
        ['d', 86400],
        ['h', 3600],
        ['m', 60],
        ['s', 1],
    ]
        .map(([key, size]) => {
            const value = Math.floor(rest / size);

            rest -= value * size;

            return [key, value];
        })
        .filter(([, value]) => value > 0)
        .slice(0, 2)
        .map(([key, value]) => `${value} ${units[key]}`);

    return parts.length > 0 ? parts.join(' ') : `0 ${units.s}`;
}

/*
|--------------------------------------------------------------------------
| Watchdog
|--------------------------------------------------------------------------
*/

const WATCHDOG_STATE_TONES = {
    ok: 'success',
    unsupervised: 'waiting',
    restarting: 'waiting',
    down: 'error',
    misconfigured: 'error',
    unknown: 'muted',
};

export function watchdogPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        t,
        ui,

        endpoints: config.endpoints,

        sessionsUrl: config.sessionsUrl,

        queueUrl: config.queueUrl,

        status: null,

        loading: false,

        error: null,

        busy: null,

        confirmOpen: false,

        confirmAction: null,

        confirmError: null,

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
                this.timer = setTimeout(() => this.tickLoad(), WATCHDOG_POLL_MS);
            }
        },

        /* The timer's turn: a tab nobody looks at does not ask. */
        tickLoad() {
            if (document.visibilityState === 'visible') {
                this.load();
            } else {
                this.timer = setTimeout(() => this.tickLoad(), WATCHDOG_POLL_MS);
            }
        },

        async run(action) {
            if (this.busy) {
                return false;
            }

            this.busy = action;

            try {
                const json = await requestJson(this.endpoints[action], 'POST');

                if (json.data) {
                    this.status = json.data;
                }

                notify(json.message);

                return true;
            } catch (error) {
                this.confirmError = error?.message || t.errors.action;

                if (!this.confirmOpen) {
                    notify(this.confirmError, false);
                }

                return false;
            } finally {
                this.busy = null;
            }
        },

        start() {
            return this.run('start');
        },

        askConfirm(action) {
            this.confirmAction = action;
            this.confirmError = null;
            this.confirmOpen = true;
        },

        closeConfirm() {
            if (this.busy) {
                return;
            }

            this.confirmOpen = false;
        },

        async confirm() {
            if (await this.run(this.confirmAction)) {
                this.confirmOpen = false;

                /* The processes need a moment before the locks tell the new story. */
                setTimeout(() => this.load(), 1500);
            }
        },

        /*
        |----------------------------------------------------------------
        | Presentation
        |----------------------------------------------------------------
        */

        state() {
            return this.status?.state ?? 'unknown';
        },

        stateTone() {
            return WATCHDOG_STATE_TONES[this.state()] ?? 'muted';
        },

        bannerClass() {
            return BANNERS[this.stateTone()] ?? BANNERS.muted;
        },

        process(role) {
            return this.status?.processes?.[role] ?? { running: null, pid: null };
        },

        processTone(role) {
            const running = this.process(role).running;

            if (running === null) {
                return 'muted';
            }

            if (running) {
                return 'success';
            }

            /* A missing spare is normal; a missing watchdog or listener is not. */
            return role === 'spare' ? 'muted' : 'error';
        },

        processBadge(role) {
            return TONES[this.processTone(role)];
        },

        processDot(role) {
            return DOTS[this.processTone(role)];
        },

        processLabel(role) {
            const running = this.process(role).running;

            if (running === null) {
                return t.processes.unknown;
            }

            return running ? t.processes.running : t.processes.stopped;
        },

        account() {
            return this.status?.account ?? null;
        },

        accountName() {
            const account = this.account();

            return account?.name || formatPhone(account?.phone);
        },

        canRestart() {
            return !!this.status?.can_signal
                && this.process('watchdog').running === true
                && this.process('listener').running === true;
        },

        canStop() {
            return !!this.status?.can_signal
                && (this.process('watchdog').running === true || this.process('spare').running === true);
        },

        restartNotice() {
            return this.status?.restart_notice ?? null;
        },

        healthMarker() {
            return this.status?.health_marker ?? null;
        },

        number: formatNumber,
        date: formatDate,
        relative: formatRelative,
        phone: formatPhone,
        handle: telegramHandle,

        duration(seconds) {
            return formatDuration(seconds, ui.duration);
        },
    };
}

/*
|--------------------------------------------------------------------------
| Queue
|--------------------------------------------------------------------------
*/

const JOB_STATE_TONES = {
    ready: 'success',
    delayed: 'muted',
    reserved: 'progress',
    stuck: 'waiting',
};

export function queuePage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        t,
        ui,

        ...createListPage({
            endpoint: config.endpoints.jobs,

            translations: t,

            defaults: {
                tab: 'pending',
                search: '',
                queue: '',
                state: '',
                /* Newest first on both tabs. */
                direction: 'desc',
                per_page: 20,
                page: 1,
            },
        }),

        /*
         * One list, two sources: the tab decides which endpoint load()
         * reads, so paging, filters and the URL work the same on both.
         */
        get endpoint() {
            return this.filters?.tab === 'failed'
                ? config.endpoints.failed
                : config.endpoints.jobs;
        },

        set endpoint(_) {
            /* createListPage assigns it once; the getter above decides. */
        },

        endpoints: config.endpoints,

        summary: null,

        summaryError: null,

        summaryTimer: null,

        classesTimer: null,

        classesData: [],

        classesLoaded: false,

        busy: null,

        confirmOpen: false,

        confirm: { action: null, queue: null, uuid: null },

        confirmError: null,

        detailOpen: false,

        detail: null,

        detailLoading: false,

        statusLabels: t.state,

        init() {
            this.initList();
            this.loadSummary();
            this.loadClasses();
        },

        destroy() {
            clearTimeout(this.summaryTimer);
            clearTimeout(this.classesTimer);
        },

        /* What is waiting by job class reads every job's payload: apart from the rest, and less often. */
        async loadClasses() {
            clearTimeout(this.classesTimer);

            try {
                const json = await requestJson(`${this.endpoints.summary}?part=classes`);

                this.classesData = json.data?.classes ?? [];
                this.classesLoaded = true;
            } catch (error) {
                this.classesLoaded = this.classesLoaded || false;
            } finally {
                this.classesTimer = setTimeout(() => this.tickClasses(), CLASSES_POLL_MS);
            }
        },

        tickClasses() {
            if (document.visibilityState === 'visible') {
                this.loadClasses();
            } else {
                this.classesTimer = setTimeout(() => this.tickClasses(), CLASSES_POLL_MS);
            }
        },

        async loadSummary() {
            clearTimeout(this.summaryTimer);

            try {
                const json = await requestJson(`${this.endpoints.summary}?part=core`);

                this.summary = json.data;
                this.summaryError = null;
            } catch (error) {
                this.summaryError = error?.message || t.errors.load_failed;
            } finally {
                this.summaryTimer = setTimeout(() => this.tickSummary(), QUEUE_POLL_MS);
            }
        },

        /* The timer's turn: a tab nobody looks at does not ask. */
        tickSummary() {
            if (document.visibilityState === 'visible') {
                this.loadSummary();
            } else {
                this.summaryTimer = setTimeout(() => this.tickSummary(), QUEUE_POLL_MS);
            }
        },

        refreshAll() {
            this.loadSummary();
            this.load();
        },

        switchTab(tab) {
            if (this.filters.tab === tab) {
                return;
            }

            this.filters.tab = tab;
            this.filters.state = '';

            this.applyFilters();
        },

        isFailedTab() {
            return this.filters.tab === 'failed';
        },

        /*
        |----------------------------------------------------------------
        | Summary helpers
        |----------------------------------------------------------------
        */

        worker() {
            return this.summary?.worker ?? null;
        },

        workerState() {
            const worker = this.worker();

            if (!worker?.pulse_at) {
                return 'never';
            }

            return worker.alive ? 'alive' : 'dead';
        },

        workerBanner() {
            return {
                alive: BANNERS.success,
                dead: BANNERS.error,
                never: BANNERS.waiting,
            }[this.workerState()];
        },

        queues() {
            return this.summary?.queues ?? [];
        },

        mainQueue() {
            return this.queues().find((queue) => queue.is_main) ?? {
                ready: 0, delayed: 0, reserved: 0, stuck: 0, total: 0,
            };
        },

        classes() {
            return this.classesData;
        },

        failedTotal() {
            return Number(this.summary?.failed?.total ?? 0);
        },

        /** A queue nobody runs, with something waiting in it. */
        isOrphan(queue) {
            return !queue.is_main && (queue.listened === false || queue.listened === null) && queue.total > 0;
        },

        /*
        |----------------------------------------------------------------
        | Rows
        |----------------------------------------------------------------
        */

        stateClass(row) {
            return TONES[JOB_STATE_TONES[row.state] ?? 'muted'];
        },

        stateDot(row) {
            return DOTS[JOB_STATE_TONES[row.state] ?? 'muted'];
        },

        attempts(row) {
            return row.max_tries ? `${row.attempts} / ${row.max_tries}` : String(row.attempts);
        },

        /*
        |----------------------------------------------------------------
        | Actions
        |----------------------------------------------------------------
        */

        askConfirm(action, extra = {}) {
            this.confirm = { action, queue: null, uuid: null, ...extra };
            this.confirmError = null;
            this.detailOpen = false;
            this.confirmOpen = true;
        },

        closeConfirm() {
            if (this.busy) {
                return;
            }

            this.confirmOpen = false;
        },

        confirmTitle() {
            return {
                move: t.confirm.move_title,
                retry_all: t.confirm.retry_all_title,
                delete: t.confirm.delete_title,
                flush: t.confirm.flush_title,
            }[this.confirm.action] ?? '';
        },

        confirmText() {
            return {
                move: t.confirm.move_text.replace(':queue', this.confirm.queue ?? ''),
                retry_all: t.confirm.retry_all_text,
                delete: t.confirm.delete_text,
                flush: t.confirm.flush_text,
            }[this.confirm.action] ?? '';
        },

        confirmDanger() {
            return this.confirm.action === 'delete' || this.confirm.action === 'flush';
        },

        async perform(key, url, method, body = null) {
            if (this.busy) {
                return false;
            }

            this.busy = key;

            try {
                const json = await requestJson(url, method, body);

                notify(json.message);

                this.refreshAll();

                return true;
            } catch (error) {
                const message = error?.message || t.errors.action;

                if (this.confirmOpen) {
                    this.confirmError = message;
                } else {
                    notify(message, false);
                }

                return false;
            } finally {
                this.busy = null;
            }
        },

        async runConfirmed() {
            const { action, queue, uuid } = this.confirm;

            const request = {
                move: () => this.perform('move', this.endpoints.move, 'POST', { queue }),
                retry_all: () => this.perform('retry_all', this.endpoints.retryAll, 'POST'),
                delete: () => this.perform(`delete-${uuid}`, this.failedUrl(uuid), 'DELETE'),
                flush: () => this.perform('flush', this.endpoints.flush, 'DELETE'),
            }[action];

            if (request && await request()) {
                this.confirmOpen = false;
            }
        },

        failedUrl(uuid, suffix = '') {
            return `${this.endpoints.failedBase}/${encodeURIComponent(uuid)}${suffix}`;
        },

        retry(row) {
            return this.perform(`retry-${row.uuid}`, this.failedUrl(row.uuid, '/retry'), 'POST')
                .then((done) => {
                    if (done) {
                        this.detailOpen = false;
                    }
                });
        },

        async openDetail(row) {
            this.detail = { ...row, exception: null };
            this.detailOpen = true;
            this.detailLoading = true;

            try {
                const json = await requestJson(this.failedUrl(row.uuid));

                this.detail = json.data;
            } catch (error) {
                this.detail = { ...row, exception: error?.message || t.errors.load_failed };
            } finally {
                this.detailLoading = false;
            }
        },

        closeDetail() {
            this.detailOpen = false;
        },

        duration(seconds) {
            return formatDuration(seconds, ui.duration);
        },
    };
}
