/*
|--------------------------------------------------------------------------
| The sender bot
|--------------------------------------------------------------------------
|
| Our people press Start in the bot, choose a language and ask the admin;
| once let in they send a message, say how many times and how often, and
| the bot posts it into the groups the admin turned on. One component for
| the six pages (requests, posts, leads, history, groups, settings): each
| loads what its part shows, plus the bot itself for the header. The posts
| and the history may be of one person (?user=ID in the address).
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

const POST_TONES = { active: 'run', paused: 'warn', done: 'ok', stopped: undefined, failed: 'bad' };

const LEAD_TONES = { link_given: 'ok', contact_sent: 'ok', contact_asked: 'warn', not_found: 'bad' };

const EVENT_TONES = {
    approved: 'ok', unblocked: 'ok', post_done: 'ok', lead_link: 'ok', lead_contact: 'ok', group_joined: 'ok', group_on: 'ok',
    rejected: 'bad', blocked: 'bad', post_failed: 'bad', lead_not_found: 'bad', group_left: 'bad',
    requested: 'warn', post_paused: 'warn', lead_asked: 'warn',
    post_created: 'run', post_now: 'run', round: 'run',
};

const FIRST_FILTER = { requests: 'pending' };

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
        filter: FIRST_FILTER[config.part] ?? 'all',
        busy: false,
        timer: null,

        /* One person's posts / history: ?user=ID in the address. */
        person: new URLSearchParams(window.location.search).get('user'),
        extra: {},
        more: false,
        open: null,
        details: {},

        token: '',
        replacing: false,
        groupInput: '',

        /* The forms: filled from the first answer and after a save, not by the refreshes. */
        form: {
            chat_id: '', listener_key: '', via_bot: true, new_posts: true,
            max_times: 20, min_interval: 1, max_interval: 1440, max_active: 3, per_day: 10, quiet_from: '', quiet_to: '',
            driver_contacts: true, contact_button: false,
        },
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

                /* The history keeps what "Show more" added while the first page is unchanged. */
                if (rows !== null && !(quiet && this.part === 'history' && this.rows.length > rows.length)) {
                    this.rows = rows;
                    this.rowsLoaded = true;
                }

                if (this.open && quiet) {
                    this.loadDetails(this.open, true);
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

        async loadRows(before = null) {
            const lists = {
                requests: ['users', 'users', 'status'],
                posts: ['posts', 'posts', 'status'],
                groups: ['groups', 'groups', null],
                leads: ['leads', 'leads', 'status'],
                history: ['history', 'events', 'type'],
            };
            const list = lists[this.part];

            if (!list) {
                return null;
            }

            const query = new URLSearchParams();

            if (list[2] && this.filter !== 'all') {
                query.set(list[2], this.filter);
            }

            if (this.person && (this.part === 'posts' || this.part === 'history')) {
                query.set('user', this.person);
            }

            if (before) {
                query.set('before', before);
            }

            const json = await requestJson(this.endpoints[list[0]] + (query.toString() ? `?${query}` : ''));

            this.extra = json;
            this.more = !!json.more;

            return json[list[1]] ?? [];
        },

        /* The history: the next page, added below. */
        async loadMore() {
            if (this.busy || !this.rows.length) {
                return;
            }

            this.busy = true;

            try {
                const rows = await this.loadRows(this.rows[this.rows.length - 1].id);
                this.rows = [...this.rows, ...rows];
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        fillForm() {
            const n = this.data.notify;
            const l = this.data.limits;
            const o = this.data.options ?? {};

            this.form = {
                chat_id: n.chat_id ?? '',
                listener_key: n.listener_key ?? '',
                via_bot: n.via_bot,
                new_posts: n.new_posts ?? true,
                max_times: l.max_times,
                min_interval: l.min_interval,
                max_interval: l.max_interval,
                max_active: l.max_active ?? 3,
                per_day: l.per_day ?? 10,
                quiet_from: l.quiet_from ?? '',
                quiet_to: l.quiet_to ?? '',
                driver_contacts: o.driver_contacts ?? true,
                contact_button: o.contact_button ?? false,
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

            if (this.part === 'leads') {
                const l = this.extra?.counts ?? {};
                const all = Object.values(l).reduce((sum, n) => sum + Number(n || 0), 0);

                return [
                    { value: 'all', label: t.leads.filters.all, total: all },
                    { value: 'link_given', label: t.leads.filters.link_given, total: l.link_given },
                    { value: 'contact_asked', label: t.leads.filters.contact_asked, total: l.contact_asked, warn: l.contact_asked > 0 },
                    { value: 'contact_sent', label: t.leads.filters.contact_sent, total: l.contact_sent },
                    { value: 'not_found', label: t.leads.filters.not_found, total: l.not_found },
                ];
            }

            return [
                { value: 'all', label: t.posts.filters.all, total: c.posts_all },
                { value: 'active', label: t.posts.filters.active, total: c.posts_active },
                { value: 'paused', label: t.posts.filters.paused, total: c.posts_paused },
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
            if (post.copies_deleted_at) {
                return `${t.posts.status[post.status === 'stopped' ? (post.stopped_by === 'admin' ? 'stopped_admin' : 'stopped_user') : post.status] ?? post.status} · ${t.posts.deleted}`;
            }

            if (post.status === 'stopped') {
                return t.posts.status[post.stopped_by === 'admin' ? 'stopped_admin' : 'stopped_user'];
            }

            return t.posts.status[post.status] ?? post.status;
        },

        preview(post) {
            const text = String(post.text || '').replace(/\s+/g, ' ').trim();

            return text || t.posts.media;
        },

        /* The history filter chips. */
        historyFilters() {
            return ['all', 'requests', 'posts', 'drivers', 'groups'].map((value) => ({ value, label: t.history.filters[value] }));
        },

        personTitle() {
            const name = this.extra?.person;

            if (!this.person || !name) {
                return null;
            }

            return (this.part === 'history' ? t.history.person : t.posts.person).replace(':name', name);
        },

        resetPerson() {
            const url = new URL(window.location.href);
            url.searchParams.delete('user');
            window.history.replaceState({}, '', url);
            this.person = null;
            this.rowsLoaded = false;
            this.load();
        },

        personUrl(base, user) {
            return `${base}?user=${user.id}`;
        },

        eventText(event) {
            const template = t.history.types[event.type] ?? event.type;
            const data = { ...(event.data || {}), title: event.group ?? event.data?.title ?? '' };

            return template.replace(/:([a-z_]+)/g, (match, key) => (data[key] !== undefined && data[key] !== null ? String(data[key]) : '—'));
        },

        eventTone(event) {
            if (event.type === 'round' && event.data && Number(event.data.ok) < Number(event.data.groups)) {
                return 'warn';
            }

            return EVENT_TONES[event.type];
        },

        actorName(event) {
            return t.history.actor[event.actor] ?? event.actor;
        },

        leadTone(lead) {
            return LEAD_TONES[lead.status];
        },

        leadStatus(lead) {
            if (lead.status === 'contact_asked' && lead.error === 'cancelled') {
                return t.leads.cancelled;
            }

            return t.leads.status[lead.status] ?? lead.status;
        },

        driverLink(driver) {
            return driver.username ? `https://t.me/${driver.username}` : null;
        },

        /* A post row opened: its rounds, history and drivers. */
        toggleDetails(post) {
            if (this.open === post.id) {
                this.open = null;

                return;
            }

            this.open = post.id;
            this.loadDetails(post.id);
        },

        async loadDetails(id, quiet = false) {
            try {
                this.details = { ...this.details, [id]: await requestJson(this.url('postShow', id)) };
            } catch (error) {
                if (!quiet) {
                    notify(error?.message || t.load_failed, false);
                }
            }
        },

        groupsOf(post) {
            return post.groups === null || post.groups === undefined ? t.posts.detail.groups_all : post.groups.join(', ');
        },

        postAction(post, action) {
            if (action === 'delete' && !window.confirm(t.confirm.delete.replace(':id', post.id).replace(':count', post.deletable))) {
                return;
            }

            this.act(this.url('postAction', post.id).replace('__ACTION__', action));
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
                new_posts: !!this.form.new_posts,
            }, () => { this.formFilled = false; });
        },

        saveOptions() {
            this.act(this.endpoints.options, 'PATCH', {
                driver_contacts: !!this.form.driver_contacts,
                contact_button: !!this.form.contact_button,
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
                max_active: Number(this.form.max_active),
                per_day: Number(this.form.per_day),
                quiet_from: String(this.form.quiet_from || '').trim() || null,
                quiet_to: String(this.form.quiet_to || '').trim() || null,
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

        setLink(group) {
            const link = window.prompt(t.groups.link_prompt, group.invite_link || '');

            if (link === null) {
                return;
            }

            this.act(this.url('groupsUpdate', group.id), 'PATCH', { invite_link: link.trim() || null });
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
