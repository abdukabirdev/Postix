/*
|--------------------------------------------------------------------------
| Join group: the people
|--------------------------------------------------------------------------
|
| Who is in which group: the people read from the groups the accounts
| joined (telegram_scraped_users through telegram_scrape_group_user, the
| tables the contact sync shares). Searched by @username, phone, Telegram
| id or name; one group's people when the page is opened with ?group_id=.
|
*/

import { requestJson } from './monitoring';
import { EM_DASH, formatDate, formatNumber, formatPhone, formatRelative } from './format';

const notify = (message, success = true) => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
};

export function groupJoinPeoplePage(config) {
    const t = config.translations;

    return {
        t,

        endpoint: config.endpoint,

        rows: [],

        meta: null,

        group: null,

        /* How many match, and how many people there are at all: counted apart, after the list. */
        total: null,

        all: 0,

        loading: false,

        filters: {
            search: '',
            phone: false,
            group_id: new URLSearchParams(window.location.search).get('group_id') ?? '',
        },

        dash: EM_DASH,

        init() {
            this.load();
        },

        url(page = 1, part = '') {
            const params = new URLSearchParams({ page: String(page), per_page: '30' });

            if (part) {
                params.set('part', part);
            }

            if (this.filters.search.trim() !== '') {
                params.set('search', this.filters.search.trim());
            }

            if (this.filters.phone) {
                params.set('phone', '1');
            }

            if (this.filters.group_id !== '') {
                params.set('group_id', this.filters.group_id);
            }

            return `${this.endpoint}?${params}`;
        },

        async load(more = false) {
            if (this.loading) {
                return;
            }

            this.loading = true;

            try {
                const page = more && this.meta ? this.meta.current_page + 1 : 1;
                const json = await requestJson(this.url(page));

                this.rows = more ? [...this.rows, ...json.data] : json.data;
                this.meta = json.meta;
                this.group = json.group;
            } catch (error) {
                notify(error?.message || t.errors.load_failed, false);
            } finally {
                this.loading = false;
            }

            if (!more) {
                this.loadTotal();
            }
        },

        async loadTotal() {
            const asked = this.url(1, 'total');

            this.total = null;

            try {
                const json = await requestJson(asked);

                /* A newer search was typed while this was counted: its answer is not for this page. */
                if (asked === this.url(1, 'total')) {
                    this.total = json.total;
                    this.all = json.all;
                }
            } catch (error) {
                this.total = null;
            }
        },

        clearGroup() {
            this.filters.group_id = '';

            window.history.replaceState(null, '', window.location.pathname);

            this.load();
        },

        get hasMore() {
            return this.meta !== null && !!this.meta.has_more;
        },

        moreGroups(row) {
            return Math.max(0, row.groups_count - row.groups.length);
        },

        telegramUrl(row) {
            return row.username ? `https://t.me/${row.username}` : `tg://user?id=${row.telegram_user_id}`;
        },

        number: formatNumber,
        relative: formatRelative,
        date: formatDate,
        phone: formatPhone,
    };
}
