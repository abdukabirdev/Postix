/*
|--------------------------------------------------------------------------
| Bot of groups: the admins of the anti-spam
|--------------------------------------------------------------------------
|
| Their links in our groups stay, they may forward spam to the bot. Put on
| by ID, @username or a t.me link, or by an invite link made here: they
| open the bot, press Start, and are one. The owner is one always.
|
*/

import { requestJson } from './monitoring';
import { inviteHelpers, notify } from './group-invites-shared';

/** The page asks again this often while it is open, ms: somebody who opened an invite link shows up by themselves. */
const REFRESH = 10000;

export function groupInvitesSpamAdminsPage(config) {
    const t = config.translations;
    const s = t.spam;

    return {
        ...inviteHelpers(t),

        t,
        s,
        endpoints: config.endpoints,

        loading: false,
        loaded: false,
        error: null,
        data: null,
        busy: false,
        timer: null,

        entries: '',
        note: '',
        inviteNote: '',
        /* The invite link just made, to copy and send. */
        made: '',
        /* The link copied last: its button says so for a moment. */
        copied: '',

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
            this.error = null;

            try {
                this.data = await requestJson(this.endpoints.show);
                this.loaded = true;
            } catch (error) {
                if (!quiet) {
                    this.error = error?.message || t.load_failed;
                }
            } finally {
                this.loading = false;
            }
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

        async makeInvite() {
            if (this.busy) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.invite, 'POST', {
                    note: this.inviteNote.trim() || null,
                });
                this.made = json.link || '';
                this.inviteNote = '';
                notify(json.message);
                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },

        /* The clipboard where the browser lets the page have it; else the old way, through the box selected. */
        async copy(link, input = null) {
            if (!link) {
                return;
            }

            let done = false;

            try {
                await navigator.clipboard.writeText(link);
                done = true;
            } catch {
                const box = input ?? Object.assign(document.createElement('textarea'), { value: link });

                if (!input) {
                    box.style.position = 'fixed';
                    box.style.opacity = '0';
                    document.body.appendChild(box);
                }

                box.select();

                try {
                    done = document.execCommand('copy');
                } catch {
                    done = false;
                }

                if (!input) {
                    box.remove();
                }
            }

            if (!done) {
                notify(s.admins.copy_failed, false);

                return;
            }

            this.copied = link;
            notify(s.admins.copied_note);
            setTimeout(() => {
                if (this.copied === link) {
                    this.copied = '';
                }
            }, 2000);
        },

        async remove(row, invite = false) {
            const question = invite
                ? s.admins.invite_remove_confirm
                : s.admins.remove_confirm.replace(':who', this.who(row) || '#' + row.id);

            if (this.busy || !window.confirm(question)) {
                return;
            }

            this.busy = true;

            try {
                const json = await requestJson(this.endpoints.destroy.replace('__ID__', row.id), 'DELETE');
                notify(json.message);

                if (invite && row.link === this.made) {
                    this.made = '';
                }

                await this.load(true);
            } catch (error) {
                notify(error?.message || t.load_failed, false);
            } finally {
                this.busy = false;
            }
        },
    };
}
