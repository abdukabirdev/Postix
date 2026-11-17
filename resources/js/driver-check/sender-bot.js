/*
|--------------------------------------------------------------------------
| The sender bot
|--------------------------------------------------------------------------
|
| Our people press Start in the bot, choose a language and ask the admin;
| once let in they send a message, say how many times and how often, and
| the bot posts it into the groups the admin turned on. One component for
| the four pages (requests, posts, groups, settings): each loads what its
| part shows, plus the bot itself for the header.
|
| Also the badge of the left menu: the requests waiting, asked now and
| then; a new one lights it and says so in a toast.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

const REFRESH = 15000;

const BADGE_REFRESH = 30000;

const USER_TONES = { pending: 'warn', approved: 'ok', rejected: 'bad', blocked: 'bad', new: undefined };

const POST_TONES = { active: 'run', done: 'ok', stopped: undefined, failed: 'bad' };

export function senderBotPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),

        t,
        part: config.part,
        endpoints: config.endpoints,

        loading: false,
        loaded: false,
        error: null,
        data: null,
        rows: [],
        rowsLoaded: false,
        filter: config.part === 'requests' ? 'pending' : 'all',
        busy: false,
        timer: null,

        token: '',
        replacing: false,
        groupInput: '',

        /* The forms: filled from the first answer and after a save, not by the refreshes. */
        form: { chat_id: '', listener_key: '', via_bot: true, max_times: 20, min_interval: 1, max_interval: 1440 },
        formFilled: false,

        init() {
            this.load();

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

            if (!quiet) {
                this.error = null;
            }

            try {
                const [data, rows] = await Promise.all([
                    requestJson(this.endpoints.show),
                    this.loadRows(),
                ]);

                this.data = data;

                if (rows !== null) {
                    this.rows = rows;
                    this.rowsLoaded = true;
                }

                this.loaded = true;

                if (!this.formFilled) {
                    this.fillForm();
                }

                this.$store.dcSenderBadge?.set(data.counts.pending);
            } catch (error) {
                if (!quiet) {
                    this.error = error?.message || t.load_failed;
                }
            } finally {
                this.loading = false;
            }
        },

        async loadRows() {
            const lists = { requests: ['users', 'users'], posts: ['posts', 'posts'], groups: ['groups', 'groups'] };
            const list = lists[this.part];

            if (!list) {
                return null;
            }

            const status = this.part !== 'groups' && this.filter !== 'all' ? `?status=${this.filter}` : '';

            return (await requestJson(this.endpoints[list[0]] + status))[list[1]] ?? [];
        },

        fillForm() {
            const n = this.data.notify;
            const l = this.data.limits;

            this.form = {
                chat_id: n.chat_id ?? '',
                listener_key: n.listener_key ?? '',
                via_bot: n.via_bot,
                max_times: l.max_times,
                min_interval: l.min_interval,
                max_interval: l.max_interval,
            };
            this.formFilled = true;
        },

        setFilter(value) {
            if (this.filter === value) {
                return;
            }

            this.filter = value;
            this.rowsLoaded = false;
            this.load();
        },

        get bot() {
            return this.data?.bot ?? { connected: false };
        },

        get counts() {
            return this.data?.counts ?? {};
        },

        get botLink() {
            return this.bot.username ? `https://t.me/${this.bot.username}` : '';
        },

        /* Running, not answering, off, no token. */
        state() {
            if (!this.bot.connected) {
                return { tone: 'bg-gray-300 dark:bg-gray-600', text: t.state.none, key: 'none' };
            }

            if (!this.bot.enabled) {
                return { tone: 'bg-gray-400', text: t.state.off, key: 'off' };
            }

            if (this.bot.alive) {
                return { tone: 'bg-success-500', text: t.state.on.replace(':time', this.time(this.bot.polled_at)), key: 'on' };
            }

            return {
                tone: 'bg-warning-500',
                text: this.bot.polled_at ? t.state.stale.replace(':time', this.time(this.bot.polled_at)) : t.state.waiting,
                key: 'stale',
            };
        },

        /* The filter tiles of the requests and posts pages. */
        tiles() {
            const c = this.counts;

            if (this.part === 'requests') {
                return [
                    { value: 'pending', label: t.users.filters.pending, total: c.pending, warn: c.pending > 0 },
                    { value: 'approved', label: t.users.filters.approved, total: c.approved },
                    { value: 'rejected', label: t.users.filters.rejected, total: c.rejected },
                    { value: 'blocked', label: t.users.filters.blocked, total: c.blocked },
                    { value: 'all', label: t.users.filters.all, total: (c.pending ?? 0) + (c.approved ?? 0) + (c.rejected ?? 0) + (c.blocked ?? 0) + (c.new ?? 0) },
                ];
            }

            return [
                { value: 'all', label: t.posts.filters.all, total: c.posts_all },
                { value: 'active', label: t.posts.filters.active, total: c.posts_active },
                { value: 'done', label: t.posts.filters.done, total: c.posts_done },
                { value: 'stopped', label: t.posts.filters.stopped, total: c.posts_stopped },
                { value: 'failed', label: t.posts.filters.failed, total: c.posts_failed, warn: c.posts_failed > 0 },
            ];
        },

        userTone(user) {
            return USER_TONES[user.status];
        },

        userName(user) {
            return user.name || (user.username ? `@${user.username}` : String(user.telegram_user_id));
        },

        userSub(user) {
            return [user.username ? `@${user.username}` : null, `ID ${user.telegram_user_id}`].filter(Boolean).join(' · ');
        },

        language(code) {
            return code === 'uz' ? "O'zbekcha" : code === 'ru' ? 'Русский' : '—';
        },

        /* What the listener did with the request. */
        noticeLine(user) {
            if (user.notified_at) {
                return { tone: 'ok', text: t.users.notice.sent.replace(':time', this.time(user.notified_at)) };
            }

            if (user.notify_error) {
                return { tone: 'bad', text: t.users.notice.failed, title: user.notify_error };
            }

            return user.status === 'pending' ? { tone: 'warn', text: t.users.notice.waiting } : null;
        },

        postTone(post) {
            return POST_TONES[post.status];
        },

        postStatus(post) {
            if (post.status === 'stopped') {
                return t.posts.status[post.stopped_by === 'admin' ? 'stopped_admin' : 'stopped_user'];
            }

            return t.posts.status[post.status] ?? post.status;
        },

        preview(post) {
            const text = String(post.text || '').replace(/\s+/g, ' ').trim();

            return text || t.posts.media;
        },

        progress(post) {
            return this.percent(post.sent_times, post.times);
        },

        interval(minutes) {
            if (!minutes) {
                return '—';
            }

            const h = Math.floor(minutes / 60);
            const m = minutes % 60;

            if (h === 0) {
                return t.minutes.replace(':m', m);
            }

            return m === 0 ? t.hours.replace(':h', h) : t.hours_minutes.replace(':h', h).replace(':m', m);
        },

        groupTone(group) {
            if (!group.bot_in) {
                return 'bad';
            }

            return group.bot_status === 'administrator' || group.bot_status === 'creator' ? 'ok' : undefined;
        },

        groupState(group) {
            return t.groups.bot_status[group.bot_status] ?? t.groups.bot_status.unknown;
        },

        groupLink(group) {
            return group.username ? `https://t.me/${group.username}` : null;
        },

        testLine() {
            const test = this.data?.notify?.test ?? {};

            if (!test.requested_at) {
                return null;
            }

            if (test.sent_at) {
                return { tone: 'ok', text: t.notify.test_sent.replace(':time', this.time(test.sent_at)) };
            }

            if (test.error) {
                return { tone: 'bad', text: t.notify.test_failed.replace(':error', test.error) };
            }

            return { tone: 'run', text: t.notify.test_waiting };
        },

        lastNoticeLine() {
            const last = this.data?.notify?.last;

            if (!last) {
                return { tone: undefined, text: t.notify.last_none };
            }

            if (last.error) {
                return { tone: 'bad', text: t.notify.last_failed.replace(':time', this.time(last.requested_at)).replace(':error', last.error) };
            }

            return { tone: 'ok', text: t.notify.last_sent.replace(':time', this.time(last.at)) };
        },

        async act(url, method = 'POST', body = null, after = null) {
            if (this.busy) {
                return false;
            }

            this.busy = true;

            try {
                const json = await requestJson(url, method, body);
                notify(json.message);
                after?.();
                this.busy = false;
                await this.load(true);

                return true;
            } catch (error) {
                notify(error?.message || t.load_failed, false);

                return false;
            } finally {
                this.busy = false;
            }
        },

        url(name, id) {
            return this.endpoints[name].replace('__ID__', id);
        },

        connect() {
            return this.act(this.endpoints.connect, 'PUT', { token: this.token.trim() }, () => {
                this.token = '';
                this.replacing = false;
            });
        },

        disconnect() {
            if (window.confirm(t.confirm.disconnect)) {
                this.act(this.endpoints.disconnect, 'DELETE');
            }
        },

        toggle() {
            this.act(this.endpoints.update, 'PATCH', { enabled: !this.bot.enabled });
        },

        saveNotify() {
            this.act(this.endpoints.notify, 'PATCH', {
                chat_id: String(this.form.chat_id || '').trim() || null,
                listener_key: this.form.listener_key || null,
                via_bot: !!this.form.via_bot,
            }, () => { this.formFilled = false; });
        },

        testNotify() {
            this.act(this.endpoints.notifyTest);
        },

        saveLimits() {
            this.act(this.endpoints.limits, 'PATCH', {
                max_times: Number(this.form.max_times),
                min_interval: Number(this.form.min_interval),
                max_interval: Number(this.form.max_interval),
            }, () => { this.formFilled = false; });
        },

        decide(user, verdict) {
            if ((verdict === 'block' || verdict === 'reject') && !window.confirm(t.confirm[verdict].replace(':name', this.userName(user)))) {
                return;
            }

            this.act(this.url('decide', user.id), 'POST', { verdict });
        },

        renotify(user) {
            this.act(this.url('renotify', user.id));
        },

        addGroup() {
            this.act(this.endpoints.groupsStore, 'POST', { chat: this.groupInput.trim() }, () => { this.groupInput = ''; });
        },

        checkGroups() {
            this.act(this.endpoints.groupsCheck);
        },

        toggleGroup(group) {
            this.act(this.url('groupsUpdate', group.id), 'PATCH', { is_active: !group.is_active });
        },

        removeGroup(group) {
            if (window.confirm(t.confirm.remove_group.replace(':group', group.title))) {
                this.act(this.url('groupsDestroy', group.id), 'DELETE');
            }
        },

        stopPost(post) {
            if (window.confirm(t.confirm.stop.replace(':id', post.id))) {
                this.act(this.url('postStop', post.id));
            }
        },

        copyLink() {
            navigator.clipboard?.writeText(this.botLink).then(() => notify(t.copied));
        },
    };
}

/**
 * The menu badge: one poller for every place it is drawn (the left menu
 * and the phone drawer), a toast when the number grows.
 */
export function senderBadgeStore() {
    return {
        pending: 0,
        started: false,
        url: null,
        toast: '',

        start(url, initial, toast) {
            if (this.started) {
                return;
            }

            this.started = true;
            this.url = url;
            this.toast = toast;
            this.pending = Number(initial) || 0;

            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.poll();
                }
            }, BADGE_REFRESH);
        },

        set(value) {
            const next = Number(value) || 0;

            if (next > this.pending && this.started && this.toast) {
                notify(this.toast.replace(':count', next));
            }

            this.pending = next;
        },

        async poll() {
            try {
                this.set((await requestJson(this.url)).pending);
            } catch {
                /* The badge stays as it was. */
            }
        },
    };
}
