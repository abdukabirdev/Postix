/*
|--------------------------------------------------------------------------
| Group invites: what the pages of the section share
|--------------------------------------------------------------------------
|
| Telegram's codes in words, times, the five places a person can stand in
| one group, the four a person stands in the base, the run log in words,
| and "what runs now" - the overview and the log page both show it.
|
*/

import { formatNumber, formatPhone } from './format';

export const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

const pad = (n) => String(n).padStart(2, '0');

/** Where a person stands in the base: the badge tone of each. */
export const FLAG_TONES = {
    can: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    cannot: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
    tried: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    untried: 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300',
};

/** The same four as a solid mark: the bar segments and the legend dots. */
export const FLAG_FILLS = {
    can: 'bg-success-500',
    cannot: 'bg-error-500',
    tried: 'bg-warning-500',
    untried: 'bg-gray-300 dark:bg-gray-600',
};

/** Where a person stands in one group. */
export const STATUS_TONES = {
    invited: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    left: 'bg-gray-900 text-white dark:bg-white dark:text-gray-900',
    not_added: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    failed: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
};

/**
 * The helpers every page of the section spreads in.
 */
export function inviteHelpers(t) {
    return {
        number(value) {
            return formatNumber(value);
        },

        phone(value) {
            return formatPhone(value) || value || '';
        },

        percent(part, all) {
            return all > 0 ? Math.round((100 * part) / all) : 0;
        },

        /* 12:40 today, 29.10 12:40 another day. */
        time(iso) {
            const date = new Date(iso);

            if (!iso || Number.isNaN(date.getTime())) {
                return '';
            }

            const clock = `${pad(date.getHours())}:${pad(date.getMinutes())}`;

            return date.toDateString() === new Date().toDateString()
                ? clock
                : `${pad(date.getDate())}.${pad(date.getMonth() + 1)} ${clock}`;
        },

        /* HH:MM:SS for a log line. */
        clock(iso) {
            const date = new Date(iso);

            return Number.isNaN(date.getTime())
                ? ''
                : `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        },

        /* 30.10.2026 */
        day(iso) {
            const date = new Date(iso);

            return Number.isNaN(date.getTime()) ? '' : `${pad(date.getDate())}.${pad(date.getMonth() + 1)}.${date.getFullYear()}`;
        },

        /* 45 s, 12 min, 1 h 5 min */
        duration(seconds) {
            const u = t.now.units;
            const s = Math.max(0, Math.round(Number(seconds) || 0));

            if (s < 60) {
                return `${s} ${u.s}`;
            }

            if (s < 3600) {
                return `${Math.floor(s / 60)} ${u.m}`;
            }

            const m = Math.floor((s % 3600) / 60);

            return `${Math.floor(s / 3600)} ${u.h}` + (m ? ` ${m} ${u.m}` : '');
        },

        /*
         * Telegram's code in words; a group's own @name has its own text, a
         * check that could not be made says why.
         */
        errorLabel(code, forGroup = false) {
            if (!code) {
                return '';
            }

            const wait = /^FLOOD_(?:PREMIUM_)?WAIT_(\d+)/.exec(code);

            if (wait) {
                return t.errors.flood_wait.replace(':n', wait[1]);
            }

            if (code.startsWith('CHECK_') && !t.errors[code]) {
                return t.errors.check_failed.replace(':reason', this.errorLabel(code.slice(6)));
            }

            return (forGroup && t.errors[`${code}_GROUP`]) || t.errors[code] || code;
        },

        flagLabel(flag) {
            return t.flags[flag] ?? flag;
        },

        flagTone(flag) {
            return FLAG_TONES[flag] ?? FLAG_TONES.untried;
        },

        flagFill(flag) {
            return FLAG_FILLS[flag] ?? FLAG_FILLS.untried;
        },

        statusLabel(status) {
            return t.statuses[status] ?? status;
        },

        statusTone(status) {
            return STATUS_TONES[status] ?? 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300';
        },

        /* added 3 · not added 10 · cannot 2 ... - only what is not zero. */
        summaryText(counts) {
            const parts = ['invited', 'not_added', 'failed', 'already', 'sent', 'stayed', 'left', 'back']
                .filter((key) => Number(counts?.[key] ?? 0) > 0)
                .map((key) => t.log.summary[key].replace(':n', this.number(counts[key])));

            return parts.length ? parts.join(' · ') : t.log.summary.nothing;
        },

        modeLabel(run) {
            if (run?.left_check) {
                return t.now.mode_left;
            }

            return run?.check_only ? t.now.mode_check : t.now.mode_invite;
        },

        /*
        |--------------------------------------------------------------
        | The run log in words
        |--------------------------------------------------------------
        */

        lineText(line) {
            const c = line.context || {};
            const e = t.log.events;
            const trigger = c.trigger === 'schedule' ? t.now.trigger_schedule : t.now.trigger_manual;

            switch (line.event) {
                case 'started':
                    return (c.left_check ? e.started_left : (c.check_only ? e.started_check : e.started)).replace(':trigger', trigger);
                case 'finished':
                    if (c.reason === 'no_groups') {
                        return e.no_groups;
                    }

                    return (c.stopped ? e.finished_stopped : e.finished).replace(':summary', this.summaryText(c));
                case 'skipped':
                    if (c.reason === 'no_accounts') {
                        return e.no_accounts;
                    }

                    if (c.reason === 'denied') {
                        return e.denied.replace(':reason', this.errorLabel(c.rpc));
                    }

                    if (c.reason === 'group_off') {
                        return e.group_off.replace(':reason', this.errorLabel(c.rpc, true));
                    }

                    if (c.reason === 'group_left') {
                        return e.group_left.replace(':n', c.accounts ?? '?').replace(':reason', this.errorLabel(c.rpc));
                    }

                    if (c.reason === 'list_failed') {
                        return e.list_failed.replace(':reason', this.errorLabel(c.rpc));
                    }

                    return line.message;
                case 'batch': {
                    const error = c.error ? String(c.error) : null;

                    if (error && error.endsWith(', split')) {
                        return e.batch_split.replace(':size', c.size ?? '?').replace(':error', this.errorLabel(error.replace(', split', '')));
                    }

                    const text = (c.via === 'username' ? e.batch_username : e.batch)
                        .replace(':size', c.size ?? '?')
                        .replace(':summary', this.summaryText(c));

                    return error ? `${text} · ${this.errorLabel(error)}` : text;
                }
                case 'check':
                    if (c.mode === 'left' && c.list) {
                        return (c.whole ? e.check_left_list : e.check_left_part)
                            .replace(':read', this.number(c.read ?? 0))
                            .replace(':members', c.members === null || c.members === undefined ? '?' : this.number(c.members))
                            .replace(':unchecked', this.number(c.unchecked ?? 0))
                            .replace(':summary', this.summaryText(c));
                    }

                    if (c.mode === 'left') {
                        return e.check_left
                            .replace(':done', this.number(c.done ?? 0))
                            .replace(':of', this.number(c.of ?? 0))
                            .replace(':stayed', this.number(c.stayed ?? 0))
                            .replace(':left', this.number(c.left ?? 0));
                    }

                    return e.check
                        .replace(':done', this.number(c.done ?? c.size ?? 0))
                        .replace(':of', this.number(c.of ?? c.size ?? 0))
                        .replace(':summary', this.summaryText(c));
                case 'members': {
                    const grew = c.grew === null || c.grew === undefined ? '' : ` (${c.grew > 0 ? '+' : ''}${c.grew})`;

                    return e.members.replace(':before', c.before ?? '?').replace(':after', c.after ?? '?') + grew;
                }
                case 'wait':
                    return e.wait.replace(':n', c.seconds ?? '?');
                case 'flood':
                    return e.flood.replace(':time', this.time(c.until)).replace(':reason', this.errorLabel(c.rpc));
                case 'rest':
                    return e.rest.replace(':time', this.time(c.until));
                case 'disabled':
                    return e.disabled.replace(':reason', this.errorLabel(c.rpc || line.message));
                case 'failed':
                    return e.failed.replace(':reason', line.message);
                case 'stop':
                    return e.stop;
                default:
                    return line.message;
            }
        },

        lineTone(line) {
            const c = line.context || {};

            switch (line.event) {
                case 'failed':
                case 'disabled':
                    return 'bad';
                case 'skipped':
                    return c.reason === 'group_off' ? 'bad' : 'warn';
                case 'flood':
                case 'rest':
                case 'wait':
                case 'stop':
                    return 'warn';
                case 'batch':
                case 'check':
                    if (c.mode === 'left') {
                        return Number(c.left ?? 0) > 0 ? 'warn' : 'ok';
                    }

                    if (Number(c.invited ?? 0) > 0) {
                        return 'ok';
                    }

                    return Number(c.not_added ?? 0) > 0 || (line.event === 'batch' && c.error) ? 'warn' : 'muted';
                case 'members':
                    return Number(c.grew ?? 0) > 0 ? 'ok' : 'muted';
                case 'finished':
                    return c.stopped ? 'warn' : 'ok';
                default:
                    return 'muted';
            }
        },

        toneClass(tone) {
            return {
                ok: 'text-success-500',
                warn: 'text-warning-500',
                bad: 'text-error-500',
            }[tone] ?? 'text-gray-300 dark:text-gray-600';
        },
    };
}

/**
 * "What runs now": the step, where, how far, until when, how long it has
 * run, whether it has gone silent. The page keeps `now`, `running`,
 * `fetchedAt` and `tick`; the ticker moves the countdowns between polls.
 */
export function runState(t) {
    return {
        now: null,
        running: false,
        fetchedAt: Date.now(),
        tick: Date.now(),
        ticker: null,

        startTicker() {
            this.ticker ??= setInterval(() => {
                this.tick = Date.now();
            }, 1000);
        },

        stopTicker() {
            if (this.ticker) {
                clearInterval(this.ticker);
                this.ticker = null;
            }
        },

        doingLabel() {
            /* The lock is held, the run has not written anything yet. */
            if (!this.now?.running) {
                return t.now.doing.starting;
            }

            const step = this.now.step || {};

            return (t.now.doing[this.now.doing] ?? this.now.doing ?? '')
                .replace(':done', this.number(step.done ?? 0))
                .replace(':size', this.number(step.size ?? 0));
        },

        whereLabel() {
            return this.now?.running ? [this.phone(this.now.account), this.now.group].filter(Boolean).join(' · ') : '';
        },

        /* Seconds until the step's "until", counted down by the page's own clock. */
        secondsLeft() {
            const until = this.now?.running ? this.now.step?.until : null;

            return until ? Math.max(0, Math.round((new Date(until).getTime() - this.tick) / 1000)) : null;
        },

        untilLabel() {
            const left = this.secondsLeft();

            return left === null ? '' : t.now.left.replace(':time', this.duration(left));
        },

        progress() {
            const step = this.now?.running ? this.now.step || {} : {};

            return step.size > 0 ? Math.min(100, Math.round((100 * (step.done ?? 0)) / step.size)) : null;
        },

        secondsSince(iso) {
            const at = new Date(iso).getTime();

            return Number.isNaN(at) ? 0 : Math.max(0, Math.round((this.tick - at) / 1000));
        },

        /* Seconds since the run last wrote what it does. */
        silentFor() {
            if (this.now?.silent_for === null || this.now?.silent_for === undefined) {
                return null;
            }

            return this.now.silent_for + Math.round((this.tick - this.fetchedAt) / 1000);
        },

        /* Three minutes without a word outside a known wait: it hangs. */
        silent() {
            const silent = this.silentFor();

            return this.running && this.now?.running && silent !== null && silent > 180 && !(this.secondsLeft() > 0);
        },

        silentLabel() {
            return t.now.silent
                .replace(':time', this.duration(this.silentFor() ?? 0))
                .replace(':pid', this.now?.pid ?? '?');
        },

        clockLine() {
            if (!this.now?.running) {
                return '';
            }

            const parts = [t.now.running_for.replace(':time', this.duration(this.secondsSince(this.now.started_at)))];
            const silent = this.silentFor();

            if (silent !== null) {
                parts.push(t.now.heartbeat.replace(':time', this.duration(silent)));
            }

            if (this.now.pid) {
                parts.push(`PID ${this.now.pid}`);
            }

            return parts.join(' · ');
        },

        lastRunLabel() {
            if (!this.now) {
                return t.now.never;
            }

            const parts = [t.now.last.replace(':start', this.time(this.now.started_at)), this.modeLabel(this.now)];

            if (this.now.finished_at) {
                const took = Math.max(0, Math.round((new Date(this.now.finished_at) - new Date(this.now.started_at)) / 1000));
                parts.push(t.now.took.replace(':time', this.duration(took)));
            } else {
                parts.push(t.now.broken);
            }

            if (this.now.stopped) {
                parts.push(t.now.last_stopped);
            }

            return parts.join(' · ');
        },
    };
}
