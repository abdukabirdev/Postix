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
import { everyVisible } from './stages';

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
        /* Narrowing the lines on the screen: words, one account, how many to read. */
        search: '',
        account: '',
        limit: 400,
        poll: null,

        init() {
            this.load();
        },

        destroy() {
            this.stopPoll();
        },

        /*
         * `follow`: the run goes on and the page already has its lines - only
         * the new ones are asked for, not all of them again.
         */
        async load(follow = false) {
            this.loading = !follow;

            const params = new URLSearchParams({ limit: String(this.limit) });

            if (this.picked && this.runId) {
                params.set('run_id', this.runId);
            }

            const after = follow && this.lines.length > 0 ? this.lines[0].id : 0;

            if (after > 0) {
                params.set('after', String(after));
            }

            try {
                const [log, overview] = await Promise.all([
                    requestJson(`${this.endpoints.logs}?${params}`),
                    requestJson(`${this.endpoints.show}?part=now`),
                ]);

                this.runs = log.runs ?? [];

                if (after > 0 && (log.run_id ?? '') === this.runId) {
                    this.lines = [...(log.lines ?? []), ...this.lines].slice(0, Math.max(this.limit, 1000));
                } else {
                    this.lines = log.lines ?? [];
                }

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
            this.poll ??= everyVisible(4000, () => {
                if (!this.loading) {
                    this.load(!this.picked);
                }
            });
            this.startTicker();
        },

        stopPoll() {
            this.poll?.();
            this.poll = null;

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
            const words = this.search.trim().toLowerCase();

            return this.lines.filter((line) => {
                if (this.filter === 'work' && !['batch', 'check', 'members'].includes(line.event)) {
                    return false;
                }

                if (this.filter === 'problems' && !['bad', 'warn'].includes(this.lineTone(line))) {
                    return false;
                }

                if (this.account !== '' && line.account !== this.account) {
                    return false;
                }

                return words === '' || `${this.lineText(line)} ${line.account ?? ''} ${line.group ?? ''}`.toLowerCase().includes(words);
            });
        },

        /* The accounts that wrote lines to this run, for the select. */
        accountsInLog() {
            return [...new Set(this.lines.map((line) => line.account).filter(Boolean))].sort();
        },

        hasFilters() {
            return this.filter !== 'all' || this.search.trim() !== '' || this.account !== '';
        },

        resetFilters() {
            this.filter = 'all';
            this.search = '';
            this.account = '';
        },

        /* The search narrows the lines as it is typed: nothing to send anywhere. */
        applyFilters() {},

        clearSearch() {
            this.search = '';
        },

        /* A different number of lines to read: from the server again. */
        changeLimit() {
            this.load();
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
