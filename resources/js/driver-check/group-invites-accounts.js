/*
|--------------------------------------------------------------------------
| Group invites: the accounts
|--------------------------------------------------------------------------
|
| Each account that invites: in or out (saved at once), how far into its
| day (calls of its cap, @username lookups), how it rests after a flood,
| what its contacts are worth for the invite, what it got, its last stop.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';
import { stages } from './stages';

/*
 * "Adds nobody": tried at least this many people in the last days and got
 * fewer than this share of them in - Telegram quietly lets it add nobody.
 */
const QUIET_MIN_TRIED = 30;
const QUIET_SHARE = 0.05;

const QUALITY_TONES = {
    adding: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    quiet: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    few: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
};

/* Which way each order goes: the account to look at first comes first. */
const SORTS = {
    id: (a, b) => a.id - b.id,
    added_recent: (a, b) => b.recent.added - a.recent.added || b.recent.tried - a.recent.tried,
    added: (a, b) => b.added - a.added,
    added_today: (a, b) => b.added_today - a.added_today || b.added - a.added,
    rate_best: (a, b) => rateKey(b, -1) - rateKey(a, -1) || b.recent.added - a.recent.added,
    /* The ones that tried enough first, worst share first; then the rest. */
    rate_worst: (a, b) => worstGroup(a) - worstGroup(b) || rateKey(a, 2) - rateKey(b, 2) || b.recent.tried - a.recent.tried,
    not_added_recent: (a, b) => b.recent.not_added - a.recent.not_added,
    calls_today: (a, b) => b.calls_today - a.calls_today,
    contacts: (a, b) => b.contacts - a.contacts,
    not_added: (a, b) => b.not_added - a.not_added,
    /* Limited by Telegram first, then the ones never asked, the free ones last. */
    spam: (a, b) => spamRank(a) - spamRank(b),
    /* Held first, then resting, then ready, the ones left out last. */
    state: (a, b) => stateRank(a) - stateRank(b),
};

/* What an account has before its numbers and contacts are in. */
const NO_NUMBERS = {
    added_today: 0,
    lookups_today: 0,
    added: 0,
    left: 0,
    not_added: 0,
    failed: 0,
    already: 0,
    sent: 0,
    last_stop: null,
};

const NO_CONTACTS = { contacts: 0, contacts_can: 0, contacts_cannot: 0 };

const noRecent = () => ({ tried: 0, added: 0, not_added: 0 });

function spamRank(account) {
    return { limited: 0, failed: 1, unknown: 2, free: 4 }[account.spam_check?.status] ?? 3;
}

function stateRank(account) {
    if (account.excluded) {
        return 3;
    }

    if (account.held) {
        return 0;
    }

    return account.rest_until ? 1 : 2;
}

/* A region's flag from its two letters (regional indicator symbols). */
export function flag(region) {
    return /^[A-Z]{2}$/.test(region ?? '')
        ? String.fromCodePoint(...[...region].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65))
        : '';
}

let regionNames = null;

export function regionName(region) {
    try {
        regionNames ??= new Intl.DisplayNames([document.documentElement.lang || 'ru'], { type: 'region' });

        return regionNames.of(region) ?? region;
    } catch {
        return region;
    }
}

function rateKey(account, none) {
    return account.recent.tried > 0 ? account.recent.added / account.recent.tried : none;
}

function worstGroup(account) {
    if (account.recent.tried >= QUIET_MIN_TRIED) {
        return 0;
    }

    return account.recent.tried > 0 ? 1 : 2;
}

export function groupInvitesAccountsPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),

        /*
         * Three stages, one after another: who they are and how they stand
         * (fast), what each got, what its contacts are worth (the slowest).
         */
        ...stages({ state: 'loadState', numbers: 'loadNumbers', contacts: 'loadContacts' }),

        t,
        endpoints: config.endpoints,

        loading: false,
        busy: null,
        error: null,
        loaded: false,
        accounts: [],
        /* @SpamBot is being asked on the server now: the answers come to the cards as it goes. */
        spamChecking: false,
        spamPoll: null,
        limits: { batches_per_day: 0, batch_size: 0, usernames_per_day: 0 },
        recentDays: 3,
        ageBands: [],
        view: 'all',
        quality: 'all',
        filters: { search: '', sort: 'id' },
        /* The rows ticked for an action on several at once, by id. */
        selected: {},
        /* The row opened for its details. */
        opened: null,
        /* The table of how well they add: by country or by age; a row picked narrows the cards to it. */
        statsBy: 'country',
        picked: null,

        init() {
            this.load();
        },

        /* Everything again, stage by stage. `loading` and `loaded` are the first stage's: the list itself. */
        async load() {
            this.loading = true;
            this.error = null;

            await this.loadStage('state');

            this.error = this.stages.state.error;
            this.loaded = this.stages.state.loaded;
            this.loading = false;

            if (this.loaded) {
                await this.loadStages(['numbers', 'contacts']);
            }
        },

        /* Only how the accounts stand: after a button, and while @SpamBot is being asked. */
        async reloadState() {
            await this.loadStage('state');
        },

        async loadState() {
            const json = await requestJson(`${this.endpoints.index}?part=state`);
            const before = new Map(this.accounts.map((account) => [account.id, account]));

            /* The numbers and contacts already on the screen stay until their own stages bring new ones. */
            this.accounts = (json.accounts ?? []).map((account) => ({
                ...NO_NUMBERS,
                ...NO_CONTACTS,
                recent: noRecent(),
                ...this.kept(before.get(account.id)),
                ...account,
            }));

            this.limits = json.limits ?? this.limits;
            this.recentDays = json.recent_days ?? this.recentDays;
            this.ageBands = json.age_bands ?? this.ageBands;
            this.spamChecking = !!json.spam_checking;
            this.spamChecking ? this.startSpamPoll() : this.stopSpamPoll();
        },

        kept(account) {
            if (!account) {
                return {};
            }

            const out = { recent: account.recent };

            for (const key of [...Object.keys(NO_NUMBERS), ...Object.keys(NO_CONTACTS)]) {
                out[key] = account[key];
            }

            return out;
        },

        async loadNumbers() {
            const json = await requestJson(`${this.endpoints.index}?part=numbers`);
            const numbers = json.numbers ?? {};

            this.accounts.forEach((account) => {
                Object.assign(account, NO_NUMBERS, { recent: noRecent() }, numbers[account.id] ?? {});
            });
        },

        async loadContacts() {
            const json = await requestJson(`${this.endpoints.index}?part=contacts`);
            const contacts = json.contacts ?? {};

            this.accounts.forEach((account) => {
                const own = contacts[account.id];

                account.contacts = own?.total ?? 0;
                account.contacts_can = own?.can ?? 0;
                account.contacts_cannot = own?.cannot ?? 0;
            });
        },

        views() {
            const count = (fn) => this.accounts.filter(fn).length;

            return [
                { value: 'all', label: `${t.accounts.views.all} · ${this.accounts.length}` },
                { value: 'working', label: `${t.accounts.views.working} · ${count((a) => !a.excluded && !this.sitsOut(a))}` },
                { value: 'resting', label: `${t.accounts.views.resting} · ${count((a) => !a.excluded && this.sitsOut(a))}` },
                { value: 'excluded', label: `${t.accounts.views.excluded} · ${count((a) => a.excluded)}` },
            ];
        },

        /* The same views as readings at the top of the page: a number and a filter in one. */
        viewTiles() {
            const count = (fn) => this.accounts.filter(fn).length;

            return [
                { value: 'all', label: t.accounts.views.all, total: this.accounts.length },
                { value: 'working', label: t.accounts.views.working, total: count((a) => !a.excluded && !this.sitsOut(a)) },
                { value: 'resting', label: t.accounts.views.resting, total: count((a) => !a.excluded && this.sitsOut(a)) },
                { value: 'limited', label: t.accounts.table.views.limited, total: count((a) => a.spam_check?.status === 'limited') },
                { value: 'unasked', label: t.accounts.table.views.unasked, total: count((a) => !a.spam_check) },
                { value: 'excluded', label: t.accounts.views.excluded, total: count((a) => a.excluded) },
            ];
        },

        setView(value) {
            this.view = value;
        },

        /* How it adds lately: adds, adds nobody, or too few tried to tell. */
        qualityOf(account) {
            const { tried, added } = account.recent;

            if (tried >= QUIET_MIN_TRIED && added / tried < QUIET_SHARE) {
                return 'quiet';
            }

            return added > 0 ? 'adding' : 'few';
        },

        qualities() {
            const count = (value) => this.accounts.filter((a) => this.qualityOf(a) === value).length;

            return [
                { value: 'all', label: t.accounts.quality.all },
                ...['adding', 'quiet', 'few'].map((value) => ({ value, label: `${t.accounts.quality[value]} · ${count(value)}` })),
            ];
        },

        setQuality(value) {
            this.quality = value;
        },

        qualityHint() {
            return t.accounts.quality_hint
                .replace(':days', this.recentDays)
                .replace(':min', QUIET_MIN_TRIED)
                .replace(':share', Math.round(QUIET_SHARE * 100));
        },

        qualityTone(account) {
            return QUALITY_TONES[this.qualityOf(account)];
        },

        /* The search narrows the list as it is typed: nothing to send anywhere. */
        applyFilters() {},

        clearSearch() {
            this.filters.search = '';
        },

        matches(account) {
            const query = this.filters.search.trim();

            if (query === '') {
                return true;
            }

            if (/^#\d+$/.test(query)) {
                return String(account.id) === query.slice(1);
            }

            const digits = query.replace(/\D/g, '');

            return (digits !== '' && String(account.phone ?? '').replace(/\D/g, '').includes(digits))
                || String(account.username ?? '').toLowerCase().includes(query.replace(/^@/, '').toLowerCase());
        },

        shown() {
            return this.accounts
                .filter((account) => ({
                    working: !account.excluded && !this.sitsOut(account),
                    resting: !account.excluded && this.sitsOut(account),
                    excluded: account.excluded,
                    limited: account.spam_check?.status === 'limited',
                    unasked: !account.spam_check,
                })[this.view] ?? true)
                .filter((account) => this.quality === 'all' || this.qualityOf(account) === this.quality)
                .filter((account) => this.picked === null || this.groupOf(account, this.picked.by) === this.picked.key)
                .filter((account) => this.matches(account))
                .sort((a, b) => (SORTS[this.filters.sort] ?? SORTS.id)(a, b) || a.id - b.id);
        },

        /* A click on a column's head: that order; a second click puts them back in the order they were connected in. */
        sortBy(key) {
            this.filters.sort = this.filters.sort === key ? 'id' : key;
        },

        sortOn(key) {
            return this.filters.sort === key ? 'desc' : null;
        },

        /* Ticks: one action on several accounts. The listener is never ticked - it never takes part. */
        isSelected(account) {
            return !!this.selected[account.id];
        },

        tick(account) {
            if (account.listener) {
                return;
            }

            if (this.selected[account.id]) {
                delete this.selected[account.id];
            } else {
                this.selected[account.id] = true;
            }
        },

        tickedAccounts() {
            return this.accounts.filter((account) => this.selected[account.id]);
        },

        selectedCount() {
            return Object.keys(this.selected).length;
        },

        allShownSelected() {
            const rows = this.shown().filter((account) => !account.listener);

            return rows.length > 0 && rows.every((account) => this.selected[account.id]);
        },

        tickAll() {
            const rows = this.shown().filter((account) => !account.listener);

            if (this.allShownSelected()) {
                rows.forEach((account) => delete this.selected[account.id]);
            } else {
                rows.forEach((account) => {
                    this.selected[account.id] = true;
                });
            }
        },

        clearSelected() {
            this.selected = {};
        },

        /* In or out of the invites for every ticked account, one request after another. */
        async bulkExclude(excluded) {
            if (this.busy) {
                return;
            }

            const rows = this.tickedAccounts().filter((account) => account.excluded !== excluded);

            this.busy = 'bulk';

            let done = 0;

            try {
                for (const account of rows) {
                    const json = await requestJson(`${this.endpoints.index}/${account.id}`, 'PATCH', { excluded });
                    account.excluded = json.excluded;
                    done++;
                }

                notify(t.accounts.table.bulk.done.replace(':n', done));
                this.clearSelected();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        /* Off the pause and off the rest for every ticked account that sits out. */
        async bulkEnable() {
            if (this.busy) {
                return;
            }

            const rows = this.tickedAccounts().filter((account) => this.canEnable(account));

            this.busy = 'bulk';

            let done = 0;

            try {
                for (const account of rows) {
                    await requestJson(`${this.endpoints.index}/${account.id}/enable`, 'POST', {});
                    done++;
                }

                notify(t.accounts.table.bulk.done.replace(':n', done));
                this.clearSelected();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
                await this.reloadState();
            }
        },

        bulkCanEnable() {
            return this.tickedAccounts().some((account) => this.canEnable(account));
        },

        open(account) {
            this.opened = this.opened === account.id ? null : account.id;
        },

        stateTone(account) {
            if (account.excluded) {
                return 'off';
            }

            if (account.held) {
                return 'bad';
            }

            return account.rest_until ? 'warn' : 'ok';
        },

        /* The state in two or three words for the table; the whole line is in the row's details. */
        stateShort(account) {
            if (account.excluded) {
                return account.listener ? t.accounts.table.states.listener : t.accounts.out;
            }

            if (account.held) {
                return t.accounts.held;
            }

            return account.rest_until ? t.accounts.table.states.resting : t.accounts.table.states.ready;
        },

        /* Under the state: until when it rests. */
        stateSub(account) {
            return !account.excluded && !account.held && account.rest_until
                ? t.accounts.table.until.replace(':time', this.time(account.rest_until))
                : '';
        },

        /* Under the account's number: its id, calling code and age, short. */
        accountSub(account) {
            return [`#${account.id}`, account.country?.code ? `+${account.country.code}` : '', t.accounts.ages[account.age]].filter(Boolean).join(' · ');
        },

        /* Under the @SpamBot tag: until when it is limited, or when it was asked. */
        spamSub(account) {
            const check = account.spam_check;

            if (!check) {
                return '';
            }

            return check.status === 'limited' && check.until
                ? t.accounts.table.until.replace(':time', this.time(check.until))
                : t.accounts.spam_asked_at.replace(':time', this.time(check.at));
        },

        spamShort(account) {
            const check = account.spam_check;

            if (!check) {
                return t.accounts.table.spam.never;
            }

            if (check.status === 'limited') {
                return t.accounts.table.spam.limited;
            }

            return t.accounts.table.spam[check.status] ?? t.accounts.table.spam.unknown;
        },

        spamTag(account) {
            return { free: 'ok', limited: 'bad', failed: 'warn', unknown: 'warn' }[account.spam_check?.status] ?? '';
        },

        recentLine(account) {
            const { tried, added } = account.recent;

            if (tried === 0) {
                return t.accounts.recent_none.replace(':days', this.recentDays);
            }

            return t.accounts.recent_line
                .replace(':days', this.recentDays)
                .replace(':tried', this.number(tried))
                .replace(':added', this.number(added));
        },

        recentRate(account) {
            const { tried, added } = account.recent;

            return tried > 0 ? `${Math.round((100 * added) / tried)}%` : '';
        },

        /* The account's country (by the number's code) or its age band (by its Telegram id). */
        groupOf(account, by) {
            return by === 'age' ? account.age : (account.country?.code ?? 'unknown');
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

        groupLabel(account, by) {
            return by === 'age' ? (t.accounts.ages[account.age] ?? account.age) : this.countryLabel(account.country);
        },

        /* Under the number on its card: where it is from and how old. */
        originLine(account) {
            return [this.countryLabel(account.country), t.accounts.ages[account.age]].filter(Boolean).join(' · ');
        },

        statsTabs() {
            return ['country', 'age'].map((value) => ({ value, label: t.accounts.stats.by[value] }));
        },

        setStatsBy(value) {
            this.statsBy = value;
        },

        /* A row per country or age band: how many accounts, how many add nobody, what they tried and got lately. */
        statRows() {
            const by = this.statsBy;
            const rows = new Map();

            for (const account of this.accounts) {
                const key = this.groupOf(account, by);
                const row = rows.get(key) ?? {
                    key,
                    label: this.groupLabel(account, by),
                    ids: by === 'age' ? (t.accounts.age_ids[key] ?? '') : '',
                    accounts: 0,
                    taking: 0,
                    quiet: 0,
                    tried: 0,
                    added: 0,
                    total: 0,
                };

                row.accounts++;
                row.taking += account.excluded ? 0 : 1;
                row.quiet += this.qualityOf(account) === 'quiet' ? 1 : 0;
                row.tried += account.recent.tried;
                row.added += account.recent.added;
                row.total += account.added;
                rows.set(key, row);
            }

            const list = [...rows.values()];

            return by === 'age'
                ? list.sort((a, b) => this.ageBands.indexOf(a.key) - this.ageBands.indexOf(b.key))
                : list.sort((a, b) => b.accounts - a.accounts || b.added - a.added || a.label.localeCompare(b.label));
        },

        rowRate(row) {
            return row.tried > 0 ? `${Math.round((100 * row.added) / row.tried)}%` : '—';
        },

        rowTone(row) {
            if (row.tried < QUIET_MIN_TRIED) {
                return 'text-gray-500';
            }

            return row.added / row.tried < QUIET_SHARE ? 'text-error-600 dark:text-error-400' : 'text-success-700 dark:text-success-400';
        },

        isPicked(row) {
            return this.picked !== null && this.picked.by === this.statsBy && this.picked.key === row.key;
        },

        pick(row) {
            this.picked = this.isPicked(row) ? null : { by: this.statsBy, key: row.key, label: row.label };
        },

        unpick() {
            this.picked = null;
        },

        sitsOut(account) {
            return account.held || !!account.rest_until;
        },

        takingPart() {
            return this.accounts.filter((account) => !account.excluded).length;
        },

        dot(account) {
            if (account.excluded) {
                return 'text-gray-300 dark:text-gray-600';
            }

            if (account.held) {
                return 'text-error-500';
            }

            return account.rest_until ? 'text-warning-500' : 'text-success-500';
        },

        /* Why the account sits out: on hold after errors, or resting after a flood until the time Telegram set. */
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

        callsShare(account) {
            return account.cap > 0 ? Math.min(100, Math.round((100 * account.calls_today) / account.cap)) : 0;
        },

        lastStopLine(account) {
            const stop = account.last_stop;

            if (!stop) {
                return '';
            }

            const reason = t.analysis.kinds[stop.kind] ?? this.errorLabel(stop.rpc);
            const calls = stop.calls !== null && stop.calls !== undefined ? t.accounts.after_calls.replace(':n', this.number(stop.calls)) : '';

            return [t.accounts.last_stop.replace(':time', this.time(stop.at)), reason, calls].filter(Boolean).join(' · ');
        },

        canEnable(account) {
            return !account.excluded && (account.held || !!account.rest_until || account.cap_lowered);
        },

        async toggle(account) {
            if (account.listener || this.busy) {
                return;
            }

            this.busy = account.id;

            try {
                const json = await requestJson(`${this.endpoints.index}/${account.id}`, 'PATCH', { excluded: !account.excluded });
                account.excluded = json.excluded;
                notify(json.message);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        async enable(account) {
            if (this.busy) {
                return;
            }

            this.busy = account.id;

            try {
                const json = await requestJson(`${this.endpoints.index}/${account.id}/enable`, 'POST', {});
                notify(json.message);
                await this.reloadState();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        /* The asking goes on the server; while it does, the page looks again every few seconds. */
        startSpamPoll() {
            this.spamPoll ??= setInterval(() => {
                if (document.visibilityState === 'visible' && !this.stages.state.loading) {
                    this.reloadState();
                }
            }, 5000);
        },

        stopSpamPoll() {
            if (this.spamPoll) {
                clearInterval(this.spamPoll);
                this.spamPoll = null;
            }
        },

        destroy() {
            this.stopSpamPoll();
            this.stopStages();
        },

        async askSpam(account) {
            if (this.busy) {
                return;
            }

            this.busy = account.id;

            try {
                const json = await requestJson(`${this.endpoints.index}/${account.id}/spam-check`, 'POST', {});
                notify(json.message);
                this.spamChecking = true;
                this.startSpamPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        async askSpamAll() {
            if (this.busy) {
                return;
            }

            this.busy = 'all';

            try {
                const json = await requestJson(`${this.endpoints.index}/spam-check`, 'POST', {});
                notify(json.message);
                this.spamChecking = true;
                this.startSpamPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        /* What @SpamBot said the last time: free, limited until when, not read, not asked. */
        spamLine(account) {
            const check = account.spam_check;

            if (!check) {
                return t.accounts.spam_never;
            }

            if (check.status === 'free') {
                return t.accounts.spam_free;
            }

            if (check.status === 'limited') {
                return check.until ? t.accounts.spam_limited_until.replace(':time', this.time(check.until)) : t.accounts.spam_limited;
            }

            return check.status === 'failed' ? t.accounts.spam_failed : t.accounts.spam_unknown;
        },

        spamTone(account) {
            const status = account.spam_check?.status;

            if (status === 'free') {
                return 'text-success-700 dark:text-success-400';
            }

            if (status === 'limited') {
                return 'text-error-600 dark:text-error-400';
            }

            return status ? 'text-warning-600 dark:text-warning-400' : 'text-gray-400';
        },
    };
}
