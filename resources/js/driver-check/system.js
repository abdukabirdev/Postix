/*
|--------------------------------------------------------------------------
| How it works
|--------------------------------------------------------------------------
|
| Every independent logic of the Telegram side, which account does it right
| now and whether that account's process is up, and the problems told to the
| developer lately. Read only; one request, refreshed in the background.
|
*/

import { formatDate, formatRelative } from './format';

export function systemPage(config) {
    const t = config.translations;

    return {
        ui: config.ui,

        translations: t,

        endpoints: config.endpoints,

        loading: false,

        error: null,

        logics: [],

        listeners: [],

        alerts: { configured: false, kinds: [], recent: [] },

        /* The alerts page narrows what it reads: one kind, words, how many. */
        filters: { kind: '', search: '', limit: 50 },

        opened: {},

        init() {
            this.load();

            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.load(true);
                }
            }, 15000);
        },

        /* What the page asks for: the map page the logics, the alerts page the problems - not both. */
        url() {
            const params = new URLSearchParams({ part: config.part === 'alerts' ? 'alerts' : 'map' });

            if (config.part === 'alerts') {
                params.set('limit', String(this.filters.limit));

                if (this.filters.kind) {
                    params.set('kind', this.filters.kind);
                }

                if (this.filters.search.trim() !== '') {
                    params.set('search', this.filters.search.trim());
                }
            }

            return `${this.endpoints.index}?${params}`;
        },

        applyFilters() {
            this.load();
        },

        clearSearch() {
            this.filters.search = '';
            this.load();
        },

        toggle(alert) {
            this.opened[alert.id] = !this.opened[alert.id];
        },

        async load(silent = false) {
            if (!silent) {
                this.loading = true;
            }

            this.error = null;

            try {
                const response = await fetch(this.url(), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const json = await response.json();

                if (config.part !== 'alerts') {
                    this.logics = Array.isArray(json.logics) ? json.logics : [];
                    this.listeners = Array.isArray(json.listeners) ? json.listeners : [];
                }

                if (json.alerts) {
                    this.alerts = json.alerts;
                }
            } catch (error) {
                this.error = error?.message || t.errors.load;
            } finally {
                this.loading = false;
            }
        },

        /* The shared empty state offers a filter reset; this page has none. */
        hasActiveFilters() {
            return false;
        },

        resetFilters() {},

        /** The logics an admin can hand to another account. */
        movableCount() {
            return this.logics.filter((logic) => logic.movable).length;
        },

        logicName(logic) {
            return t.logics[logic.key]?.name || logic.key;
        },

        logicWhat(logic) {
            return t.logics[logic.key]?.what || '';
        },

        logicNote(logic) {
            return t.logics[logic.key]?.note || '';
        },

        dotClass(running) {
            return running ? 'bg-success-500' : 'bg-error-500';
        },

        listenerState(row) {
            if (!row.is_enabled) {
                return t.state.off;
            }

            return row.running ? t.state.running : t.state.stopped;
        },

        date(value) {
            return formatDate(value);
        },

        relative(value) {
            return formatRelative(value);
        },
    };
}
