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
};

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
        /* The table of how well they add: by country or by age; a row picked narrows the cards to it. */
        statsBy: 'country',
        picked: null,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.index);
                this.accounts = (json.accounts ?? []).map((account) => ({
                    ...account,
                    recent: account.recent ?? { tried: 0, added: 0, not_added: 0 },
                }));
                this.limits = json.limits ?? this.limits;
                this.recentDays = json.recent_days ?? this.recentDays;
                this.ageBands = json.age_bands ?? this.ageBands;
                this.spamChecking = !!json.spam_checking;
                this.spamChecking ? this.startSpamPoll() : this.stopSpamPoll();
                this.loaded = true;
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
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
                })[this.view] ?? true)
                .filter((account) => this.quality === 'all' || this.qualityOf(account) === this.quality)
                .filter((account) => this.picked === null || this.groupOf(account, this.picked.by) === this.picked.key)
                .filter((account) => this.matches(account))
                .sort((a, b) => (SORTS[this.filters.sort] ?? SORTS.id)(a, b) || a.id - b.id);
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
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = null;
            }
        },

        /* The asking goes on the server; while it does, the page looks again every few seconds. */
        startSpamPoll() {
            this.spamPoll ??= setInterval(() => {
                if (document.visibilityState === 'visible' && !this.loading) {
                    this.load();
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
