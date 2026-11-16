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

const FILTERS = ['flag', 'reach', 'activity', 'group_id', 'status', 'search'];

const ACTIVITY_TONES = {
    green: 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-500/10 dark:text-success-300',
    red: 'bg-error-50 text-error-700 ring-error-600/20 dark:bg-error-500/10 dark:text-error-300',
    unchecked: 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300',
};

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
        /* How many the filters match: counted apart from the list, it comes after it. */
        total: null,
        totalLoading: false,
        counts: null,
        group: null,
        groups: [],
        metaGroupId: undefined,
        perPage: 30,

        filters: Object.fromEntries(FILTERS.map((key) => [key, query.get(key) ?? ''])),

        init() {
            this.load();
        },

        url(page = 1, part = '') {
            const params = new URLSearchParams({ page: String(page), per_page: String(this.perPage) });

            if (part) {
                params.set('part', part);
            }

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

        /* The list comes first; the numbers (where people stand, the groups, how many match) after it. */
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

                if (!more) {
                    this.remember();
                }
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }

            if (!more && !this.error) {
                this.loadTotal();

                /* The numbers of the base and the groups do not change with a filter - but the chosen group's name does. */
                if (this.counts === null || this.metaGroupId !== this.filters.group_id) {
                    this.loadMeta();
                }
            }
        },

        async loadMeta() {
            this.metaGroupId = this.filters.group_id;

            try {
                const json = await requestJson(this.url(1, 'meta'));

                this.counts = json.counts ?? this.counts;
                this.group = json.group;
                this.groups = json.groups ?? [];
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            }
        },

        async loadTotal() {
            const asked = this.url(1, 'total');

            this.totalLoading = true;
            this.total = null;

            try {
                const json = await requestJson(asked);

                /* A newer filter was chosen while this was counted: its answer is not for this page. */
                if (asked === this.url(1, 'total')) {
                    this.total = json.total;
                }
            } catch (error) {
                this.total = null;
            } finally {
                this.totalLoading = false;
            }
        },

        changePerPage() {
            this.load();
        },

        get hasMore() {
            return this.meta !== null && !!this.meta.has_more;
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

        /* Worth inviting or not - green, red, not looked at yet - as big tiles over the list: a number and a filter in one. */
        activityTiles() {
            const counts = this.counts ?? {};
            const box = t.overview.activity_box;

            return [
                { value: 'green', label: box.green, hint: '', dot: 'bg-success-500', n: counts.green ?? 0 },
                { value: 'red', label: box.red, hint: box.red_hint, dot: 'bg-error-500', n: counts.red ?? 0 },
                { value: 'unchecked', label: box.unchecked, hint: box.unchecked_hint, dot: 'bg-gray-300 dark:bg-gray-600', n: counts.unchecked ?? 0 },
            ];
        },

        /* Checked 1 234 of 349 355 (0.4%). */
        checkedLine() {
            const c = this.counts ?? {};
            const checked = (c.green ?? 0) + (c.red ?? 0);

            return t.overview.activity_box.checked_short
                .replace(':checked', this.number(checked))
                .replace(':total', this.number(c.total ?? 0))
                .replace(':share', this.percent(checked, c.total ?? 0));
        },

        /* A second press on the chosen tile shows everybody again. */
        toggleActivity(value) {
            this.setActivity(this.filters.activity === value ? '' : value);
        },

        setActivity(value) {
            this.filters.activity = value;
            this.load();
        },

        activityTone(state) {
            return ACTIVITY_TONES[state] ?? ACTIVITY_TONES.unchecked;
        },

        /* Why green or red: wrote when, online when, in our group. */
        activityLine(row) {
            const parts = [];

            if (row.last_posted_at) {
                parts.push(t.activity.posted.replace(':time', this.time(row.last_posted_at)));
            }

            if (row.seen_status) {
                const seen = t.activity.seen[row.seen_status] ?? row.seen_status;

                parts.push(row.seen_status === 'offline' && row.last_seen_at ? t.activity.seen_at.replace(':time', this.time(row.last_seen_at)) : seen);
            }

            if (row.our_group_at) {
                parts.push(t.activity.our_group);
            }

            if (parts.length === 0 && row.activity === 'unchecked') {
                parts.push(t.activity.not_checked);
            }

            return parts.join(' · ');
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
                this.counts = null;
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
