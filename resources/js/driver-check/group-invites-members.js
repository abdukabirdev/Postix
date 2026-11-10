/*
|--------------------------------------------------------------------------
| Group invites: who came, who left, who was removed
|--------------------------------------------------------------------------
|
| Every change of a member of our groups as our join request bot saw it:
| came by a link or a request, added by somebody, left, removed and by
| whom. The period in numbers, who removes, whose added people are gone,
| and the events themselves, newest first.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

/* .dc-dot takes the text colour. */
const EVENT_DOTS = {
    joined: 'text-success-500',
    added: 'text-brand-500',
    left: 'text-warning-500',
    removed: 'text-error-500',
    banned: 'text-error-500',
    unbanned: 'text-gray-400',
    restricted: 'text-warning-500',
    unrestricted: 'text-gray-400',
    promoted: 'text-brand-500',
    demoted: 'text-gray-400',
    changed: 'text-gray-400',
};

const EVENT_TONES = {
    joined: 'text-success-700 dark:text-success-400',
    added: 'text-brand-700 dark:text-brand-400',
    left: 'text-warning-700 dark:text-warning-400',
    removed: 'text-error-700 dark:text-error-400',
    banned: 'text-error-700 dark:text-error-400',
};

const TAG_TONES = {
    ours: 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400',
    added: 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400',
    base: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    bot: 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
};

export function groupInvitesMembersPage(config) {
    const t = config.translations;
    const m = t.members;

    return {
        ...inviteHelpers(t),

        t,
        m,
        endpoints: config.endpoints,

        loading: false,
        loaded: false,
        error: null,
        data: null,

        days: 7,
        event: 'all',
        who: 'all',
        group: '',

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            const params = new URLSearchParams({ days: String(this.days), event: this.event, who: this.who });

            if (this.group !== '') {
                params.set('group', this.group);
            }

            try {
                this.data = await requestJson(`${this.endpoints.show}?${params}`);
                this.loaded = true;
            } catch (error) {
                this.error = error?.message || t.load_failed;
                notify(this.error, false);
            } finally {
                this.loading = false;
            }
        },

        periods() {
            return Object.entries(m.periods).map(([value, label]) => ({ value: Number(value), label }));
        },

        setDays(value) {
            this.days = value;
            this.load();
        },

        filters() {
            return Object.entries(m.filters).map(([value, label]) => ({ value, label }));
        },

        setEvent(value) {
            this.event = value;
            this.load();
        },

        whoItems() {
            return Object.entries(m.who).map(([value, label]) => ({ value, label }));
        },

        setWho(value) {
            this.who = value;
            this.load();
        },

        /* Off or silent, the bot writes nothing down: the page says so on top. */
        botWarning() {
            const bot = this.data?.bot;

            if (!bot) {
                return '';
            }

            if (!bot.connected || !bot.enabled) {
                return m.bot_off;
            }

            return bot.alive ? '' : m.bot_silent;
        },

        sinceLine() {
            return this.data?.since ? m.since.replace(':date', this.time(this.data.since)) : '';
        },

        tiles() {
            const totals = this.data?.totals ?? {};
            const ours = this.data?.ours ?? { left: 0, removed: 0 };

            return [
                { key: 'joined', label: m.tiles.joined, value: totals.joined ?? 0, tone: 'text-success-700 dark:text-success-400' },
                { key: 'added', label: m.tiles.added, value: totals.added ?? 0, tone: 'text-brand-700 dark:text-brand-400' },
                { key: 'left', label: m.tiles.left, value: totals.left ?? 0, tone: 'text-warning-700 dark:text-warning-400' },
                { key: 'removed', label: m.tiles.removed, value: totals.removed ?? 0, tone: 'text-error-700 dark:text-error-400' },
                {
                    key: 'ours_gone',
                    label: m.tiles.ours_gone,
                    value: ours.left + ours.removed,
                    tone: 'text-gray-950 dark:text-white',
                    hint: m.ours_gone_hint.replace(':left', this.number(ours.left)).replace(':removed', this.number(ours.removed)),
                },
            ];
        },

        eventDot(row) {
            return EVENT_DOTS[row.event] ?? 'text-gray-400';
        },

        eventTone(row) {
            return EVENT_TONES[row.event] ?? 'text-gray-600 dark:text-gray-300';
        },

        eventLabel(row) {
            const label = m.events[row.event] ?? row.event;

            return row.via && m.via[row.via] ? `${label} ${m.via[row.via]}` : label;
        },

        who(row) {
            return row.name || (row.username ? `@${row.username}` : '') || `ID ${row.user_id}`;
        },

        whoLine(row) {
            return [row.name && row.username ? `@${row.username}` : '', `ID ${row.user_id}`].filter(Boolean).join(' · ');
        },

        /* Who did it, when it was not the person themselves. */
        byLine(row) {
            const by = row.by;

            if (!by || by.kind === 'self') {
                return ['left', 'joined'].includes(row.event) ? '' : m.by.self;
            }

            return this.actorName({ kind: by.kind, account_id: by.account_id, name: by.name, user_id: by.user_id });
        },

        actorName(actor) {
            if (actor.kind === 'ours') {
                return m.by.ours.replace(':id', actor.account_id ?? '?');
            }

            if (actor.kind === 'bot') {
                return m.by.bot;
            }

            if (actor.kind === 'self') {
                return m.by.self;
            }

            return actor.name || m.by.unknown.replace(':id', actor.user_id ?? '?');
        },

        actorLine(actor) {
            return actor.user_id ? `ID ${actor.user_id}` : '';
        },

        tags(row) {
            const tags = [];

            if (row.account_id) {
                tags.push({ key: 'ours', label: m.our_account.replace(':id', row.account_id), tone: TAG_TONES.ours });
            }

            if (row.invited_by) {
                tags.push({ key: 'added', label: m.our_added.replace(':id', row.invited_by), tone: TAG_TONES.added });
            } else if (row.in_base) {
                tags.push({ key: 'base', label: m.in_base, tone: TAG_TONES.base });
            }

            if (row.is_bot) {
                tags.push({ key: 'bot', label: m.bot_badge, tone: TAG_TONES.bot });
            }

            return tags;
        },
    };
}
