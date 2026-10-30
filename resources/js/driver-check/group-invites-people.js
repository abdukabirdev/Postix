/*
|--------------------------------------------------------------------------
| Group invites: the base of people
|--------------------------------------------------------------------------
|
| Everybody the invite works with, by where they stand: can be added (added
| into one of our groups before), cannot (Telegram refused because of
| them - junk, kept, never tried again), tried without an answer, never
| tried (reachable by an account or not). Each row shows what became of the
| person in each of our groups. One group's people, by their status in it,
| when a group is chosen. The filters live in the URL: the overview links
| straight into a slice.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

const FILTERS = ['flag', 'reach', 'group_id', 'status', 'search'];

export function groupInvitesPeoplePage(config) {
    const t = config.translations;
    const query = new URLSearchParams(window.location.search);

    return {
        ...inviteHelpers(t),

        t,
        endpoints: config.endpoints,

        loading: false,
        busy: false,
        error: null,
        rows: [],
        meta: null,
        counts: null,
        group: null,
        groups: [],

        filters: Object.fromEntries(FILTERS.map((key) => [key, query.get(key) ?? ''])),

        init() {
            this.load();
        },

        url(page = 1) {
            const params = new URLSearchParams({ page: String(page), per_page: '30' });

            FILTERS.forEach((key) => {
                const value = String(this.filters[key] ?? '').trim();

                if (value !== '') {
                    params.set(key, value);
                }
            });

            return `${this.endpoints.index}?${params}`;
        },

        /* The filters into the address bar, so a slice can be sent or reloaded. */
        remember() {
            const params = new URLSearchParams();

            FILTERS.forEach((key) => {
                const value = String(this.filters[key] ?? '').trim();

                if (value !== '') {
                    params.set(key, value);
                }
            });

            const search = params.toString();

            window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
        },

        async load(more = false) {
            if (this.loading) {
                return;
            }

            this.loading = true;
            this.error = null;

            try {
                const page = more && this.meta ? this.meta.current_page + 1 : 1;
                const json = await requestJson(this.url(page));

                this.rows = more ? [...this.rows, ...(json.data ?? [])] : (json.data ?? []);
                this.meta = json.meta;
                this.counts = json.counts ?? this.counts;
                this.group = json.group;
                this.groups = json.groups ?? [];

                if (!more) {
                    this.remember();
                }
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
        },

        get hasMore() {
            return this.meta !== null && this.meta.current_page < this.meta.last_page;
        },

        flagChips() {
            const counts = this.counts ?? {};

            return [
                { value: '', label: `${t.people.all} · ${this.number(counts.total ?? 0)}` },
                ...['can', 'cannot', 'tried', 'untried'].map((flag) => ({
                    value: flag,
                    label: `${this.flagLabel(flag)} · ${this.number(counts[flag] ?? 0)}`,
                })),
            ];
        },

        setFlag(value) {
            this.filters.flag = value;

            if (value !== 'untried') {
                this.filters.reach = '';
            }

            this.load();
        },

        reachChips() {
            const counts = this.counts ?? {};

            return [
                { value: '', label: t.people.reach_all },
                { value: 'reachable', label: `${t.people.reachable} · ${this.number(counts.untried_reachable ?? 0)}` },
                { value: 'unreachable', label: `${t.people.unreachable} · ${this.number(counts.untried_unreachable ?? 0)}` },
            ];
        },

        setReach(value) {
            this.filters.reach = value;
            this.load();
        },

        clearGroup() {
            this.filters.group_id = '';
            this.filters.status = '';
            this.load();
        },

        reset() {
            Object.keys(this.filters).forEach((key) => {
                this.filters[key] = '';
            });

            this.load();
        },

        filtered() {
            return FILTERS.some((key) => String(this.filters[key] ?? '').trim() !== '');
        },

        async resetRefused() {
            if (this.busy || !window.confirm(t.people.reset_confirm.replace(':n', this.number(this.counts?.cannot ?? 0)))) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.reset, 'POST', {});
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        telegramUrl(row) {
            return row.username ? `https://t.me/${row.username}` : `tg://user?id=${row.telegram_user_id}`;
        },

        /* With a group chosen: its row first, the others after. */
        tries(row) {
            if (!this.filters.group_id) {
                return row.groups;
            }

            const id = Number(this.filters.group_id);

            return [...row.groups].sort((a, b) => (b.group_id === id) - (a.group_id === id));
        },

        tryTitle(tryRow) {
            const parts = [this.statusLabel(tryRow.status)];

            if (tryRow.error) {
                parts.push(this.errorLabel(tryRow.error));
            }

            if (tryRow.attempted_at) {
                parts.push(t.people.tried_at.replace(':time', this.time(tryRow.attempted_at)));
            }

            if (tryRow.left_at) {
                parts.push(t.people.left_at.replace(':time', this.time(tryRow.left_at)));
            }

            if (tryRow.account) {
                parts.push(this.phone(tryRow.account));
            }

            if (tryRow.via === 'username') {
                parts.push(t.people.via_username);
            }

            return parts.join(' · ');
        },

        /* Why the person stands where they stand, in words. */
        reasonLine(row) {
            if (row.flag === 'cannot') {
                return this.errorLabel(row.reason);
            }

            if (row.flag === 'can') {
                return t.people.can_line.replace(':n', this.number(row.groups.filter((g) => ['invited', 'left'].includes(g.status)).length));
            }

            if (row.flag === 'tried') {
                const last = row.groups[0];

                return last ? `${this.statusLabel(last.status)}${last.error ? ' · ' + this.errorLabel(last.error) : ''}` : '';
            }

            return '';
        },
    };
}
