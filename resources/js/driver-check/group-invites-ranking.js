/*
|--------------------------------------------------------------------------
| Group invites: the accounts side by side
|--------------------------------------------------------------------------
|
| Over 7 / 14 / 30 / 90 days: the account that added the most, any
| account's days (the leader's at first, a click on a row picks another),
| the ranking, how old an account is against how much it adds a day, the
| countries of the numbers, every account in one sortable table. People,
| never batches.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';
import { flag, regionName } from './group-invites-accounts';

/* The ranking shows this many until "show all". */
const BOARD_TOP = 15;

/* The age chart's frame, in its viewBox units. */
const PLOT = { w: 640, h: 300, left: 44, right: 18, top: 22, bottom: 44 };

/* The table's columns and what each sorts by. */
const SORT_KEYS = {
    account: 'id',
    country: 'countryKey',
    age: 'rank',
    since: 'since',
    days: 'activeDays',
    tried: 'tried',
    added: 'added',
    per_day: 'perDay',
    reach: 'reach',
    best: 'best',
    today: 'today',
    floods: 'floods',
    state: 'stateOrder',
};

/* These sort up on the first click (oldest, first, A-Z); the numbers sort down. */
const SORT_UP = ['account', 'country', 'age', 'since', 'state'];

const esc = (value) => String(value).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/* A clean top for an axis: 10, 20, 25, 50, 100... */
function niceMax(value) {
    if (value <= 0) {
        return 10;
    }

    const power = 10 ** Math.floor(Math.log10(value));

    return ([1, 2, 2.5, 5, 10].find((m) => m * power >= value) ?? 10) * power;
}

export function groupInvitesRankingPage(config) {
    const t = config.translations;
    const r = t.ranking;

    return {
        ...inviteHelpers(t),

        t,
        endpoints: config.endpoints,
        boardTop: BOARD_TOP,

        loading: false,
        error: null,
        data: null,
        accounts: [],
        days: 7,
        /* The account whose days are shown; null - the leader. */
        picked: null,
        showAll: false,
        sort: { key: 'added', dir: -1 },
        hover: null,
        hoverPoint: null,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const json = await requestJson(`${this.endpoints.show}?days=${this.days}`);
                this.data = json;
                this.accounts = this.enrich(json.accounts ?? [], json.dates ?? []);
                this.hover = null;
                this.hoverPoint = null;

                if (this.picked !== null && !this.accounts.some((a) => a.id === this.picked)) {
                    this.picked = null;
                }
            } catch (error) {
                this.error = error?.message || t.load_failed;
                notify(this.error, false);
            } finally {
                this.loading = false;
            }
        },

        /* What the page reads off every account, worked out once per load. */
        enrich(list, dates) {
            const byAge = list.filter((a) => a.telegram_user_id).sort((a, b) => a.telegram_user_id - b.telegram_user_id);
            const ranks = new Map(byAge.map((a, i) => [a.id, i + 1]));

            return list.map((a) => {
                const added = a.added_by_day ?? [];
                const tried = a.tried_by_day ?? [];
                const activeDays = tried.filter((n) => n > 0).length;
                const bestAt = added.reduce((best, n, i) => (n > added[best] ? i : best), 0);
                const seen = a.added + a.not_added;

                return {
                    ...a,
                    added_by_day: added,
                    tried_by_day: tried,
                    activeDays,
                    perDay: activeDays ? a.added / activeDays : 0,
                    reach: seen > 0 ? Math.round((100 * a.added) / seen) : null,
                    best: added[bestAt] ?? 0,
                    bestDay: (added[bestAt] ?? 0) > 0 ? dates[bestAt] : null,
                    today: added[added.length - 1] ?? 0,
                    rank: ranks.get(a.id) ?? null,
                    countryKey: a.country?.code ?? '',
                    since: a.first_day ?? null,
                    stateOrder: a.excluded ? 3 : (a.held ? 2 : (a.rest_until ? 1 : 0)),
                };
            });
        },

        periods() {
            return (this.data?.periods ?? [7, 14, 30, 90]).map((days) => ({ value: days, label: t.analysis.period.replace(':n', days) }));
        },

        setPeriod(value) {
            this.days = value;
            this.load();
        },

        /* 40,9 - one decimal at most. */
        one(value, digits = 1) {
            return Number(value ?? 0).toLocaleString(document.documentElement.lang || 'ru', { maximumFractionDigits: digits });
        },

        shortDay(iso) {
            if (!iso) {
                return '';
            }

            const [, month, day] = String(iso).split('-');

            return `${day}.${month}`;
        },

        reachText(value) {
            return value === null || value === undefined ? '—' : `${value}%`;
        },

        who(account) {
            return account ? `#${account.id} · ${this.phone(account.phone)}` : '';
        },

        countryLabel(country) {
            if (!country) {
                return t.accounts.stats.unknown;
            }

            if (!country.region) {
                return `${t.accounts.stats.anonymous} +${country.code}`;
            }

            return `${flag(country.region)} ${regionName(country.region)} +${country.code}`;
        },

        countryShort(account) {
            const country = account.country;

            return country ? `${flag(country.region)} +${country.code}`.trim() : '';
        },

        /*
        |--------------------------------------------------------------
        | The leader and the ranking
        |--------------------------------------------------------------
        */

        ranked() {
            return this.accounts.filter((a) => a.added > 0).sort((a, b) => b.added - a.added || a.id - b.id);
        },

        leader() {
            return this.ranked()[0] ?? null;
        },

        totalAdded() {
            return this.accounts.reduce((sum, a) => sum + a.added, 0);
        },

        leaderTitle() {
            return r.leader.title.replace(':days', this.data?.period?.days ?? this.days);
        },

        leaderOrigin() {
            const a = this.leader();

            return a ? [this.countryLabel(a.country), t.accounts.ages[a.age]].filter(Boolean).join(' · ') : '';
        },

        nextLine() {
            const next = this.ranked().slice(1, 3);

            return next.length ? `${r.leader.next} ` + next.map((a) => `#${a.id} — ${this.number(a.added)}`).join(', ') : '';
        },

        selected() {
            return this.accounts.find((a) => a.id === this.picked) ?? this.leader();
        },

        isSelected(account) {
            return this.selected()?.id === account.id;
        },

        pick(account) {
            this.picked = account.id;
            this.hover = null;
        },

        board() {
            return this.showAll ? this.ranked() : this.ranked().slice(0, BOARD_TOP);
        },

        boardWidth(account) {
            return Math.max(0.5, (100 * account.added) / (this.leader()?.added || 1));
        },

        boardToggle() {
            return this.showAll
                ? r.board.show_top.replace(':n', BOARD_TOP)
                : r.board.show_all.replace(':n', this.ranked().length);
        },

        boardLine(account) {
            return r.board.row
                .replace(':tried', this.number(account.tried))
                .replace(':added', this.number(account.added))
                .replace(':perday', this.one(account.perDay))
                .replace(':reach', this.reachText(account.reach));
        },

        /*
        |--------------------------------------------------------------
        | One account's days; the first three day by day
        |--------------------------------------------------------------
        */

        daily() {
            const account = this.selected();
            const dates = this.data?.dates ?? [];

            if (!account) {
                return [];
            }

            return dates.map((day, i) => ({
                day,
                added: account.added_by_day[i] ?? 0,
                tried: account.tried_by_day[i] ?? 0,
                today: i === dates.length - 1,
            }));
        },

        dailyTitle() {
            return r.daily.title.replace(':who', this.who(this.selected()));
        },

        dailyMax() {
            return Math.max(1, ...this.daily().map((point) => point.added));
        },

        barHeight(point) {
            return point.added > 0 ? Math.max(3, (100 * point.added) / this.dailyMax()) : 0;
        },

        /* Every few days a label under the bars, so they never collide. */
        showsLabel(index) {
            const count = this.daily().length;
            const step = Math.ceil(count / 10);

            return index % step === 0 || index === count - 1;
        },

        hoverLine(point) {
            return r.daily.tooltip.replace(':added', this.number(point.added)).replace(':tried', this.number(point.tried))
                + (point.today ? ` · ${r.daily.today}` : '');
        },

        top3() {
            return this.ranked().slice(0, 3);
        },

        /* The latest day first. */
        topRows() {
            const dates = this.data?.dates ?? [];
            const top = this.top3();

            return dates
                .map((day, i) => ({ day, today: i === dates.length - 1, values: top.map((a) => a.added_by_day[i] ?? 0) }))
                .reverse();
        },

        /*
        |--------------------------------------------------------------
        | Age against the result
        |--------------------------------------------------------------
        */

        points() {
            return this.accounts.filter((a) => a.telegram_user_id && a.activeDays > 0);
        },

        unplaced() {
            return this.accounts.filter((a) => !a.telegram_user_id && a.activeDays > 0).length;
        },

        /* The chart drawn as SVG markup: one dot per account, the picked one in the accent, the leaders and the oldest named. */
        scatter() {
            const points = this.points();

            if (points.length === 0) {
                return '';
            }

            const { w, h, left, right, top, bottom } = PLOT;
            const ids = points.map((a) => a.telegram_user_id / 1e9);
            const pad = Math.max(0.05, (Math.max(...ids) - Math.min(...ids)) * 0.06);
            const x0 = Math.min(...ids) - pad;
            const x1 = Math.max(...ids) + pad;
            const yMax = niceMax(Math.max(...points.map((a) => a.perDay)));
            const x = (v) => left + ((w - left - right) * (v - x0)) / (x1 - x0);
            const y = (v) => top + (h - top - bottom) * (1 - v / yMax);
            const label = 'fill-gray-400 dark:fill-gray-500 text-[11px] tabular-nums';
            let svg = '';

            for (let i = 0; i <= 4; i++) {
                const value = (yMax * i) / 4;

                if (i > 0) {
                    svg += `<line x1="${left}" x2="${w - right}" y1="${y(value)}" y2="${y(value)}" class="stroke-gray-100 dark:stroke-gray-800" />`;
                }

                svg += `<text x="${left - 8}" y="${y(value) + 4}" text-anchor="end" class="${label}">${esc(this.one(value))}</text>`;
            }

            const span = x1 - x0;
            const step = [0.1, 0.2, 0.25, 0.5, 1, 2, 5].find((s) => span / s <= 6) ?? 5;

            for (let i = Math.ceil(x0 / step); i * step <= x1; i++) {
                const value = i * step;
                svg += `<text x="${x(value)}" y="${h - bottom + 18}" text-anchor="middle" class="${label}">${esc(this.one(value, 2))}</text>`;
            }

            svg += `<line x1="${left}" x2="${w - right}" y1="${y(0)}" y2="${y(0)}" class="stroke-gray-300 dark:stroke-gray-700" />`;
            svg += `<text x="${left - 8}" y="${top - 10}" text-anchor="end" class="${label}">${esc(r.age.y)}</text>`;
            svg += `<text x="${left}" y="${h - 6}" class="${label}">${esc(r.age.older)}</text>`;
            svg += `<text x="${(left + w - right) / 2}" y="${h - 6}" text-anchor="middle" class="${label}">${esc(r.age.axis)}</text>`;
            svg += `<text x="${w - right}" y="${h - 6}" text-anchor="end" class="${label}">${esc(r.age.newer)}</text>`;

            const chosen = this.selected()?.id;
            const oldest = points.reduce((a, b) => (b.telegram_user_id < a.telegram_user_id ? b : a));
            const named = new Set([...this.top3().map((a) => a.id), chosen, oldest.id]);
            const order = [...points].sort((a, b) => (a.id === chosen) - (b.id === chosen));

            for (const a of order) {
                const cx = x(a.telegram_user_id / 1e9);
                const cy = y(a.perDay);
                const mine = a.id === chosen;

                svg += `<circle cx="${cx}" cy="${cy}" r="${mine ? 6.5 : 4.5}" class="${mine ? 'fill-brand-500' : 'fill-gray-400 dark:fill-gray-500'}" style="stroke: var(--dc-surface); stroke-width: 2" />`;

                if (named.has(a.id)) {
                    const toRight = cx < w - 70;
                    svg += `<text x="${cx + (toRight ? 10 : -10)}" y="${cy + 4}" text-anchor="${toRight ? 'start' : 'end'}" class="${mine ? 'fill-gray-950 dark:fill-white font-semibold' : 'fill-gray-500 dark:fill-gray-400'} text-[12px] tabular-nums">#${a.id}</text>`;
                }

                svg += `<circle cx="${cx}" cy="${cy}" r="11" fill="transparent" data-id="${a.id}" class="cursor-pointer" />`;
            }

            return svg;
        },

        pointAt(event) {
            const id = Number(event.target?.dataset?.id);

            return id ? (this.accounts.find((a) => a.id === id) ?? null) : null;
        },

        scatterOver(event) {
            this.hoverPoint = this.pointAt(event);
        },

        scatterClick(event) {
            const account = this.pointAt(event);

            if (account) {
                this.pick(account);
            }
        },

        pointLine(account) {
            return r.age.point
                .replace(':id', account.id)
                .replace(':tg', this.one(account.telegram_user_id / 1e9, 2))
                .replace(':rank', account.rank)
                .replace(':perday', this.one(account.perDay));
        },

        /*
        |--------------------------------------------------------------
        | By country
        |--------------------------------------------------------------
        */

        countries() {
            const rows = new Map();

            for (const a of this.accounts) {
                const row = rows.get(a.countryKey) ?? {
                    key: a.countryKey,
                    label: this.countryLabel(a.country),
                    accounts: 0,
                    worked: 0,
                    tried: 0,
                    added: 0,
                    notAdded: 0,
                    accountDays: 0,
                    floods: 0,
                };

                row.accounts++;
                row.worked += a.activeDays > 0 ? 1 : 0;
                row.tried += a.tried;
                row.added += a.added;
                row.notAdded += a.not_added;
                row.accountDays += a.activeDays;
                row.floods += a.floods;
                rows.set(a.countryKey, row);
            }

            const list = [...rows.values()].map((row) => {
                const seen = row.added + row.notAdded;

                return {
                    ...row,
                    perDay: row.accountDays ? row.added / row.accountDays : 0,
                    reach: seen > 0 ? Math.round((100 * row.added) / seen) : null,
                };
            });
            const top = Math.max(1, ...list.map((row) => row.perDay));

            return list
                .map((row) => ({ ...row, bar: row.perDay > 0 ? Math.max(3, (100 * row.perDay) / top) : 0 }))
                .sort((a, b) => b.added - a.added || b.accounts - a.accounts);
        },

        /*
        |--------------------------------------------------------------
        | Every account
        |--------------------------------------------------------------
        */

        setSort(column) {
            const key = SORT_KEYS[column];

            if (this.sort.key === key) {
                this.sort.dir = -this.sort.dir;
            } else {
                this.sort = { key, dir: SORT_UP.includes(column) ? 1 : -1 };
            }
        },

        sortState(column) {
            if (this.sort.key !== SORT_KEYS[column]) {
                return null;
            }

            return this.sort.dir > 0 ? 'ascending' : 'descending';
        },

        table() {
            const { key, dir } = this.sort;

            return [...this.accounts].sort((a, b) => {
                const va = a[key];
                const vb = b[key];

                if (va === null || va === undefined || va === '') {
                    return vb === null || vb === undefined || vb === '' ? a.id - b.id : 1;
                }

                if (vb === null || vb === undefined || vb === '') {
                    return -1;
                }

                const order = typeof va === 'string' ? va.localeCompare(vb) : va - vb;

                return order * dir || a.id - b.id;
            });
        },

        ageCell(account) {
            return account.rank ? r.rank.replace(':n', account.rank) : r.no_id;
        },

        floodCell(account) {
            return account.floods > 0 ? r.flood_line.replace(':n', this.number(account.floods)).replace(':max', this.duration(account.flood_max)) : '0';
        },

        floodTitle(account) {
            return account.peer_floods > 0 ? r.peer_line.replace(':n', this.number(account.peer_floods)) : '';
        },

        stateLine(account) {
            if (account.excluded) {
                return account.listener ? t.accounts.listener : t.accounts.excluded;
            }

            if (account.held) {
                return t.accounts.held + (account.held_reason ? ` · ${this.errorLabel(account.held_reason)}` : '');
            }

            if (account.rest_until) {
                const kind = t.accounts.rest_kinds[account.rest_kind] ?? this.errorLabel(account.rest_reason);

                return `${t.accounts.resting.replace(':time', this.time(account.rest_until))} · ${kind}`;
            }

            return t.accounts.ready;
        },

        stateDot(account) {
            if (account.excluded) {
                return 'text-gray-300 dark:text-gray-600';
            }

            if (account.held) {
                return 'text-error-500';
            }

            return account.rest_until ? 'text-warning-500' : 'text-success-500';
        },
    };
}
