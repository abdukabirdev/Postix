/*
|--------------------------------------------------------------------------
| Processes of all the accounts
|--------------------------------------------------------------------------
|
| The processes page: every account that runs (Driver Check and the
| listeners are the same kind of thing here) with its state and the reason it
| does not run, and the buttons that act on all of them or on one - start,
| restart, stop. Stop and restart of everything ask first. Refreshed in the
| background.
|
*/

import { formatDate, formatRelative } from './format';

const BANNERS = {
    success: 'border-success-200 bg-success-25 text-success-800 dark:border-success-500/30 dark:bg-success-500/[0.07] dark:text-success-300',
    waiting: 'border-warning-200 bg-warning-25 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/[0.07] dark:text-warning-300',
    error: 'border-error-200 bg-error-25 text-error-800 dark:border-error-500/30 dark:bg-error-500/[0.07] dark:text-error-300',
    muted: 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300',
};

const DOTS = {
    success: 'text-success-500',
    waiting: 'text-warning-500',
    error: 'text-error-500',
    muted: 'text-gray-400',
};

export function listenerProcessesBlock(config) {
    const t = config.translations;

    return {
        t,

        states: config.states,

        endpoints: config.endpoints,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        status: { running: false, pid: null, queue_worker_alive: true, command: '', can_control: false },

        rows: [],

        loaded: false,

        loading: false,

        error: null,

        /* Which button is waiting: 'start' | 'restart' | 'stop' | an account key. */
        busy: null,

        confirmOpen: false,

        confirmAction: null,

        confirmError: null,

        init() {
            /* The Driver Check page has a component of its own inside: it does not show the listeners' list. */
            if (config.part === 'driver-check') {
                return;
            }

            this.load();

            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.load(true);
                }
            }, 10000);
        },

        async load(silent = false) {
            if (!silent) {
                this.loading = true;
            }

            try {
                const json = await this.request(this.endpoints.index);

                this.status = json.status || this.status;
                this.rows = Array.isArray(json.listeners) ? json.listeners : [];
                this.loaded = true;
                this.error = null;
            } catch (error) {
                this.error = error?.message;
            } finally {
                this.loading = false;
            }
        },

        async control(action, key = null) {
            if (this.busy) {
                return false;
            }

            this.busy = key || action;
            this.error = null;
            this.confirmError = null;

            try {
                const json = await this.request(`${this.endpoints.base}/${action}`, {
                    method: 'POST',
                    body: JSON.stringify(key ? { key } : {}),
                });

                this.status = json.status || this.status;

                this.notify(json.message || t.messages.done);

                /* The processes come and go within seconds of the request. */
                setTimeout(() => this.load(true), 4000);

                if (action === 'start') {
                    this.watchStart();
                }

                return true;
            } catch (error) {
                this.error = error?.message;
                this.confirmError = error?.message;

                if (!this.confirmOpen) {
                    this.notify(error?.message, false);
                }

                return false;
            } finally {
                this.busy = null;
            }
        },

        /**
         * The start goes through the queue worker; when nothing is running
         * after a while, it did not run: say so instead of leaving the page quiet.
         */
        watchStart() {
            setTimeout(async () => {
                await this.load(true);

                if (!this.status.running) {
                    this.error = t.start_timeout;
                }
            }, 20000);
        },

        /* Stop and restart of everything are asked about first, as on the old page. */
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
            if (await this.control(this.confirmAction)) {
                this.confirmOpen = false;
            }
        },

        /*
        |----------------------------------------------------------------
        | Presentation
        |----------------------------------------------------------------
        */

        /** running / waiting for the watchdog to start it / not running / switched off */
        stateOf(row) {
            if (!row.is_enabled) {
                return 'off';
            }

            if (row.running) {
                return 'running';
            }

            /* Something that blocks the start is not "starting". */
            if (row.blocker) {
                return 'stopped';
            }

            return row.can_restart ? 'starting' : 'stopped';
        },

        toneOf(row) {
            return { running: 'success', starting: 'waiting', stopped: 'error', off: 'muted' }[this.stateOf(row)];
        },

        dotClass(row) {
            return DOTS[this.toneOf(row)];
        },

        badgeClass(row) {
            return {
                success: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400',
                waiting: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400',
                error: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400',
                muted: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-300/60 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10',
            }[this.toneOf(row)];
        },

        /** How many of the accounts that should run do. */
        runningCount() {
            return this.rows.filter((row) => row.is_enabled && row.running).length;
        },

        enabledCount() {
            return this.rows.filter((row) => row.is_enabled).length;
        },

        /** all / partial / none / empty: what the banner says. */
        summary() {
            const total = this.enabledCount();
            const running = this.runningCount();

            if (!this.loaded) {
                return 'loading';
            }

            if (total === 0) {
                return 'empty';
            }

            if (running === total) {
                return 'all';
            }

            return running === 0 ? 'none' : 'partial';
        },

        summaryTone() {
            return { loading: 'muted', empty: 'muted', all: 'success', partial: 'waiting', none: 'error' }[this.summary()];
        },

        bannerClass() {
            return BANNERS[this.summaryTone()];
        },

        bannerDot() {
            return DOTS[this.summaryTone()];
        },

        summaryTitle() {
            return t.summary[this.summary()]?.title ?? '';
        },

        summaryHint() {
            return (t.summary[this.summary()]?.hint ?? '')
                .replace(':running', this.runningCount())
                .replace(':total', this.enabledCount());
        },

        /**
         * Why it does not run, when something can be said: what blocks the
         * start, how the last start ended, or that the watchdog has not even
         * tried (so it is probably an old one).
         */
        reason(row) {
            if (!row.is_enabled || row.running) {
                return '';
            }

            if (row.blocker) {
                return t.blockers[row.blocker] || row.blocker;
            }

            if (row.last_exit) {
                const said = row.last_exit.message || t.exit_code.replace(':code', row.last_exit.exit);
                const when = row.last_exit.at ? ` (${this.relative(row.last_exit.at)})` : '';

                return `${t.last_error}: ${said}${when}`;
            }

            if (!this.status.running) {
                return '';
            }

            return row.last_start
                ? t.tried.replace(':when', this.relative(row.last_start))
                : t.never_tried;
        },

        date(value) {
            return formatDate(value);
        },

        relative(value) {
            return formatRelative(value);
        },

        async request(url, options = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                ...options,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrf,
                },
            });

            let json = {};

            try {
                json = await response.json();
            } catch (_) {
                json = {};
            }

            if (!response.ok) {
                const error = new Error(json.message || `HTTP ${response.status}`);

                if (json.status) {
                    this.status = json.status;
                }

                throw error;
            }

            return json;
        },

        notify(message, success = true) {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message, success },
            }));
        },
    };
}
