/*
|--------------------------------------------------------------------------
| Group invites: the settings
|--------------------------------------------------------------------------
|
| When the daily run starts, how many people one call adds and how many
| calls an account makes a day, the pause between them, who is invited
| (everybody not refused, those added before first - or only those), and
| the @username lookups. Which accounts take part is set on their page.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

const FIELDS = ['enabled', 'schedule_at', 'batch_size', 'batches_per_day', 'delay_min', 'delay_max', 'use_usernames', 'usernames_per_day', 'audience'];
const NUMBERS = ['batch_size', 'batches_per_day', 'delay_min', 'delay_max', 'usernames_per_day'];

export function groupInvitesSettingsPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),

        t,
        endpoints: config.endpoints,

        loading: false,
        busy: false,
        error: null,
        form: null,
        stored: null,
        defaults: null,
        limits: {},
        saved: false,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.show);
                this.limits = json.limits ?? {};
                this.defaults = json.defaults ?? null;
                this.saved = !!json.saved;
                this.fill(json.settings);
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
        },

        fill(settings) {
            const values = Object.fromEntries(FIELDS.map((key) => [key, settings[key]]));

            this.stored = { ...values };
            this.form = { ...values };
        },

        get dirty() {
            return this.form !== null && FIELDS.some((key) => String(this.form[key]) !== String(this.stored[key]));
        },

        perAccount() {
            const size = Number(this.form?.batch_size ?? 0);
            const times = Number(this.form?.batches_per_day ?? 0);

            return size > 0 && times > 0 ? size * times : 0;
        },

        audiences() {
            return Object.entries(t.settings.audiences).map(([value, label]) => ({ value, label, hint: t.settings.audience_hints[value] }));
        },

        useDefaults() {
            if (this.defaults) {
                this.form = { ...this.form, ...Object.fromEntries(FIELDS.map((key) => [key, this.defaults[key]])) };
            }
        },

        async save() {
            if (this.busy || !this.form) {
                return;
            }

            this.busy = true;

            try {
                const payload = { ...this.form };

                NUMBERS.forEach((key) => {
                    payload[key] = Number(payload[key]);
                });

                const json = await requestJson(this.endpoints.update, 'PUT', payload);
                this.saved = true;
                this.fill(json.settings);
                notify(json.message);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },
    };
}
