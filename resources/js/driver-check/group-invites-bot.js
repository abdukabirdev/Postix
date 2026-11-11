/*
|--------------------------------------------------------------------------
| Group invites: our join request bot
|--------------------------------------------------------------------------
|
| The bot approves every request to join a group it administers and writes
| who asked into the base. Our own accounts ask too (by the bot's own link,
| when not in the group yet): they are let in, not written. Here: the token,
| the switch, where the bot reports what it does, whether it is asking
| Telegram right now, where it is an admin, and the requests it took.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

/** The page asks again this often while it is open, ms. */
const REFRESH = 15000;

const GROUP_TONES = {
    ok: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    no_right: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    no_restrict: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    no_delete: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    not_admin: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
    absent: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400',
};

export function groupInvitesBotPage(config) {
    const t = config.translations;
    const b = t.bot;

    return {
        ...inviteHelpers(t),

        t,
        b,
        endpoints: config.endpoints,

        loading: false,
        loaded: false,
        error: null,
        data: null,
        kind: 'all',

        groups: [],
        groupsLoading: false,
        groupsLoaded: false,

        token: '',
        replacing: false,
        busy: false,
        timer: null,

        /* The reports form: filled from the first answer and after a save, not by the refreshes in between. */
        reportChat: '',
        reportOn: true,
        reportFilled: false,

        init() {
            this.load();

            /* The groups are asked for live, one call to Telegram each: only for a page that shows them. */
            if (config.withGroups) {
                this.loadGroups();
            }

            this.timer = setInterval(() => {
                if (document.visibilityState === 'visible' && !this.busy) {
                    this.load(true);
                }
            }, REFRESH);
        },

        destroy() {
            clearInterval(this.timer);
        },

        async load(quiet = false) {
            if (this.loading) {
                return;
            }

            this.loading = !quiet;
            this.error = null;

            try {
                const params = this.kind === 'all' ? '' : `?kind=${this.kind}`;
                this.data = await requestJson(this.endpoints.show + params);
                this.loaded = true;

                if (!this.reportFilled) {
                    this.reportChat = this.reports.chat_id ?? '';
                    this.reportOn = this.reports.enabled || !this.reports.chat_id;
                    this.reportFilled = true;
                }
            } catch (error) {
                if (!quiet) {
                    this.error = error?.message || t.load_failed;
                }
            } finally {
                this.loading = false;
            }
        },

        async loadGroups() {
            if (this.groupsLoading) {
                return;
            }

            this.groupsLoading = true;

            try {
                this.groups = (await requestJson(this.endpoints.groups)).groups ?? [];
                this.groupsLoaded = true;
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.groupsLoading = false;
            }
        },

        refresh() {
            this.load();

            if (config.withGroups) {
                this.loadGroups();
            }
        },

        get bot() {
            return this.data?.bot ?? { connected: false };
        },

        get reports() {
            return this.data?.reports ?? { chat_id: null, enabled: false };
        },

        /* Whether the reports get through: the last one arrived, they do not, none yet, off. */
        reportState() {
            const r = this.reports;
            const rb = b.reports;

            if (!r.enabled) {
                return { tone: 'bg-gray-400', text: rb.off, bad: false };
            }

            const failing = r.error && (!r.sent_at || new Date(r.error_at) >= new Date(r.sent_at));

            if (failing) {
                return {
                    tone: 'bg-error-500',
                    text: rb.failing.replace(':time', this.time(r.error_at)).replace(':error', b.errors_tg[r.error] ?? r.error),
                    bad: true,
                };
            }

            if (r.sent_at) {
                return { tone: 'bg-success-500', text: rb.sent.replace(':time', this.ago(r.sent_at)), bad: false };
            }

            return { tone: 'bg-gray-300 dark:bg-gray-600', text: rb.never, bad: false };
        },

        async saveReports() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.reports, 'PATCH', {
                    chat_id: String(this.reportChat ?? '').trim() || null,
                    enabled: this.reportOn,
                });
                notify(json.message);
                this.reportFilled = false;
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async testReport() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.reportTest, 'POST');
                notify(json.message);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
                await this.load(true);
            }
        },

        /* The bot as one line: asking now, switched off, or silent. */
        state() {
            const bot = this.bot;

            if (!bot.connected) {
                return { tone: 'bg-gray-300 dark:bg-gray-600', text: b.state.none };
            }

            if (!bot.enabled) {
                return { tone: 'bg-gray-400', text: b.state.off };
            }

            if (bot.alive) {
                return { tone: 'bg-success-500 animate-pulse', text: b.state.alive.replace(':time', this.ago(bot.polled_at)) };
            }

            return {
                tone: 'bg-error-500',
                text: bot.polled_at ? b.state.silent.replace(':time', this.ago(bot.polled_at)) : b.state.never,
            };
        },

        ago(iso) {
            const date = new Date(iso);

            return Number.isNaN(date.getTime()) ? '' : this.duration((Date.now() - date.getTime()) / 1000);
        },

        kinds() {
            return ['all', 'people', 'ours', 'failed'].map((value) => ({ value, label: b.kinds[value] }));
        },

        setKind(value) {
            this.kind = value;
            this.load();
        },

        groupLabel(group) {
            return b.group_states[group.state] ?? group.state;
        },

        groupTone(group) {
            return GROUP_TONES[group.state] ?? 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300';
        },

        groupsReady() {
            return this.groups.filter((group) => group.state === 'ok').length;
        },

        who(row) {
            if (row.is_ours) {
                return b.our_account.replace(':phone', this.phone(row.account) || '');
            }

            return row.name || (row.username ? '@' + row.username : '#' + row.telegram_user_id);
        },

        whoLine(row) {
            return [row.name && row.username ? '@' + row.username : null, 'ID ' + row.telegram_user_id].filter(Boolean).join(' · ');
        },

        requestError(row) {
            return b.errors_tg[row.error] ?? this.errorLabel(row.error);
        },

        async connect() {
            const token = this.token.trim();

            if (this.busy || token === '') {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.connect, 'PUT', { token });
                this.token = '';
                this.replacing = false;
                notify(json.message);
                await this.load();
                this.loadGroups();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async toggle() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.update, 'PATCH', { enabled: !this.bot.enabled });
                notify(json.message);
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async disconnect() {
            if (this.busy || !window.confirm(b.disconnect_confirm)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.disconnect, 'DELETE');
                notify(json.message);
                await this.load();
                this.loadGroups();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },
    };
}
