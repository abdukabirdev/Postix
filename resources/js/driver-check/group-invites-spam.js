/*
|--------------------------------------------------------------------------
| Group invites: the spam list
|--------------------------------------------------------------------------
|
| Our join request bot deletes what the people of the list write in our
| groups a few seconds after they wrote it and tells the owner without a
| sound; they stay in the group. Here: who is on the list, how long a
| message stays, whether the owner is told, where the bot may delete, and
| the messages it took.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

/** The page asks again this often while it is open, ms: a waiting message turns "deleted" in front of the owner. */
const REFRESH = 5000;

/* .dc-dot takes the text colour. */
const STATUS_DOTS = {
    pending: 'text-warning-500 animate-pulse',
    deleted: 'text-success-500',
    failed: 'text-error-500',
};

export function groupInvitesSpamPage(config) {
    const t = config.translations;
    const s = t.spam;
    const b = t.bot;

    return {
        ...inviteHelpers(t),

        t,
        s,
        b,
        endpoints: config.endpoints,

        loading: false,
        loaded: false,
        error: null,
        data: null,
        status: 'all',
        busy: false,
        timer: null,

        groups: [],
        groupsLoading: false,
        groupsLoaded: false,

        entries: '',
        note: '',

        /* The settings form: filled from the first answer and after a save, not by the refreshes in between. */
        delay: 15,
        notifyOn: true,
        /* Every message with a link goes too, the owner gets its copy. */
        linksOn: true,
        settingsFilled: false,

        init() {
            this.load();
            this.loadGroups();

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
                const params = this.status === 'all' ? '' : `?status=${this.status}`;
                this.data = await requestJson(this.endpoints.show + params);
                this.loaded = true;

                if (!this.settingsFilled) {
                    this.delay = this.data.settings.delay;
                    this.notifyOn = this.data.settings.notify;
                    this.linksOn = this.data.settings.links;
                    this.settingsFilled = true;
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
            this.loadGroups();
        },

        get bot() {
            return this.data?.bot ?? { connected: false };
        },

        /* The bot as one line: watching, switched off, silent, not there. */
        botState() {
            const bot = this.bot;

            if (!bot.connected) {
                return { tone: 'text-error-500', text: s.bot_none, bad: true };
            }

            if (!bot.enabled) {
                return { tone: 'text-error-500', text: s.bot_off, bad: true };
            }

            if (!bot.alive) {
                return { tone: 'text-warning-500', text: s.bot_silent, bad: true };
            }

            return { tone: 'text-success-500 animate-pulse', text: s.bot_ok.replace(':bot', '@' + bot.username), bad: false };
        },

        /* Telegram knows whether the bot may delete there; a group not seen by the bot yet is not known. */
        groupDelete(group) {
            if (group.can_delete === true) {
                return { text: s.can_delete, tone: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' };
            }

            if (group.can_delete === false) {
                const text = ['absent', 'not_admin'].includes(group.state) ? b.group_states[group.state] : s.cannot_delete;

                return { text, tone: 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400' };
            }

            return {
                text: b.group_states[group.state] ?? s.group_unknown,
                tone: 'bg-gray-100 text-gray-600 dark:bg-white/[0.07] dark:text-gray-300',
            };
        },

        groupsReady() {
            return this.groups.filter((group) => group.can_delete === true).length;
        },

        /* Forward a spammer's message to the bot: its author goes onto the list. */
        forwardHint() {
            if (!this.bot.connected) {
                return s.bot_none;
            }

            return s.forward_hint.replace(':bot', '@' + this.bot.username);
        },

        /* You and N admins; nobody - nobody may yet. */
        forwardWho() {
            const { owner, admins } = this.data.forward;

            if (!owner && !admins) {
                return s.forward_nobody;
            }

            return s.forward_who.replace(':n', this.number(admins + (owner ? 1 : 0)));
        },

        statuses() {
            return ['all', 'pending', 'deleted', 'failed'].map((value) => ({ value, label: s.statuses[value] }));
        },

        setStatus(value) {
            this.status = value;
            this.load();
        },

        /* Ali Valiyev, else @ali, else the id. */
        who(row) {
            return row.name || (row.username ? '@' + row.username : row.telegram_user_id ? 'ID ' + row.telegram_user_id : '');
        },

        /* @ali · ID 501 under the name; only what the first line did not say. */
        whoLine(row) {
            return [
                row.name && row.username ? '@' + row.username : null,
                (row.name || row.username) && row.telegram_user_id ? 'ID ' + row.telegram_user_id : null,
            ].filter(Boolean).join(' · ');
        },

        senderStats(sender) {
            const parts = [s.deleted_n.replace(':n', this.number(sender.deleted))];

            if (sender.pending > 0) {
                parts.push(s.pending_n.replace(':n', this.number(sender.pending)));
            }

            parts.push(sender.last_deleted_at ? s.last_deleted.replace(':time', this.time(sender.last_deleted_at)) : s.never_deleted);

            return parts.join(' · ');
        },

        statusDot(row) {
            return STATUS_DOTS[row.status] ?? 'text-gray-300';
        },

        statusText(row) {
            if (row.status === 'pending') {
                return s.status_pending.replace(':time', this.clock(row.delete_at));
            }

            if (row.status === 'deleted') {
                return s.status_deleted.replace(':time', this.clock(row.removed_at));
            }

            return s.status_failed.replace(':error', b.errors_tg[row.error] ?? this.errorLabel(row.error));
        },

        kindLabel(row) {
            return row.kind === 'text' ? '' : s.kinds[row.kind] ?? row.kind;
        },

        async add() {
            if (this.busy || this.entries.trim() === '') {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.store, 'POST', {
                    entries: this.entries,
                    note: this.note.trim() || null,
                });
                notify(json.message, (json.invalid ?? []).length === 0);

                /* What was not understood stays in the box to be put right. */
                this.entries = (json.invalid ?? []).join('\n');
                this.note = '';
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async remove(sender) {
            const name = this.who(sender) || '#' + sender.id;

            if (this.busy || !window.confirm(s.remove_confirm.replace(':who', name))) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.destroy.replace('__ID__', sender.id), 'DELETE');
                notify(json.message);
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        async saveSettings() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.settings, 'PATCH', {
                    delay: Number(this.delay),
                    notify: this.notifyOn,
                    links: this.linksOn,
                });
                notify(json.message);
                this.settingsFilled = false;
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },
    };
}
