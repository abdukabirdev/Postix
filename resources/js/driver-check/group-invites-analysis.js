/*
|--------------------------------------------------------------------------
| Group invites: the analysis
|--------------------------------------------------------------------------
|
| Over 7 / 14 / 30 / 90 days: what the invites did, day by day; why the
| accounts were stopped and after how many calls each kind of stop comes;
| every stop with what came before it; what each group got and lost; a row
| per day per account. The stops and the days export to CSV.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

export function groupInvitesAnalysisPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),

        t,
        endpoints: config.endpoints,

        loading: false,
        error: null,
        data: null,
        days: 30,
        tab: 'stops',
        hover: null,
        account: '',

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                this.data = await requestJson(`${this.endpoints.show}?days=${this.days}`);
            } catch (error) {
                this.error = error?.message || t.load_failed;
                notify(this.error, false);
            } finally {
                this.loading = false;
            }
        },

        periods() {
            return (this.data?.periods ?? [7, 14, 30, 90]).map((days) => ({ value: days, label: t.analysis.period.replace(':n', days) }));
        },

        setPeriod(value) {
            this.days = value;
            this.load();
        },

        exportUrl(type) {
            return `${this.endpoints.export}?type=${type}&days=${this.days}`;
        },

        tabs() {
            return [
                { value: 'stops', label: `${t.analysis.tabs.stops} · ${this.number(this.data?.stops?.length ?? 0)}` },
                { value: 'days', label: t.analysis.tabs.days },
                { value: 'groups', label: t.analysis.tabs.groups },
            ];
        },

        setTab(value) {
            this.tab = value;
        },

        /*
        |--------------------------------------------------------------
        | The trend: people added, day by day
        |--------------------------------------------------------------
        */

        trend() {
            return this.data?.trend ?? [];
        },

        trendMax() {
            return Math.max(1, ...this.trend().map((point) => point.added));
        },

        barHeight(point) {
            return point.added > 0 ? Math.max(3, (100 * point.added) / this.trendMax()) : 0;
        },

        shortDay(iso) {
            const [, month, day] = String(iso).split('-');

            return `${day}.${month}`;
        },

        /* Every few days a label under the bars, so they never collide. */
        showsLabel(index) {
            const step = Math.ceil(this.trend().length / 10);

            return index % step === 0 || index === this.trend().length - 1;
        },

        hoverLine(point) {
            return t.analysis.trend_tooltip
                .replace(':added', this.number(point.added))
                .replace(':not_added', this.number(point.not_added))
                .replace(':failed', this.number(point.failed))
                .replace(':left', this.number(point.left))
                .replace(':calls', this.number(point.calls))
                .replace(':stops', this.number(point.stops));
        },

        /*
        |--------------------------------------------------------------
        | Stops and days
        |--------------------------------------------------------------
        */

        kindLabel(kind) {
            return t.analysis.kinds[kind] ?? kind;
        },

        kindHint(kind) {
            return t.analysis.kind_hints[kind] ?? '';
        },

        kindTone(kind) {
            return {
                spam: 'text-error-600 dark:text-error-400',
                dead: 'text-error-600 dark:text-error-400',
                wait: 'text-warning-600 dark:text-warning-400',
                empty: 'text-gray-600 dark:text-gray-300',
            }[kind] ?? 'text-gray-600';
        },

        rpcLine(rpcs) {
            return Object.entries(rpcs ?? {})
                .map(([code, n]) => `${this.errorLabel(code)} × ${n}`)
                .join(' · ');
        },

        accounts() {
            const phones = new Set((this.data?.days ?? []).map((row) => row.account).filter(Boolean));

            return [...phones].sort();
        },

        dayRows() {
            const rows = this.data?.days ?? [];

            return this.account ? rows.filter((row) => row.account === this.account) : rows;
        },

        stopWhat(stop) {
            const c = stop.counters ?? {};
            const step = c.step ? (t.analysis.steps[c.step] ?? c.step) : '';
            const via = c.via === 'username' ? t.analysis.via_username : '';

            return [step, via, c.size ? t.analysis.size.replace(':n', c.size) : ''].filter(Boolean).join(' · ');
        },
    };
}
