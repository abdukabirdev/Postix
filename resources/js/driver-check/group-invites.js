/*
|--------------------------------------------------------------------------
| Group invites: the overview
|--------------------------------------------------------------------------
|
| What runs now and the buttons that start and stop it; the base in numbers
| (can be added, cannot, tried without an answer, never tried - how many of
| them are processed); what our groups got and lost; today; how the
| accounts stand; the last lines of the run log.
|
| Five stages, the light first: what runs now (and the log), the settings and
| accounts, the groups and today, the base, the day. While a run goes on only
| the first is asked every few seconds; the heavy ones every half a minute.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify, runState } from './group-invites-shared';
import { stages, everyVisible } from './stages';

const FLAGS = ['can', 'cannot', 'tried', 'untried'];

export function groupInvitesPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),
        ...runState(t),
        ...stages({ now: 'loadNow', light: 'loadLight', groups: 'loadGroups', base: 'loadBase', day: 'loadDay' }),

        t,
        endpoints: config.endpoints,
        links: config.links,
        /* A card on the control panel asks for fewer stages than the page. */
        stageList: config.stageList ?? ['now', 'light', 'groups', 'base', 'day'],

        loading: false,
        busy: false,
        error: null,
        /* Filled stage by stage; empty until its stage is in, so that the page can be drawn at once. */
        data: {
            base: null,
            progress: null,
            today: { added: 0, calls: 0, lookups: 0, stops: 0 },
            added: { total: 0, still: 0, left: 0, not_added: 0, failed: 0, already: 0, sent: 0 },
            groups: [],
            current: null,
            accounts: { total: 0, taking_part: 0, resting: 0, held: 0 },
            settings: null,
            times: null,
            last_left_check: null,
            recent: [],
        },
        poll: null,
        slowPoll: null,

        init() {
            this.load();
        },

        destroy() {
            this.stopPoll();
            this.stopStages();
        },

        /* Every stage again, in order. `loading` is up until the first one is in. */
        async load() {
            this.loading = !this.stages.now.loaded;
            this.error = null;

            await this.loadStage('now');

            this.error = this.stages.now.error;
            this.loading = false;

            if (!this.stages.now.error) {
                await this.loadStages(this.stageList.filter((name) => name !== 'now'));
            }
        },

        async loadNow() {
            const json = await requestJson(`${this.endpoints.show}?part=now`);
            const ended = this.running && !json.running;

            this.running = !!json.running;
            this.now = json.now ?? null;
            this.data.recent = json.recent ?? [];
            this.fetchedAt = Date.now();
            this.tick = this.fetchedAt;

            this.running ? this.startPoll() : this.stopPoll();

            /* The run just ended: what it did to the numbers is read once more. */
            if (ended) {
                this.loadStages(this.stageList.filter((name) => ['groups', 'base', 'day'].includes(name)));
            }
        },

        async loadLight() {
            Object.assign(this.data, await requestJson(`${this.endpoints.show}?part=light`));
        },

        async loadGroups() {
            Object.assign(this.data, await requestJson(`${this.endpoints.show}?part=groups`));
        },

        async loadBase() {
            Object.assign(this.data, await requestJson(`${this.endpoints.show}?part=base`));
        },

        async loadDay() {
            Object.assign(this.data, await requestJson(`${this.endpoints.show}?part=day`));
        },

        startPoll() {
            /* The run itself every few seconds, what it did to the numbers every half a minute. */
            this.poll ??= everyVisible(4000, () => {
                if (!this.stages.now.loading) {
                    this.loadStage('now');
                }
            });
            this.slowPoll ??= everyVisible(30000, () => {
                if (!this.anyStageLoading()) {
                    this.loadStages(this.stageList.filter((name) => ['groups', 'day'].includes(name)));
                }
            });
            this.startTicker();
        },

        stopPoll() {
            this.poll?.();
            this.poll = null;
            this.slowPoll?.();
            this.slowPoll = null;

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
                this.loadStage('now');
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

        /* Every account asks @SpamBot about its limits; the answers come to the accounts page. Not a run: nothing to follow here. */
        async askSpamBot() {
            if (this.busy || !this.endpoints.spamCheck) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.spamCheck, 'POST', {});
                notify(json.message);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async stop() {
            if (this.busy || !this.running || !window.confirm(t.stop_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.stop, 'POST', {});
                notify(json.message);
                await this.loadStage('now');
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
        | The day: the ideal one and how far into it
        |--------------------------------------------------------------
        */

        day() {
            return this.data?.progress ?? { max: 0, due: 0, made: 0, tried: 0, added: 0, accounts: 0, batch_size: 0 };
        },

        /* The calls made out of those due; 100 only once every one is made. */
        dayShare() {
            const day = this.day();

            return day.due > 0 ? Math.min(100, (100 * day.made) / day.due) : 0;
        },

        dayMadeLine() {
            const day = this.day();

            return t.overview.progress.made_line
                .replace(':made', this.number(day.made))
                .replace(':due', this.number(day.due))
                .replace(':tried', this.number(day.tried))
                .replace(':added', this.number(day.added));
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
