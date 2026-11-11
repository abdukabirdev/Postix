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
| Four stages, one after another: the groups themselves, what each got,
| when "who left" was last looked at, who is left to try (the heaviest).
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';
import { stages } from './stages';

const NO_NUMBERS = {
    added: 0,
    invited: 0,
    left: 0,
    not_added: 0,
    already: 0,
    sent: 0,
    failed: 0,
    pending: 0,
    joined: 0,
    last_added_at: null,
};

export function groupInvitesGroupsPage(config) {
    const t = config.translations;

    return {
        ...inviteHelpers(t),
        ...stages({ groups: 'loadGroups', numbers: 'loadNumbers', checks: 'loadChecks', queue: 'loadQueue' }),

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
        /* The group whose details are open. */
        opened: null,

        init() {
            this.load();
        },

        /* All the stages again. `loaded` and `error` are the first stage's: the groups themselves. */
        async load() {
            this.loading = true;
            this.error = null;

            await this.loadStage('groups');

            this.error = this.stages.groups.error;
            this.loaded = this.stages.groups.loaded;
            this.loading = false;

            if (this.loaded) {
                await this.loadStages(['numbers', 'checks', 'queue']);
            }
        },

        async loadGroups() {
            const json = await requestJson(`${this.endpoints.index}?part=groups`);
            const before = new Map(this.groups.map((group) => [group.id, group]));

            /* What the other stages brought stays on the screen until they bring new numbers. */
            this.groups = (json.groups ?? []).map((group) => {
                const old = before.get(group.id);

                return {
                    ...NO_NUMBERS,
                    left_checked_at: null,
                    queue: null,
                    ...(old ? { ...NO_NUMBERS, ...Object.fromEntries(Object.keys(NO_NUMBERS).map((key) => [key, old[key]])), left_checked_at: old.left_checked_at, queue: old.queue } : {}),
                    ...group,
                };
            });

            this.currentId = json.current_id ?? null;
            this.audience = json.audience ?? 'all';
        },

        async loadNumbers() {
            const json = await requestJson(`${this.endpoints.index}?part=numbers`);
            const numbers = json.numbers ?? {};

            this.groups.forEach((group) => Object.assign(group, NO_NUMBERS, numbers[group.id] ?? {}));
        },

        async loadChecks() {
            const json = await requestJson(`${this.endpoints.index}?part=checks`);
            const checks = json.checks ?? {};

            this.groups.forEach((group) => {
                group.left_checked_at = checks[group.id] ?? null;
            });
        },

        async loadQueue() {
            const json = await requestJson(`${this.endpoints.index}?part=queue`);
            const queues = json.queues ?? {};

            this.groups.forEach((group) => {
                group.queue = group.archived_at ? null : (queues[group.id] ?? null);
            });
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

        open(group) {
            this.opened = this.opened === group.id ? null : group.id;
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

        /* Forget the failures of one kind (or both) so the next run tries those people here again. */
        async retry(group, status = null) {
            const n = status === 'failed' ? group.failed : (status === 'not_added' ? group.not_added : group.not_added + group.failed);

            if (!window.confirm(t.groups.retry_confirm.replace(':n', this.number(n)))) {
                return;
            }

            await this.act(`${this.endpoints.index}/${group.id}/retry`, 'POST', status ? { status } : {});
        },

        /* After a button the groups are read again, and the numbers with them. */
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
                { key: 'joined', value: group.joined, status: 'joined' },
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

        /* Short, for the table: how many are left to try. */
        queueTotal(group) {
            if (!group.queue) {
                return null;
            }

            return this.audience === 'proven' ? group.queue.proven : group.queue.proven + group.queue.other;
        },
    };
}
