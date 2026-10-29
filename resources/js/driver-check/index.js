/*
|--------------------------------------------------------------------------
| Driver-check panel: Alpine components
|--------------------------------------------------------------------------
|
| Registered from resources/js/app.js before Alpine.start(), so the blade
| pages only carry markup and a small config object.
|
*/

import { createListPage } from './list-page';
import { sessionsPage } from './sessions';
import { penaltiesPage } from './penalties';
import { autoRepliesPage } from './auto-replies';
import { personalAnswersPage } from './personal-answers';
import { penaltySettingsPage } from './penalty-settings';
import { fileRoutesPage } from './file-routes';
import { scheduledMessagesPage } from './scheduled-messages';
import { listenersPage } from './listeners';
import { systemPage } from './system';
import { listenerProcessesBlock } from './processes';
import { queuePage, watchdogPage } from './monitoring';
import { contactSyncPage } from './contact-sync';
import { contactsPage } from './contacts';
import { contactReportPage } from './contact-report';
import { contactSettingsPage } from './contact-settings';
import { groupJoinPage } from './group-join';
import { groupJoinGroupsPage } from './group-join-groups';
import { groupJoinPeoplePage } from './group-join-people';
import { groupJoinSettingsPage } from './group-join-settings';
import { contactAccountsPage } from './contact-accounts';
import { groupInvitesPage } from './group-invites';
import { statusType } from './status';

/*
|--------------------------------------------------------------------------
| Operators (management CRUD)
|--------------------------------------------------------------------------
*/

function operatorsPage(config) {
    const t = config.translations;
    const ui = config.ui;

    /*
     * Fixed by the page: /driver-check/operators lists operators,
     * /driver-check/sales lists sales managers. Never a filter.
     */
    const role = ['sales', 'all'].includes(config.role) ? config.role : 'operation';

    /* A person added on the page of everyone starts as an operator; the form can change it. */
    const newRole = role === 'all' ? 'operation' : role;

    /* OperationUser::MESSAGE_SWITCHES: what goes to their private chat, all on by default. */
    const switchKeys = Object.keys(t.form.switch || {});

    const allOn = () => Object.fromEntries(switchKeys.map((key) => [key, true]));

    const emptyForm = () => ({
        id: null,
        name: '',
        telegram_username: '',
        telegram_id: '',
        dm_enabled: true,
        role: newRole,
        /* '' = as the role says (Russian for sales, Uzbek for operators). */
        language: '',
        respectful: false,
        /* How to call them, per language: "Ali aka", "jigar". */
        address: { uz: '', ru: '' },
        switches: allOn(),
        deletable: false,
    });

    return {
        ui,

        role,

        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                dm_enabled: '',
                linked: '',
                role,
                sort: 'name',
                direction: 'asc',
                per_page: 20,
                page: 1,
            },

            onLoaded(json) {
                this.stats = {
                    total: Number(json.stats?.total ?? 0),
                    linked: Number(json.stats?.linked ?? 0),
                    dm_enabled: Number(json.stats?.dm_enabled ?? 0),
                    failing: Number(json.stats?.failing ?? 0),
                    roles: {
                        /* The tab of everyone counts both lists. */
                        all: Number(json.stats?.roles?.operation ?? 0) + Number(json.stats?.roles?.sales ?? 0),
                        operation: Number(json.stats?.roles?.operation ?? 0),
                        sales: Number(json.stats?.roles?.sales ?? 0),
                    },
                };
            },
        }),

        endpoints: config.endpoints,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        stats: { total: 0, linked: 0, dm_enabled: 0, failing: 0, roles: { all: 0, operation: 0, sales: 0 } },

        formOpen: false,

        saving: false,

        formError: null,

        formErrors: {},

        /** Delete takes two clicks: the first one only asks. */
        deleteArmed: false,

        form: emptyForm(),

        init() {
            this.readUrl();

            /* A ?role= in a hand-edited URL must not swap the page's list. */
            this.filters.role = role;

            this.load();
        },

        /**
         * "По роли: Узбекский" - the option that leaves the language to the
         * role, naming what that is for the role in the form right now.
         */
        languageAutoLabel() {
            const lang = this.form.role === 'sales' ? 'ru' : 'uz';

            return t.form.language_auto.replace(':language', config.languages[lang] ?? lang);
        },

        languageOf(row) {
            return (row.message_language || '').toUpperCase();
        },

        /** How the person is called in the language they are written to. */
        addressOf(row) {
            const address = row.address || {};

            return address[row.message_language] || Object.values(address)[0] || '';
        },

        /** The private-chat switches turned off on the card, by name. */
        switchesOff(row) {
            return switchKeys
                .filter((key) => row.switches?.[key] === false)
                .map((key) => t.form.switch[key].label);
        },

        /**
         * The penalties page, narrowed to one person.
         */
        penaltiesUrl(row) {
            return `${this.endpoints.penalties}?operation_user_id=${encodeURIComponent(row.id)}`;
        },

        /*
        |----------------------------------------------------------------
        | Delivery state
        |
        | A report only reaches an operator when the switch is on AND a
        | username or id is filled in, so the badge reports that whole
        | condition rather than the dm_enabled flag alone.
        |----------------------------------------------------------------
        */

        deliveryState(row) {
            if (!row.dm_enabled) {
                return 'off';
            }

            if (!row.has_telegram_peer) {
                return 'unreachable';
            }

            if (row.dm_last_error) {
                return 'failing';
            }

            return 'on';
        },

        deliveryLabel(row) {
            return {
                off: t.table.dm_off,
                unreachable: t.table.dm_unreachable,
                failing: t.stats.failing,
                on: t.table.dm_on,
            }[this.deliveryState(row)];
        },

        deliveryClass(row) {
            return {
                off: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-300/60 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10',
                unreachable: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/20',
                failing: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/20',
                on: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20',
            }[this.deliveryState(row)];
        },

        deliveryDot(row) {
            return {
                off: 'bg-gray-400',
                unreachable: 'bg-warning-500',
                failing: 'bg-error-500',
                on: 'bg-success-500',
            }[this.deliveryState(row)];
        },

        avatarClass(row) {
            return row.can_receive_dm
                ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400'
                : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400';
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
                telegram_username: row.telegram_username || '',
                telegram_id: row.telegram_id ?? '',
                dm_enabled: !!row.dm_enabled,
                role: row.role || role,
                language: row.language || '',
                respectful: !!row.respectful,
                address: { uz: row.address?.uz || '', ru: row.address?.ru || '' },
                switches: { ...allOn(), ...(row.switches || {}) },
                deletable: !!row.deletable,
            };

            this.openForm();
        },

        /*
         * The page behind the dialog is deliberately left exactly where it
         * is: locking <html> would reset its scroll offset to the top,
         * because the layout gives html a fixed height. The dialog's own
         * scroll container carries `overscroll-contain` instead, which keeps
         * a wheel or a swipe over the dialog from reaching the page.
         */
        openForm() {
            this.formError = null;
            this.formErrors = {};
            this.deleteArmed = false;
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

        fieldError(field) {
            const messages = this.formErrors[field];

            return Array.isArray(messages) ? messages[0] : null;
        },

        /**
         * Mirrors OperationUser::normalizeName() so the operator can see,
         * before saving, the exact key their entry will be matched on.
         */
        normalizedPreview() {
            return String(this.form.name || '')
                .trim()
                .replace(/\s+/g, ' ')
                .toUpperCase();
        },

        async save() {
            if (this.saving) {
                return;
            }

            this.saving = true;
            this.formError = null;
            this.formErrors = {};

            const editing = !!this.form.id;

            const url = editing
                ? `${this.endpoints.base}/${this.form.id}`
                : this.endpoints.store;

            try {
                const response = await fetch(url, {
                    method: editing ? 'PUT' : 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        name: this.form.name,
                        telegram_username: this.form.telegram_username || null,
                        telegram_id: this.form.telegram_id === ''
                            ? null
                            : Number(this.form.telegram_id),
                        dm_enabled: this.form.dm_enabled,
                        role: this.form.role,
                        language: this.form.language || null,
                        respectful: !!this.form.respectful,
                        address: {
                            uz: this.form.address.uz.trim() || null,
                            ru: this.form.address.ru.trim() || null,
                        },
                        ...this.form.switches,
                    }),
                });

                if (response.status === 422) {
                    const body = await response.json();

                    this.formErrors = body.errors || {};
                    this.formError = body.message || null;

                    return;
                }

                await this.readJson(response);

                this.formOpen = false;

                /*
                 * Saved under the other role, the row leaves this list:
                 * say where it went rather than let it vanish.
                 */
                this.notify(
                    role !== 'all' && this.form.role !== role
                        ? t.messages.moved.replace(':role', t.tabs[this.form.role] ?? this.form.role)
                        : (editing ? t.messages.updated : t.messages.created),
                );

                this.load();
            } catch (error) {
                this.formError = error?.message || t.errors.save;
            } finally {
                this.saving = false;
            }
        },

        async remove() {
            if (this.saving || !this.form.id) {
                return;
            }

            if (!this.deleteArmed) {
                this.deleteArmed = true;

                return;
            }

            this.saving = true;
            this.formError = null;

            try {
                const response = await fetch(`${this.endpoints.base}/${this.form.id}`, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    credentials: 'same-origin',
                });

                await this.readJson(response);

                this.formOpen = false;

                this.notify(t.messages.deleted);

                this.load();
            } catch (error) {
                this.formError = error?.message || t.errors.delete;
                this.deleteArmed = false;
            } finally {
                this.saving = false;
            }
        },
    };
}

/*
|--------------------------------------------------------------------------
| Operation users (statistics list)
|--------------------------------------------------------------------------
*/

function operationUsersPage(config) {
    const t = config.translations;
    const ui = config.ui;

    const emptyStats = {
        drivers: 0,
        checks: 0,
        confirmed: 0,
        not_confirmed: 0,
        pending: 0,
        processing: 0,
        match_rate: 0,
        avg_match_score: null,
        best_match_score: null,
    };

    return {
        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                telegram_id: '',
                telegram_username: '',
                status: '',
                has_driver: '',
                min_match_score: '',
                max_match_score: '',
                period_from: '',
                period_to: '',
                sort: 'created_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },

            onLoaded(json) {
                this.globalStats = {
                    ...emptyStats,
                    ...(json.stats || {}),
                };
            },
        }),

        ui,

        detailBaseUrl: config.detailBaseUrl,

        exportUrls: config.exportUrls,

        globalStats: { ...emptyStats },

        statusLabels: t.result,

        init() {
            this.initList();
        },

        periodPresets() {
            return [
                { value: 'all', label: t.filters.period_all },
                { value: 'today', label: t.filters.period_today },
                { value: 'week', label: t.filters.period_week },
                { value: 'month', label: t.filters.period_month },
                { value: 'custom', label: t.filters.period_custom },
            ];
        },

        stats(row) {
            return row?.stats ?? emptyStats;
        },

        exportUrl(kind) {
            return `${this.exportUrls[kind]}?${this.exportParams().toString()}`;
        },

        detailUrl(row) {
            return `${this.detailBaseUrl}/${encodeURIComponent(row.id)}`;
        },

        /**
         * Share of confirmed checks, clamped so a rounding artefact cannot
         * push the bar past its track.
         */
        matchWidth(row) {
            const rate = Number(this.stats(row).match_rate ?? 0);

            return `${Math.max(0, Math.min(100, rate))}%`;
        },

        matchTone(row) {
            const rate = Number(this.stats(row).match_rate ?? 0);

            if (rate >= 80) {
                return 'bg-success-500';
            }

            if (rate >= 50) {
                return 'bg-warning-500';
            }

            if (rate > 0) {
                return 'bg-error-500';
            }

            return 'bg-gray-300 dark:bg-gray-700';
        },

        /** Only the result buckets that actually have rows are worth pixels. */
        resultChips(row) {
            const stats = this.stats(row);

            return [
                { key: 'confirmed', value: stats.confirmed },
                { key: 'not_confirmed', value: stats.not_confirmed },
                { key: 'pending', value: stats.pending },
                { key: 'processing', value: stats.processing },
            ].filter((chip) => Number(chip.value) > 0);
        },
    };
}

/*
|--------------------------------------------------------------------------
| Operation user (single operator + their drivers)
|--------------------------------------------------------------------------
*/

function operationUserPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        ui,

        ...createListPage({
            endpoint: config.driversUrl,

            translations: t,

            defaults: {
                status: '',
                period_from: '',
                period_to: '',
                sort: 'created_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },
        }),

        operationUserId: config.operationUserId,

        operatorUrl: config.operatorUrl,

        exportBaseUrl: config.exportBaseUrl,

        operator: null,

        operatorLoading: false,

        operatorError: null,

        statusLabels: {
            confirmed: t.drivers.confirmed,
            not_confirmed: t.drivers.not_confirmed,
            pending: t.drivers.pending,
            processing: t.filters.processing,
            unknown: t.unknown,
        },

        /** Which driver cards have their phone list unfolded. */
        expanded: {},

        init() {
            this.readUrl();

            this.loadOperator();
            this.load();
        },

        async refreshAll() {
            if (this.operatorLoading || this.loading) {
                return;
            }

            await Promise.all([this.loadOperator(), this.load()]);
        },

        async loadOperator() {
            this.operatorLoading = true;
            this.operatorError = null;

            try {
                const response = await fetch(this.operatorUrl, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });

                const json = await this.readJson(response);

                this.operator = json.data ?? json;
            } catch (error) {
                this.operatorError = error?.message || t.errors.load_operator;
            } finally {
                this.operatorLoading = false;
            }
        },

        periodPresets() {
            return [
                { value: 'all', label: t.filters.all },
                { value: 'last_week', label: t.filters.last_week },
                { value: 'last_month', label: t.filters.last_month },
                { value: 'custom', label: ui.period_custom },
            ];
        },

        operatorStats() {
            return this.operator?.stats ?? {
                drivers: 0,
                checks: 0,
                confirmed: 0,
                not_confirmed: 0,
                pending: 0,
                processing: 0,
                match_rate: 0,
                avg_match_score: null,
                best_match_score: null,
                last_check_at: null,
            };
        },

        exportUrl() {
            const params = this.exportParams({
                operation_user_id: this.operationUserId,
            });

            return `${this.exportBaseUrl}?${params.toString()}`;
        },

        /*
        |----------------------------------------------------------------
        | Driver card
        |----------------------------------------------------------------
        */

        driverStatus(driver) {
            return statusType(driver?.status ?? driver?.latest_check?.status);
        },

        driverLabel(driver) {
            return this.statusLabel(this.driverStatus(driver));
        },

        driverDescription(driver) {
            return {
                confirmed: t.drivers.confirmed_description,
                not_confirmed: t.drivers.not_confirmed_description,
                pending: t.drivers.pending_description,
                processing: t.drivers.pending_description,
                unknown: t.drivers.unknown_description,
            }[this.driverStatus(driver)];
        },

        phones(driver) {
            return Array.isArray(driver?.resolved_phones) ? driver.resolved_phones : [];
        },

        primaryPhone(driver) {
            return this.phones(driver)[0] ?? null;
        },

        telegramName(driver) {
            const phone = this.primaryPhone(driver);

            if (!phone) {
                return this.dash;
            }

            const full = [phone.telegram_first_name, phone.telegram_last_name]
                .map((part) => String(part || '').trim())
                .filter(Boolean)
                .join(' ');

            return full || this.dash;
        },

        telegramUsername(driver) {
            const phone = this.primaryPhone(driver);

            return this.handle(phone?.telegram_username) || t.drivers.no_username;
        },

        telegramUserId(driver) {
            return this.primaryPhone(driver)?.telegram_user_id ?? null;
        },

        driverScore(driver) {
            const value = driver?.stats?.avg_match_score;

            return value === null || value === undefined || value === ''
                ? null
                : Number(value);
        },

        toggleExpanded(driverId) {
            this.expanded[driverId] = !this.expanded[driverId];
        },

        isExpanded(driverId) {
            return !!this.expanded[driverId];
        },
    };
}

/*
|--------------------------------------------------------------------------
| Drivers (list page)
|--------------------------------------------------------------------------
*/

function driversPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        ui,

        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                status: '',
                min_match_score: '',
                max_match_score: '',
                period_from: '',
                period_to: '',
                sort: 'created_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },
        }),

        operatorBaseUrl: config.operatorBaseUrl,

        exportBaseUrl: config.exportBaseUrl,

        statusLabels: t.status,

        init() {
            this.initList();
        },

        periodPresets() {
            return [
                { value: 'all', label: t.filters.period_all },
                { value: 'today', label: t.filters.period_today },
                { value: 'week', label: t.filters.period_week },
                { value: 'month', label: t.filters.period_month },
                { value: 'custom', label: t.filters.period_custom },
            ];
        },

        stats(row) {
            return row?.stats ?? {
                checks: 0,
                confirmed: 0,
                not_confirmed: 0,
                pending: 0,
                processing: 0,
                avg_match_score: null,
                best_match_score: null,
                last_check_at: null,
            };
        },

        operatorUrl(row) {
            return row?.operation_user?.id
                ? `${this.operatorBaseUrl}/${encodeURIComponent(row.operation_user.id)}`
                : null;
        },

        phoneCount(row) {
            return Array.isArray(row?.resolved_phones) ? row.resolved_phones.length : 0;
        },

        exportUrl() {
            return `${this.exportBaseUrl}?${this.exportParams().toString()}`;
        },
    };
}

/*
|--------------------------------------------------------------------------
| Watched chats (management CRUD)
|--------------------------------------------------------------------------
*/

function chatsPage(config) {
    const t = config.translations;
    const ui = config.ui;

    const emptyForm = {
        id: null,
        chat: '',
        title: '',
        is_active: true,
        label: '',
    };

    return {
        ui,

        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                is_active: '',
                resolved: '',
                sort: 'created_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },

            onLoaded(json) {
                this.stats = {
                    total: Number(json.stats?.total ?? 0),
                    active: Number(json.stats?.active ?? 0),
                    watching: Number(json.stats?.watching ?? 0),
                    failing: Number(json.stats?.failing ?? 0),
                };
            },
        }),

        endpoints: config.endpoints,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        stats: { total: 0, active: 0, watching: 0, failing: 0 },

        formOpen: false,

        saving: false,

        formError: null,

        formErrors: {},

        confirmOpen: false,

        deleting: false,

        confirmError: null,

        form: { ...emptyForm },

        init() {
            this.initList();
        },

        /*
        |----------------------------------------------------------------
        | Row state
        |
        | A chat is only watched when it is switched on AND the listener
        | has turned its link into a numeric peer, so the badge reports
        | that whole condition rather than the is_active flag alone.
        |----------------------------------------------------------------
        */

        chatState(row) {
            if (!row.is_active) {
                return 'paused';
            }

            if (row.resolve_error) {
                return 'failed';
            }

            if (!row.is_resolved) {
                return 'pending';
            }

            return 'watching';
        },

        stateLabel(row) {
            return t.status[this.chatState(row)];
        },

        stateClass(row) {
            return {
                paused: 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-300/60 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-white/10',
                pending: 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-400 dark:ring-warning-500/20',
                failed: 'bg-error-50 text-error-700 ring-1 ring-inset ring-error-600/20 dark:bg-error-500/10 dark:text-error-400 dark:ring-error-500/20',
                watching: 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-600/20 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-500/20',
            }[this.chatState(row)];
        },

        stateDot(row) {
            return {
                paused: 'bg-gray-400',
                pending: 'bg-warning-500',
                failed: 'bg-error-500',
                watching: 'bg-success-500',
            }[this.chatState(row)];
        },

        avatarClass(row) {
            return row.is_watching
                ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400'
                : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400';
        },

        /*
        |----------------------------------------------------------------
        | Form
        |----------------------------------------------------------------
        */

        openCreate() {
            this.form = { ...emptyForm };

            this.openForm();
        },

        /**
         * The single field is filled with whatever identifies the chat -
         * the link it was added with, or the id it was resolved to - so
         * saving an untouched form is always a no-op.
         */
        openEdit(row) {
            this.form = {
                id: row.id,
                chat: row.link || String(row.chat_id ?? ''),
                title: row.title || '',
                is_active: !!row.is_active,
                label: row.label || '',
            };

            this.openForm();
        },

        openForm() {
            this.formError = null;
            this.formErrors = {};
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

        fieldError(field) {
            const messages = this.formErrors[field];

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

            const url = editing
                ? `${this.endpoints.base}/${this.form.id}`
                : this.endpoints.store;

            try {
                const response = await fetch(url, {
                    method: editing ? 'PUT' : 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        chat: this.form.chat,
                        title: this.form.title || null,
                        is_active: this.form.is_active,
                    }),
                });

                if (response.status === 422) {
                    const body = await response.json();

                    this.formErrors = body.errors || {};
                    this.formError = body.message || null;

                    return;
                }

                await this.readJson(response);

                this.formOpen = false;

                this.notify(editing ? t.messages.updated : t.messages.created);

                this.load();
            } catch (error) {
                this.formError = error?.message || t.errors.save;
            } finally {
                this.saving = false;
            }
        },

        /*
        |----------------------------------------------------------------
        | Removal
        |
        | Two dialogs cannot share the screen, so the edit form steps
        | aside for the confirmation and comes back if it is dismissed.
        |----------------------------------------------------------------
        */

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

            if (this.form.id) {
                this.openForm();
            }
        },

        async destroy() {
            if (this.deleting || !this.form.id) {
                return;
            }

            this.deleting = true;
            this.confirmError = null;

            try {
                const response = await fetch(
                    `${this.endpoints.base}/${this.form.id}`,
                    {
                        method: 'DELETE',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrf,
                        },
                        credentials: 'same-origin',
                    },
                );

                await this.readJson(response);

                /* Cleared first: closeConfirm() must not reopen the form. */
                this.form = { ...emptyForm };
                this.confirmOpen = false;

                this.notify(t.messages.deleted);

                this.load();
            } catch (error) {
                this.confirmError = error?.message || t.errors.delete;
            } finally {
                this.deleting = false;
            }
        },
    };
}

/*
|--------------------------------------------------------------------------
| Resolved phones
|--------------------------------------------------------------------------
*/

function resolvedPhonesPage(config) {
    const t = config.translations;
    const ui = config.ui;

    return {
        ui,

        ...createListPage({
            endpoint: config.endpoints.index,

            translations: t,

            defaults: {
                search: '',
                has_username: '',
                has_driver: '',
                stale: false,
                period_from: '',
                period_to: '',
                sort: 'resolved_at',
                direction: 'desc',
                per_page: 20,
                page: 1,
            },
        }),

        operatorBaseUrl: config.operatorBaseUrl,

        statusLabels: config.statusLabels ?? {},

        init() {
            this.initList();
        },

        periodPresets() {
            return [
                { value: 'all', label: t.filters.period_all },
                { value: 'today', label: t.filters.period_today },
                { value: 'week', label: t.filters.period_week },
                { value: 'month', label: t.filters.period_month },
                { value: 'custom', label: t.filters.period_custom },
            ];
        },

        stats(row) {
            return row?.stats ?? {
                checks: 0,
                confirmed: 0,
                not_confirmed: 0,
                pending: 0,
                processing: 0,
                last_check_at: null,
            };
        },

        fullName(row) {
            return [row?.telegram_first_name, row?.telegram_last_name]
                .map((part) => String(part || '').trim())
                .filter(Boolean)
                .join(' ');
        },

        operatorUrl(row) {
            const id = row?.driver?.operation_user?.id;

            return id ? `${this.operatorBaseUrl}/${encodeURIComponent(id)}` : null;
        },
    };
}

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

export function registerDriverCheck(Alpine) {
    Alpine.data('dcOperators', operatorsPage);
    Alpine.data('dcOperationUsers', operationUsersPage);
    Alpine.data('dcOperationUser', operationUserPage);
    Alpine.data('dcDrivers', driversPage);
    Alpine.data('dcResolvedPhones', resolvedPhonesPage);
    Alpine.data('dcChats', chatsPage);
    Alpine.data('dcFileRoutes', fileRoutesPage);
    Alpine.data('dcScheduledMessages', scheduledMessagesPage);
    Alpine.data('dcListeners', listenersPage);
    Alpine.data('dcSystem', systemPage);
    Alpine.data('dcListenerProcesses', listenerProcessesBlock);
    Alpine.data('dcSessions', sessionsPage);
    Alpine.data('dcPenalties', penaltiesPage);
    Alpine.data('dcPenaltySettings', penaltySettingsPage);
    Alpine.data('dcAutoReplies', autoRepliesPage);
    Alpine.data('dcPersonalAnswers', personalAnswersPage);
    Alpine.data('dcWatchdog', watchdogPage);
    Alpine.data('dcQueue', queuePage);
    Alpine.data('dcContactSync', contactSyncPage);
    Alpine.data('dcContacts', contactsPage);
    Alpine.data('dcContactReport', contactReportPage);
    Alpine.data('dcContactSettings', contactSettingsPage);
    Alpine.data('dcContactAccounts', contactAccountsPage);
    Alpine.data('dcGroupInvites', groupInvitesPage);
    Alpine.data('dcGroupJoin', groupJoinPage);
    Alpine.data('dcGroupJoinGroups', groupJoinGroupsPage);
    Alpine.data('dcGroupJoinPeople', groupJoinPeoplePage);
    Alpine.data('dcGroupJoinSettings', groupJoinSettingsPage);
}
