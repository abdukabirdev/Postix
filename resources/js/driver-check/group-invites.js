/*
|--------------------------------------------------------------------------
| Group invites: the overview
|--------------------------------------------------------------------------
|
| What runs now and the buttons that start and stop it; the base in numbers
| (can be added, cannot, tried without an answer, never tried - how many of
| them are processed); what our groups got and lost; today; how the
| accounts stand; the last lines of the run log. Polls while a run goes on.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify, runState } from './group-invites-shared';

const FLAGS = ['can', 'cannot', 'tried', 'untried'];

export function groupInvitesPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),
        ...runState(t),

        t,
        endpoints: config.endpoints,
        links: config.links,

        loading: false,
        busy: false,
        error: null,
        data: null,
        poll: null,

        init() {
            this.load();
        },

        destroy() {
            this.stopPoll();
        },

        async load() {
            this.loading = this.data === null;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.show);

                this.running = !!json.running;
                this.now = json.now ?? null;
                this.fetchedAt = Date.now();
                this.tick = this.fetchedAt;
                this.data = json;

                this.running ? this.startPoll() : this.stopPoll();
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
        },

        startPoll() {
            this.poll ??= setInterval(() => this.load(), 4000);
            this.startTicker();
        },

        stopPoll() {
            if (this.poll) {
                clearInterval(this.poll);
                this.poll = null;
            }

            this.stopTicker();
        },

        async start(endpoint, confirmText = null) {
            if (this.busy || this.running || (confirmText && !window.confirm(confirmText))) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(endpoint, 'POST', {});
                notify(json.message);
                this.running = true;
                this.startPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        run() {
            this.start(this.endpoints.run);
        },

        check() {
            this.start(this.endpoints.check);
        },

        checkLeft() {
            this.start(this.endpoints.checkLeft, t.overview.left_confirm);
        },

        async stop() {
            if (this.busy || !this.running || !window.confirm(t.stop_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.stop, 'POST', {});
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        /*
        |--------------------------------------------------------------
        | The base
        |--------------------------------------------------------------
        */

        base() {
            return this.data?.base ?? { total: 0, processed: 0, can: 0, cannot: 0, tried: 0, untried: 0, untried_reachable: 0, untried_unreachable: 0 };
        },

        /* The four parts of the bar, in a fixed order; an empty one takes no room. */
        segments() {
            const base = this.base();

            return FLAGS.map((flag) => ({
                flag,
                value: base[flag],
                share: base.total > 0 ? (100 * base[flag]) / base.total : 0,
            }));
        },

        segmentTitle(segment) {
            return `${this.flagLabel(segment.flag)}: ${this.number(segment.value)} (${this.percent(segment.value, this.base().total)}%)`;
        },

        peopleLink(params) {
            return `${this.links.people}?${new URLSearchParams(params)}`;
        },

        /*
        |--------------------------------------------------------------
        | The day in people
        |--------------------------------------------------------------
        */

        day() {
            return this.data?.progress ?? {
                max: 0, done: 0, added: 0, expected: 0, lost: 0, lost_by: {}, accounts: {}, batch_size: 0,
                runs_left: 0, next_run: null, schedule_on: true, has_group: true,
            };
        },

        /* Done, still to come, most likely not: the three add up to the most of the day. */
        dayParts() {
            const day = this.day();
            const p = t.overview.progress;

            return [
                { key: 'done', label: p.done, value: day.done, fill: 'bg-success-500' },
                { key: 'expected', label: p.expected, value: day.expected, fill: 'bg-brand-300 dark:bg-brand-500/60' },
                { key: 'lost', label: p.lost, value: day.lost, fill: 'bg-error-300 dark:bg-error-500/50' },
            ].map((part) => ({ ...part, share: day.max > 0 ? (100 * part.value) / day.max : 0 }));
        },

        /* The biggest reason first; none that took nothing. */
        lostReasons() {
            const by = this.day().lost_by ?? {};

            return ['resting', 'held', 'schedule', 'partial', 'queue']
                .map((key) => ({ key, value: Number(by[key] ?? 0) }))
                .filter((reason) => reason.value > 0)
                .sort((a, b) => b.value - a.value);
        },

        expectedLine() {
            const day = this.day();
            const p = t.overview.progress;

            if (!day.schedule_on || day.runs_left === 0) {
                return p.people.replace(':n', this.number(day.expected)) + ' · ' + p.no_runs;
            }

            return p.expected_line
                .replace(':n', this.number(day.expected))
                .replace(':runs', this.number(day.runs_left))
                .replace(':time', this.time(day.next_run));
        },

        dayAccountsLine() {
            const a = this.day().accounts ?? {};

            return t.overview.progress.accounts
                .replace(':n', this.number(a.accounts ?? 0))
                .replace(':working', this.number(a.working ?? 0))
                .replace(':resting', this.number(a.resting ?? 0))
                .replace(':held', this.number(a.held ?? 0))
                .replace(':capped', this.number(a.capped ?? 0));
        },

        /*
        |--------------------------------------------------------------
        | Groups, accounts, the last "who left"
        |--------------------------------------------------------------
        */

        /* Every group not archived: the current one first. */
        activeGroups() {
            const current = this.data?.current?.id;

            return [...(this.data?.groups ?? [])].sort((a, b) => (b.id === current) - (a.id === current));
        },

        /* Who is still left to try for the group: the proven first, then the rest - or the proven alone. */
        queueLine(group) {
            if (!group?.queue) {
                return '';
            }

            const { proven, other } = group.queue;

            if (this.data?.settings?.audience === 'proven') {
                return t.groups.queue_proven_only
                    .replace(':proven', this.number(proven))
                    .replace(':other', this.number(other));
            }

            return t.groups.queue
                .replace(':n', this.number(proven + other))
                .replace(':proven', this.number(proven))
                .replace(':other', this.number(other));
        },

        leftShare(group) {
            return group.added > 0 ? this.percent(group.left, group.added) : 0;
        },

        lastLeftLabel() {
            const last = this.data?.last_left_check;

            if (!last) {
                return t.overview.left_never;
            }

            return t.overview.left_last
                .replace(':time', this.time(last.at))
                .replace(':left', this.number(last.left))
                .replace(':stayed', this.number(last.stayed));
        },

        audienceLabel() {
            return t.settings.audiences[this.data?.settings?.audience ?? 'all'] ?? '';
        },

        scheduleLabel() {
            const settings = this.data?.settings;

            if (!settings) {
                return '';
            }

            return settings.enabled
                ? t.overview.schedule_on.replace(':time', (this.data.times ?? [settings.schedule_at]).join(', '))
                : t.overview.schedule_off;
        },

        /* 20 at a time, up to 80 times a day, up to 20 in one run. */
        paceLabel() {
            const settings = this.data?.settings;

            if (!settings) {
                return '';
            }

            const share = Math.ceil(Number(settings.batches_per_day) / Math.max(1, Number(settings.runs_per_day) || 1));

            return t.overview.pace_value
                .replace(':size', this.number(settings.batch_size))
                .replace(':times', this.number(settings.batches_per_day))
                .replace(':share', this.number(share));
        },
    };
}
