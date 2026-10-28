/*
|--------------------------------------------------------------------------
| Contact sync: the settings page
|--------------------------------------------------------------------------
|
| When a run starts, how many contacts each account may add a day, how
| fast, and under which name the contacts are saved. Read and
| written through ContactSyncSettingsController; a run reads them fresh when
| it starts, so a change applies from the next run.
|
*/

import { requestJson } from './monitoring';

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

/** What the form holds; compared as text, the inputs hand back strings. */
const FIELDS = ['enabled', 'schedule_at', 'daily_limit', 'skip_usernames', 'delay_min', 'delay_max', 'nick_base', 'excluded_accounts'];

export function contactSettingsPage(config) {
    const s = config.translations;

    return {
        s,

        endpoint: config.endpoint,

        form: null,

        stored: null,

        defaults: null,

        limits: {},

        /** The authorized accounts the exclusion list is chosen from. */
        accounts: [],

        savedInPanel: false,

        loading: false,

        busy: false,

        error: null,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                this.apply(await requestJson(this.endpoint));
            } catch (error) {
                this.error = error?.message || s.load_failed;
            } finally {
                this.loading = false;
            }
        },

        apply(json) {
            this.stored = { ...json.data };
            this.form = { ...json.data };
            this.defaults = json.defaults;
            this.limits = json.limits ?? {};
            this.accounts = json.accounts ?? [];
            this.savedInPanel = !!json.saved;
        },

        get dirty() {
            return this.form !== null
                && FIELDS.some((key) => String(this.form[key]) !== String(this.stored[key]));
        },

        /** The listener account is always out; the rest is the reader's choice. */
        isExcluded(account) {
            return account.is_primary || this.form.excluded_accounts.includes(account.id);
        },

        toggleExcluded(account) {
            if (account.is_primary) {
                return;
            }

            const set = new Set(this.form.excluded_accounts);

            set.has(account.id) ? set.delete(account.id) : set.add(account.id);

            this.form.excluded_accounts = [...set].sort((a, b) => a - b);
        },

        takingPart() {
            return this.accounts.filter((account) => !this.isExcluded(account)).length;
        },

        /** "Driver, Driver-1, Driver-2 …" - what the contacts will be called. */
        nickPreview() {
            const base = String(this.form?.nick_base ?? '').trim();

            return base === '' ? '' : `${base}, ${base}-1, ${base}-2 …`;
        },

        async save() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoint, 'PUT', {
                    ...this.form,
                    daily_limit: Number(this.form.daily_limit),
                    delay_min: Number(this.form.delay_min),
                    delay_max: Number(this.form.delay_max),
                });

                this.apply(json);
                notify(json.message);
            } catch (error) {
                notify(error?.message || s.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async reset() {
            if (this.busy || !window.confirm(s.reset_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoint, 'DELETE');

                this.apply(json);
                notify(json.message);
            } catch (error) {
                notify(error?.message || s.load_failed, false);
            } finally {
                this.busy = false;
            }
        },
    };
}
