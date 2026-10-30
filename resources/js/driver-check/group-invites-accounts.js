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
        limits: { batches_per_day: 0, batch_size: 0, usernames_per_day: 0 },
        view: 'all',

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.index);
                this.accounts = json.accounts ?? [];
                this.limits = json.limits ?? this.limits;
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

        shown() {
            return this.accounts.filter((account) => ({
                working: !account.excluded && !this.sitsOut(account),
                resting: !account.excluded && this.sitsOut(account),
                excluded: account.excluded,
            })[this.view] ?? true);
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
    };
}
