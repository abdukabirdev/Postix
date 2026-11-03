/*
|--------------------------------------------------------------------------
| Listeners
|--------------------------------------------------------------------------
|
| The accounts that run their own event loop next to Driver Check (CEO,
| Head Operation ...): one row each, with the account, what it does (the
| duties) and whether its process is up. A handful of rows, one request.
|
*/

import { formatDate, formatNumber, formatRelative } from './format';

const emptyForm = () => ({
    id: null,
    key: '',
    name: '',
    telegram_account_id: '',
    is_enabled: true,
    duties: [],
});

export function listenersPage(config) {
    const t = config.translations;

    return {
        ui: config.ui,

        translations: t,

        endpoints: config.endpoints,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        loading: false,

        error: null,

        rows: [],

        accounts: [],

        duties: [],

        /* Who does what: { duties: [{duty, owner, saved}], options: [{key, name, running}] } */
        owners: { duties: [], roles: [], options: [] },

        /* The select values: '' is Driver Check. */
        ownerForm: {},

        /* Penalties by role: { sales: '', operation: '_dc' } - '' follows "Penalties", '_dc' is Driver Check. */
        roleForm: {},

        savingOwners: false,

        ownersError: null,

        formOpen: false,

        saving: false,

        formError: null,

        formErrors: {},

        form: emptyForm(),

        confirmOpen: false,

        deleting: false,

        confirmError: null,

        init() {
            this.load();

            /* Whether the processes are up changes without a click. */
            setInterval(() => {
                if (!this.formOpen && !this.confirmOpen) {
                    this.load(true);
                }
            }, 15000);
        },

        async load(silent = false) {
            if (!silent) {
                this.loading = true;
            }

            this.error = null;

            try {
                const json = await this.request(this.endpoints.index);

                this.rows = Array.isArray(json.listeners) ? json.listeners : [];
                this.accounts = Array.isArray(json.accounts) ? json.accounts : [];
                this.duties = Array.isArray(json.duties) ? json.duties : [];

                this.applyOwners(json.owners);
            } catch (error) {
                this.error = error?.message || t.errors.load;
            } finally {
                this.loading = false;
            }
        },

        /*
        |----------------------------------------------------------------
        | Who does what
        |----------------------------------------------------------------
        */

        applyOwners(owners) {
            this.owners = {
                duties: Array.isArray(owners?.duties) ? owners.duties : [],
                roles: Array.isArray(owners?.roles) ? owners.roles : [],
                options: Array.isArray(owners?.options) ? owners.options : [],
            };

            /* The saved choice, even when its listener is off for the moment: that is what the person set. */
            const form = {};

            this.owners.duties.forEach((item) => {
                form[item.duty] = item.saved || '';
            });

            this.ownerForm = form;

            const roles = {};

            this.owners.roles.forEach((item) => {
                roles[item.role] = item.saved || '';
            });

            this.roleForm = roles;
        },

        ownersChanged() {
            return this.owners.duties.some((item) => (this.ownerForm[item.duty] || '') !== (item.saved || ''))
                || this.owners.roles.some((item) => (this.roleForm[item.role] || '') !== (item.saved || ''));
        },

        /** The choices for a role: follow "Penalties", Driver Check, or a listener. */
        roleOptions() {
            return [
                { value: '', name: t.owners.roles.default },
                ...this.owners.options.map((option) => ({ value: option.key || '_dc', name: option.name })),
            ];
        },

        /** Who delivers the role's penalties now, as a name. */
        roleOwnerName(item) {
            const option = this.owners.options.find((entry) => (entry.key || null) === (item.owner || null));

            return option ? option.name : item.owner || 'Driver Check';
        },

        /** The chosen listener for a role is on but its process is not up. */
        roleIdle(role) {
            const key = this.roleForm[role] || '';

            if (key === '' || key === '_dc') {
                return false;
            }

            const option = this.owners.options.find((item) => item.key === key);

            return !!option && !option.running;
        },

        /** The saved listener for a role is off or has no account: another one does it meanwhile. */
        roleFallback(item) {
            return !!item.saved && item.saved !== '_dc' && item.owner !== item.saved;
        },

        /** The chosen listener is switched on but its process is not up: the duty waits. */
        ownerIdle(duty) {
            const key = this.ownerForm[duty] || '';

            if (key === '') {
                return false;
            }

            const option = this.owners.options.find((item) => item.key === key);

            return !!option && !option.running;
        },

        /** The saved owner is off or has no account: Driver Check does it meanwhile. */
        ownerFallback(item) {
            return !!item.saved && item.owner !== item.saved;
        },

        async saveOwners() {
            if (this.savingOwners) {
                return;
            }

            this.savingOwners = true;
            this.ownersError = null;

            const body = {};

            this.owners.duties.forEach((item) => {
                body[item.duty] = this.ownerForm[item.duty] || null;
            });

            const roles = {};

            this.owners.roles.forEach((item) => {
                roles[item.role] = this.roleForm[item.role] || null;
            });

            try {
                const json = await this.request(this.endpoints.owners, {
                    method: 'PUT',
                    body: JSON.stringify({ owners: body, roles }),
                });

                this.applyOwners(json.owners);
                this.rows = Array.isArray(json.listeners) ? json.listeners : this.rows;

                this.notify(t.owners.saved);
            } catch (error) {
                this.ownersError = error?.message || t.errors.save;
            } finally {
                this.savingOwners = false;
            }
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

            if (response.status === 422) {
                const error = new Error(json.message || t.errors.save);
                error.errors = json.errors || {};

                throw error;
            }

            if (!response.ok) {
                throw new Error(json.message || `HTTP ${response.status}`);
            }

            return json;
        },

        /*
        |----------------------------------------------------------------
        | Display
        |----------------------------------------------------------------
        */

        number(value) {
            return formatNumber(value);
        },

        date(value) {
            return formatDate(value);
        },

        relative(value) {
            return formatRelative(value);
        },

        /* The shared empty state offers a filter reset; this page has none. */
        hasActiveFilters() {
            return false;
        },

        resetFilters() {},

        dutyLabel(duty) {
            /* "penalties:sales" - the penalties of one role. */
            const [name, role] = String(duty).split(':');

            if (role) {
                return `${t.owners.duties[name]?.name || name} · ${t.owners.roles.names[role] || role}`;
            }

            return t.duties[duty] || t.owners.duties[duty]?.name || duty;
        },

        /** What it does: its own switches and the duties that are its by "who does what". */
        rowDuties(row) {
            return [...(row.duties || []), ...(row.owned_duties || [])];
        },

        /** up / waiting for its watchdog / switched off */
        stateOf(row) {
            if (!row.is_enabled) {
                return 'off';
            }

            if (row.running) {
                return 'running';
            }

            return row.supervised ? 'starting' : 'stopped';
        },

        stateClass(state) {
            return {
                running: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20',
                starting: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/20',
                stopped: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/20',
                off: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-300/60 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10',
            }[state];
        },

        /*
        |----------------------------------------------------------------
        | Form
        |----------------------------------------------------------------
        */

        openCreate() {
            this.form = emptyForm();

            this.openForm();
        },

        openEdit(row) {
            this.form = {
                id: row.id,
                key: row.key,
                name: row.name || '',
                telegram_account_id: row.telegram_account_id ?? '',
                is_enabled: !!row.is_enabled,
                duties: [...(row.duties || [])],
            };

            this.openForm();
        },

        openForm() {
            this.formError = null;
            this.formErrors = {};
            this.formOpen = true;
        },

        closeForm() {
            if (this.saving) {
                return;
            }

            this.formOpen = false;
        },

        /** Accounts a listener can take: free ones, and this row's own. */
        freeAccounts() {
            return this.accounts.filter(
                (account) => !account.taken_by || account.id === Number(this.form.telegram_account_id),
            );
        },

        hasDuty(duty) {
            return this.form.duties.includes(duty);
        },

        toggleDuty(duty) {
            this.form.duties = this.hasDuty(duty)
                ? this.form.duties.filter((item) => item !== duty)
                : [...this.form.duties, duty];
        },

        fieldError(field) {
            const key = Object.keys(this.formErrors).find(
                (name) => name === field || name.startsWith(field + '.'),
            );

            const messages = key ? this.formErrors[key] : null;

            return Array.isArray(messages) ? messages[0] : null;
        },

        async save() {
            if (this.saving) {
                return;
            }

            this.saving = true;
            this.formError = null;
            this.formErrors = {};

            const editing = !!this.form.id;

            const body = {
                name: this.form.name,
                telegram_account_id: this.form.telegram_account_id === '' ? null : Number(this.form.telegram_account_id),
                is_enabled: this.form.is_enabled,
                duties: this.form.duties,
            };

            if (!editing) {
                body.key = this.form.key;
            }

            try {
                await this.request(
                    editing ? `${this.endpoints.base}/${this.form.id}` : this.endpoints.store,
                    { method: editing ? 'PUT' : 'POST', body: JSON.stringify(body) },
                );

                this.formOpen = false;

                this.notify(editing ? t.messages.updated : t.messages.created);

                this.load(true);
            } catch (error) {
                this.formErrors = error?.errors || {};
                this.formError = error?.message || t.errors.save;
            } finally {
                this.saving = false;
            }
        },

        askDelete() {
            if (this.saving || !this.form.id) {
                return;
            }

            this.confirmError = null;
            this.formOpen = false;
            this.confirmOpen = true;
        },

        closeConfirm() {
            if (this.deleting) {
                return;
            }

            this.confirmOpen = false;
        },

        async destroy() {
            if (this.deleting || !this.form.id) {
                return;
            }

            this.deleting = true;
            this.confirmError = null;

            try {
                await this.request(`${this.endpoints.base}/${this.form.id}`, { method: 'DELETE' });

                this.confirmOpen = false;
                this.form = emptyForm();

                this.notify(t.messages.deleted);

                this.load(true);
            } catch (error) {
                this.confirmError = error?.message || t.errors.delete;
            } finally {
                this.deleting = false;
            }
        },

        notify(message, success = true) {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message, success },
            }));
        },
    };
}
