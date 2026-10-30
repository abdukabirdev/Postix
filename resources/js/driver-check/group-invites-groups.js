/*
|--------------------------------------------------------------------------
| Group invites: our groups
|--------------------------------------------------------------------------
|
| The groups people are added into: what each got (added, of them left, not
| added, cannot be, already in, unchecked), who is still left to try for it,
| its member count. One group at a time is current - it comes first; making
| another current switches it off. Delete archives a group with its
| history; the archive folds away below, and the same link added again
| brings a group back.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

export function groupInvitesGroupsPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),

        t,
        endpoints: config.endpoints,
        links: config.links,

        loading: false,
        busy: false,
        error: null,
        loaded: false,
        groups: [],
        currentId: null,
        audience: 'all',
        link: '',
        showArchive: false,

        init() {
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = null;

            try {
                const json = await requestJson(this.endpoints.index);
                this.groups = json.groups ?? [];
                this.currentId = json.current_id ?? null;
                this.audience = json.audience ?? 'all';
                this.loaded = true;
            } catch (error) {
                this.error = error?.message || t.load_failed;
            } finally {
                this.loading = false;
            }
        },

        /* The current group first, the others after it. */
        live() {
            return this.groups
                .filter((group) => !group.archived_at)
                .sort((a, b) => (b.id === this.currentId) - (a.id === this.currentId));
        },

        isCurrent(group) {
            return group.id === this.currentId;
        },

        current() {
            return this.groups.find((group) => group.id === this.currentId) ?? null;
        },

        archived() {
            return this.groups.filter((group) => group.archived_at);
        },

        async add() {
            const link = this.link.trim();

            if (link === '' || this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.index, 'POST', { link });
                this.link = '';
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        /* Make it the one group people are added into; the current one goes off. */
        async makeCurrent(group) {
            const current = this.current();

            if (current && !window.confirm(t.groups.switch_confirm
                .replace(':group', group.title || group.link)
                .replace(':current', current.title || current.link))) {
                return;
            }

            await this.act(`${this.endpoints.index}/${group.id}`, 'PATCH', { is_active: true });
        },

        async switchOff(group) {
            if (!window.confirm(t.groups.off_confirm.replace(':group', group.title || group.link))) {
                return;
            }

            await this.act(`${this.endpoints.index}/${group.id}`, 'PATCH', { is_active: false });
        },

        async archive(group) {
            if (!window.confirm(t.groups.archive_confirm)) {
                return;
            }

            await this.act(`${this.endpoints.index}/${group.id}`, 'DELETE');
        },

        async restore(group) {
            await this.act(`${this.endpoints.index}/${group.id}/restore`, 'POST');
        },

        async retry(group) {
            if (!window.confirm(t.groups.retry_confirm.replace(':n', this.number(group.not_added + group.failed)))) {
                return;
            }

            await this.act(`${this.endpoints.index}/${group.id}/retry`, 'POST');
        },

        async act(url, method, body = {}) {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(url, method, body);
                notify(json.message);
                await this.load();
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        peopleLink(group, status = '') {
            const params = new URLSearchParams({ group_id: String(group.id) });

            if (status) {
                params.set('status', status);
            }

            return `${this.links.people}?${params}`;
        },

        /* added · of them left · not added · cannot · already · unchecked: the numbers of one group. */
        cells(group) {
            return [
                { key: 'added', value: group.added, status: 'added' },
                { key: 'left', value: group.left, status: 'left' },
                { key: 'not_added', value: group.not_added, status: 'not_added' },
                { key: 'failed', value: group.failed, status: 'failed' },
                { key: 'already', value: group.already, status: 'already' },
                { key: 'sent', value: group.sent, status: 'sent' },
            ];
        },

        stillIn(group) {
            return group.added - group.left;
        },

        membersLine(group) {
            const before = group.members_before === null ? '?' : this.number(group.members_before);
            const after = group.members_after === null ? '…' : this.number(group.members_after);

            return t.groups.members.replace(':before', before).replace(':after', after);
        },

        membersGrew(group) {
            return group.members_before === null || group.members_after === null
                ? null
                : group.members_after - group.members_before;
        },

        /* Who is still left to try for the group: the proven first, then the rest - or the proven alone. */
        queueLine(group) {
            if (!group.queue) {
                return '';
            }

            const { proven, other } = group.queue;

            if (this.audience === 'proven') {
                return t.groups.queue_proven_only
                    .replace(':proven', this.number(proven))
                    .replace(':other', this.number(other));
            }

            return t.groups.queue
                .replace(':n', this.number(proven + other))
                .replace(':proven', this.number(proven))
                .replace(':other', this.number(other));
        },
    };
}
