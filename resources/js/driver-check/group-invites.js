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
                ? t.overview.schedule_on.replace(':time', settings.schedule_at)
                : t.overview.schedule_off;
        },
    };
}
