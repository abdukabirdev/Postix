/*
|--------------------------------------------------------------------------
| Group invites: the run log
|--------------------------------------------------------------------------
|
| One run's log in words, newest line first; any of the last runs can be
| picked. While a run goes on, the latest one follows it by itself, with a
| strip on top that says what it does this moment.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify, runState } from './group-invites-shared';

export function groupInvitesLogPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),
        ...runState(t),

        t,
        endpoints: config.endpoints,

        loading: false,
        runs: [],
        runId: '',
        picked: false,
        lines: [],
        filter: 'all',
        poll: null,

        init() {
            this.load();
        },

        destroy() {
            this.stopPoll();
        },

        async load() {
            this.loading = true;

            const params = new URLSearchParams();

            if (this.picked && this.runId) {
                params.set('run_id', this.runId);
            }

            try {
                const [log, overview] = await Promise.all([
                    requestJson(`${this.endpoints.logs}?${params}`),
                    requestJson(this.endpoints.show),
                ]);

                this.runs = log.runs ?? [];
                this.lines = log.lines ?? [];
                this.runId = log.run_id ?? '';

                this.running = !!overview.running;
                this.now = overview.now ?? null;
                this.fetchedAt = Date.now();
                this.tick = this.fetchedAt;

                this.running ? this.startPoll() : this.stopPoll();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
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

        pick() {
            this.picked = this.runId !== (this.runs[0]?.run_id ?? '');
            this.load();
        },

        filters() {
            return Object.entries(t.log.filters).map(([value, label]) => ({ value, label }));
        },

        setFilter(value) {
            this.filter = value;
        },

        shown() {
            if (this.filter === 'work') {
                return this.lines.filter((line) => ['batch', 'check', 'members'].includes(line.event));
            }

            if (this.filter === 'problems') {
                return this.lines.filter((line) => ['bad', 'warn'].includes(this.lineTone(line)));
            }

            return this.lines;
        },

        current() {
            return this.runs.find((run) => run.run_id === this.runId) ?? null;
        },

        runLabel(run) {
            let label = t.log.run.replace(':date', this.time(run.started_at)).replace(':mode', this.modeLabel(run));

            if (!run.finished_at && run.run_id === this.runs[0]?.run_id && this.running) {
                label += ` · ${t.log.run_running}`;
            } else if (run.stopped) {
                label += ` · ${t.log.run_stopped}`;
            }

            return label;
        },

        runSummary(run) {
            if (!run) {
                return '';
            }

            const parts = [run.trigger === 'schedule' ? t.now.trigger_schedule : t.now.trigger_manual];

            if (run.finished_at) {
                const took = Math.max(0, Math.round((new Date(run.finished_at) - new Date(run.started_at)) / 1000));
                parts.push(t.now.took.replace(':time', this.duration(took)));
            }

            if (run.totals) {
                parts.push(this.summaryText(run.totals));
            }

            return parts.join(' · ');
        },
    };
}
