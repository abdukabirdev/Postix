/*
|--------------------------------------------------------------------------
| Scheduled messages
|--------------------------------------------------------------------------
|
| What the listener's account writes into groups by itself: one row per
| message, with its time, days and groups. A handful of rows, so no
| paging: everything comes in one request. "Send now" only leaves a mark;
| the listener sends within seconds, so the page looks again shortly after.
|
*/

import { formatDate, formatNumber, formatRelative } from './format';

const ALL_DAYS = [1, 2, 3, 4, 5, 6, 7];

const emptyForm = () => ({
    id: null,
    name: '',
    text: '',
    variants: [],
    send_at: '17:00',
    jitter_minutes: 0,
    days: [...ALL_DAYS],
    chat_ids: [],
    listener_key: '',
    is_active: true,
    skip_if_file_today: false,
});

export function scheduledMessagesPage(config) {
    const t = config.translations;

    return {
        ui: config.ui,

        translations: t,

        endpoints: config.endpoints,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        loading: false,

        error: null,

        rows: [],

        groups: [],

        /* Whose account a message can be written from: Driver Check (key null) and the listeners. */
        listeners: [],

        formOpen: false,

        saving: false,

        formError: null,

        formErrors: {},

        form: emptyForm(),

        extraGroup: '',

        confirm: { open: false, kind: null, row: null, busy: false, error: null },

        init() {
            this.load();
        },

        async load(silent = false) {
            if (!silent) {
                this.loading = true;
            }

            this.error = null;

            try {
                const json = await this.request(this.endpoints.index);

                this.rows = Array.isArray(json.messages) ? json.messages : [];
                this.groups = Array.isArray(json.groups) ? json.groups : [];
                this.listeners = Array.isArray(json.listeners) ? json.listeners : [];
            } catch (error) {
                this.error = error?.message || t.errors.load;
            } finally {
                this.loading = false;
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

        daysLabel(days) {
            const list = Array.isArray(days) ? days : [];

            if (list.length === 0 || list.length === 7) {
                return t.days.every_day;
            }

            return list.map((day) => t.days.short[day - 1]).join(', ');
        },

        senderLabel(key) {
            const listener = this.listeners.find((item) => (item.key || null) === (key || null));

            return listener ? listener.name : (key || 'Driver Check');
        },

        groupLabel(id) {
            const group = this.groups.find((item) => item.id === id);

            return group?.title ? `${group.title} (${id})` : String(id);
        },

        /** "today 17:00" / "tomorrow 17:00" / the weekday and time. */
        nextLabel(row) {
            if (!row.next_at) {
                if (!row.is_active) {
                    return t.next.off;
                }

                return row.has_text ? t.next.no_groups : t.next.no_text;
            }

            return this.date(row.next_at);
        },

        statusClass(status) {
            return {
                sent: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20',
                partial: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/20',
                failed: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/20',
            }[status] || 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300';
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
                name: row.name || '',
                text: row.text || '',
                variants: [...(row.variants || [])],
                send_at: row.send_at || '17:00',
                jitter_minutes: Number(row.jitter_minutes || 0),
                days: [...(row.days || ALL_DAYS)],
                chat_ids: [...(row.chat_ids || [])],
                listener_key: row.listener_key || '',
                is_active: !!row.is_active,
                skip_if_file_today: !!row.skip_if_file_today,
            };

            this.openForm();
        },

        openForm() {
            this.formError = null;
            this.formErrors = {};
            this.extraGroup = '';
            this.formOpen = true;

            this.$nextTick(() => {
                this.$refs.firstField?.focus({ preventScroll: true });
            });
        },

        closeForm() {
            if (this.saving) {
                return;
            }

            this.formOpen = false;
        },

        /** The groups to tick: the known ones and whatever this message already has. */
        formGroups() {
            const ids = this.groups.map((group) => group.id);

            this.form.chat_ids.forEach((id) => {
                if (!ids.includes(id)) {
                    ids.push(id);
                }
            });

            return ids;
        },

        hasGroup(id) {
            return this.form.chat_ids.includes(id);
        },

        toggleGroup(id) {
            this.form.chat_ids = this.hasGroup(id)
                ? this.form.chat_ids.filter((item) => item !== id)
                : [...this.form.chat_ids, id];
        },

        addExtraGroup() {
            const value = String(this.extraGroup || '').trim();

            if (!/^-?\d{5,20}$/.test(value)) {
                this.formErrors = { chat_ids: [t.form.group_invalid] };

                return;
            }

            const id = Number(value);

            if (!this.groups.some((group) => group.id === id)) {
                this.groups = [...this.groups, { id, title: null }];
            }

            if (!this.hasGroup(id)) {
                this.form.chat_ids = [...this.form.chat_ids, id];
            }

            this.extraGroup = '';
            this.formErrors = {};
        },

        addVariant() {
            this.form.variants = [...this.form.variants, ''];

            /* The new box takes the cursor. */
            this.$nextTick(() => {
                const boxes = this.$root.querySelectorAll('[data-variant]');

                boxes[boxes.length - 1]?.focus({ preventScroll: false });
            });
        },

        removeVariant(index) {
            this.form.variants = this.form.variants.filter((_, i) => i !== index);
        },

        hasDay(day) {
            return this.form.days.includes(day);
        },

        toggleDay(day) {
            this.form.days = this.hasDay(day)
                ? this.form.days.filter((item) => item !== day)
                : [...this.form.days, day].sort();
        },

        /** The errors come back as chat_ids.0, days.1 ... */
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

            try {
                await this.request(
                    editing ? `${this.endpoints.base}/${this.form.id}` : this.endpoints.store,
                    {
                        method: editing ? 'PUT' : 'POST',
                        body: JSON.stringify({
                            name: this.form.name,
                            text: this.form.text,
                            variants: this.form.variants,
                            send_at: this.form.send_at,
                            jitter_minutes: Math.max(0, Math.min(30, Number(this.form.jitter_minutes) || 0)),
                            days: this.form.days,
                            chat_ids: this.form.chat_ids,
                            listener_key: this.form.listener_key || null,
                            is_active: this.form.is_active,
                            skip_if_file_today: !!this.form.skip_if_file_today,
                        }),
                    },
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

        /*
        |----------------------------------------------------------------
        | Switch on/off, send now, delete
        |----------------------------------------------------------------
        */

        async toggleActive(row) {
            try {
                await this.request(`${this.endpoints.base}/${row.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        name: row.name,
                        text: row.text,
                        variants: row.variants || [],
                        send_at: row.send_at,
                        jitter_minutes: row.jitter_minutes || 0,
                        days: row.days,
                        chat_ids: row.chat_ids,
                        listener_key: row.listener_key || null,
                        is_active: !row.is_active,
                        skip_if_file_today: !!row.skip_if_file_today,
                    }),
                });

                this.load(true);
            } catch (error) {
                this.error = error?.message || t.errors.save;
            }
        },

        ask(kind, row) {
            this.confirm = { open: true, kind, row, busy: false, error: null };
        },

        askDelete() {
            if (this.saving || !this.form.id) {
                return;
            }

            const row = this.rows.find((item) => item.id === this.form.id);

            this.formOpen = false;
            this.ask('delete', row || { id: this.form.id, name: this.form.name });
        },

        closeConfirm() {
            if (this.confirm.busy) {
                return;
            }

            this.confirm.open = false;
        },

        async runConfirm() {
            const { kind, row } = this.confirm;

            if (this.confirm.busy || !row) {
                return;
            }

            this.confirm.busy = true;
            this.confirm.error = null;

            try {
                if (kind === 'delete') {
                    await this.request(`${this.endpoints.base}/${row.id}`, { method: 'DELETE' });

                    this.notify(t.messages.deleted);
                } else {
                    await this.request(`${this.endpoints.base}/${row.id}/send`, { method: 'POST' });

                    this.notify(t.messages.queued);

                    /* The listener answers within seconds. */
                    setTimeout(() => this.load(true), 7000);
                }

                this.confirm.open = false;

                this.load(true);
            } catch (error) {
                this.confirm.error = error?.message || (kind === 'delete' ? t.errors.delete : t.errors.send);
            } finally {
                this.confirm.busy = false;
            }
        },

        notify(message, success = true) {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message, success },
            }));
        },
    };
}
