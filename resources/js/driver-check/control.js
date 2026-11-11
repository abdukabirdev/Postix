/*
|--------------------------------------------------------------------------
| The control desk
|--------------------------------------------------------------------------
|
| Every start and stop of the panel on one page (Monitoring > Control). Most
| of its cards are the sections' own components - the same data and the same
| buttons as on their pages. The two below are only what the desk adds:
|
|   - the queue worker in one line (the queue page's own component also
|     loads the job lists and keeps its filters in the URL);
|   - the Join group switch, which otherwise lives inside the join settings
|     form: it reads the saved settings and saves them back with only
|     `enabled` changed, through the same endpoint.
|
*/

import { requestJson } from './monitoring';
import { formatNumber, formatRelative } from './format';

const POLL_MS = 10000;

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

export function controlQueueCard(config) {
    return {
        t: config.translations,

        summary: null,

        error: null,

        timer: null,

        init() {
            this.load();
        },

        destroy() {
            clearTimeout(this.timer);
        },

        async load() {
            clearTimeout(this.timer);

            try {
                this.summary = (await requestJson(config.endpoints.summary + '?part=core')).data;
                this.error = null;
            } catch (error) {
                this.error = error?.message || this.t.errors?.load_failed || '';
            } finally {
                this.timer = setTimeout(() => this.load(), POLL_MS);
            }
        },

        /** alive | dead | never - the same three states the queue page shows. */
        state() {
            const worker = this.summary?.worker;

            if (!worker?.pulse_at) {
                return 'never';
            }

            return worker.alive ? 'alive' : 'dead';
        },

        dot() {
            return { alive: 'text-success-500', dead: 'text-error-500', never: 'text-warning-500' }[this.state()];
        },

        main() {
            return (this.summary?.queues ?? []).find((queue) => queue.is_main) ?? null;
        },

        failed() {
            return Number(this.summary?.failed?.total ?? 0);
        },

        number(value) {
            return formatNumber(value ?? 0);
        },

        relative(value) {
            return formatRelative(value);
        },
    };
}

export function controlJoinSwitch(config) {
    return {
        switching: false,

        /**
         * Turn Join group on or off. `after` reloads the card around it.
         */
        async setEnabled(on, after = null) {
            if (this.switching || (!on && !window.confirm(config.translations.off_confirm))) {
                return;
            }

            this.switching = true;

            try {
                const saved = await requestJson(config.endpoints.settings);
                const json = await requestJson(config.endpoints.settings, 'PUT', { ...saved.data, enabled: on });

                notify(json.message || config.translations.saved);

                if (typeof after === 'function') {
                    await after();
                }
            } catch (error) {
                notify(error?.message || config.translations.failed, false);
            } finally {
                this.switching = false;
            }
        },
    };
}
