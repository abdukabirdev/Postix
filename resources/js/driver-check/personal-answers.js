/*
|--------------------------------------------------------------------------
| Personal answers
|--------------------------------------------------------------------------
|
| One person at a time (PersonalAnswers): pick them on the left, add a
| situation - a penalty level of their role, an auto reply kind, a
| greeting, the nudge - and give it texts, voice messages, GIFs. Added to
| what everyone gets, or with "only theirs" instead of it. Edited locally,
| saved in one go from the bar at the bottom.
|
*/

import { escapeHtml, telegramHtml } from './telegram-html';
import { telegramGifPicker, uploadAnswerMedia } from './media-picker';

let keySeed = 0;

const nextKey = () => {
    keySeed += 1;

    return `p${keySeed}`;
};

/** What a preview fills the placeholders with. */
const SAMPLE = {
    uz: { address: 'Ali aka', name: 'ALIYEV ALI', request: 'TLS04851', repeat_number: '2', status_limit: '3 soat', time_in_status: '3 soat 5 daqiqa', crm_status: 'Актуальный' },
    ru: { address: 'Али ака', name: 'ALIYEV ALI', request: 'TLS04851', repeat_number: '2', status_limit: '3 ч', time_in_status: '3 ч 5 мин', crm_status: 'Актуальный' },
};

/** Server slots -> form ones: items get stable keys for x-for. */
function slotsToForm(slots) {
    return Object.fromEntries(Object.entries(slots || {}).map(([slot, data]) => [slot, {
        only: !!data.only,
        items: (data.items || []).map((item) => ({ key: nextKey(), ...item })),
    }]));
}

function slotsToPayload(slots) {
    return Object.fromEntries(Object.entries(slots).map(([slot, data]) => [slot, {
        only: !!data.only,
        items: data.items
            .map(({ key, ...item }) => (item.type === 'text' ? { type: 'text', text: String(item.text || '').trim() } : item))
            .filter((item) => item.type !== 'text' || item.text !== ''),
    }]));
}

export function personalAnswersPage(config) {
    const t = config.translations;

    return {
        ...telegramGifPicker(t.media),

        translations: t,

        endpoints: config.endpoints,

        placeholders: config.placeholders,

        csrf: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        loading: true,

        loadError: null,

        people: [],

        situations: { penalties: { operation: [], sales: [] }, replies: [], greetings: [], silence: 'silence', opening: 'opening', ping: 'ping' },

        filters: { search: '', role: '', withoutVoice: false },

        /** The person being edited, and their answers as edited. */
        person: null,

        slots: {},

        snapshot: '',

        loadingPerson: false,

        saving: false,

        formError: null,

        /** The situation picked in "add", by slot. */
        adding: '',

        /** Uploads in flight, by slot and type. */
        uploading: {},

        /** Where a placeholder chip inserts: the text edited last. */
        target: null,

        init() {
            this.load();

            window.addEventListener('beforeunload', (event) => {
                if (this.dirty()) {
                    event.preventDefault();
                    event.returnValue = t.messages.leave;
                }
            });
        },

        async request(url, method = 'GET', body = null) {
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrf,
                },
                credentials: 'same-origin',
                cache: 'no-store',
                body: body === null ? null : JSON.stringify(body),
            });

            let json = {};

            try {
                json = await response.json();
            } catch (_) {
                json = {};
            }

            if (!response.ok) {
                const error = new Error(Object.values(json.errors || {}).flat()[0] || json.message || `HTTP ${response.status}`);

                error.status = response.status;

                throw error;
            }

            return json;
        },

        async load() {
            this.loading = true;
            this.loadError = null;

            try {
                const json = await this.request(this.endpoints.index);

                this.people = json.data || [];
                this.situations = json.situations || this.situations;

                /* ?person=12 opens that person: a link from their card. */
                const wanted = Number(new URLSearchParams(window.location.search).get('person'));

                if (wanted && !this.person) {
                    this.select(wanted);
                }
            } catch (error) {
                this.loadError = error?.message || t.messages.failed;
            } finally {
                this.loading = false;
            }
        },

        /*
        |----------------------------------------------------------------
        | People
        |----------------------------------------------------------------
        */

        filtered() {
            const search = this.filters.search.trim().toLowerCase();

            return this.people.filter((person) => (!this.filters.role || person.role === this.filters.role)
                && (!this.filters.withoutVoice || person.counts.voice === 0)
                && (search === ''
                    || String(person.name || '').toLowerCase().includes(search)
                    || String(person.telegram_username || '').toLowerCase().includes(search)));
        },

        /** People with at least one voice message of their own: the progress. */
        withVoice() {
            return this.people.filter((person) => person.counts.voice > 0).length;
        },

        async select(id) {
            if (this.person?.id === id || this.loadingPerson) {
                return;
            }

            if (this.dirty() && !window.confirm(t.messages.leave)) {
                return;
            }

            this.loadingPerson = true;
            this.formError = null;

            try {
                this.accept((await this.request(`${this.endpoints.person}/${id}`)).data);

                const url = new URL(window.location.href);

                url.searchParams.set('person', String(id));
                window.history.replaceState(null, '', url);
            } catch (error) {
                this.notify(error?.message || t.messages.failed, false);
            } finally {
                this.loadingPerson = false;
            }
        },

        accept(data) {
            const { slots, ...person } = data;

            this.person = person;
            this.slots = slotsToForm(slots);
            this.snapshot = JSON.stringify(slotsToPayload(this.slots));
            this.adding = '';
            this.target = null;

            /* The list shows the counts saved. */
            const row = this.people.find((p) => p.id === person.id);

            if (row) {
                row.counts = person.counts;
            }
        },

        dirty() {
            return this.person !== null && JSON.stringify(slotsToPayload(this.slots)) !== this.snapshot;
        },

        async save() {
            if (this.saving || !this.dirty()) {
                return;
            }

            this.saving = true;
            this.formError = null;

            try {
                const json = await this.request(`${this.endpoints.person}/${this.person.id}`, 'PUT', {
                    slots: slotsToPayload(this.slots),
                });

                this.accept(json.data);
                this.notify(json.message || t.messages.saved);
            } catch (error) {
                this.formError = error?.message || t.messages.failed;
            } finally {
                this.saving = false;
            }
        },

        discard() {
            this.slots = slotsToForm(JSON.parse(this.snapshot || '{}'));
            this.formError = null;
            this.target = null;
        },

        notify(message, success = true) {
            window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
        },

        /*
        |----------------------------------------------------------------
        | Situations
        |----------------------------------------------------------------
        */

        /** Every situation the person can be given answers for, in order. */
        situationList() {
            if (!this.person) {
                return [];
            }

            const s = this.situations;

            return [
                ...(s.penalties[this.person.role] || []).map((level) => ({
                    slot: level.slot,
                    group: t.groups.penalty,
                    label: t.situations.penalty
                        .replace(':name', level.name || t.situations.level.replace(':n', level.from))
                        .replace(':from', level.from),
                })),
                ...s.greetings.map((greeting) => ({
                    slot: greeting.slot,
                    group: t.groups.greeting,
                    label: greeting.name || t.situations.greeting.replace(':n', greeting.index + 1),
                })),
                ...s.replies.map((reply) => ({
                    slot: reply.slot,
                    group: t.groups.reply,
                    label: reply.name || t.situations.reply.replace(':n', reply.index + 1),
                })),
                { slot: s.silence, group: t.groups.silence, label: t.situations.silence },
                { slot: s.opening, group: t.groups.opening, label: t.situations.opening },
                { slot: s.ping || 'ping', group: t.groups.ping, label: t.situations.ping },
            ];
        },

        /** The ones not added yet, by group, for the "add" select. */
        addable() {
            const groups = [];

            this.situationList()
                .filter((situation) => !(situation.slot in this.slots))
                .forEach((situation) => {
                    let group = groups.find((g) => g.name === situation.group);

                    if (!group) {
                        group = { name: situation.group, options: [] };
                        groups.push(group);
                    }

                    group.options.push(situation);
                });

            return groups;
        },

        /** The added ones, in the order of situationList(); ones whose kind is gone last. */
        slotKeys() {
            const order = this.situationList().map((situation) => situation.slot);

            return Object.keys(this.slots).sort((a, b) => {
                const ia = order.indexOf(a);
                const ib = order.indexOf(b);

                return (ia < 0 ? 999 : ia) - (ib < 0 ? 999 : ib);
            });
        },

        situation(slot) {
            return this.situationList().find((s) => s.slot === slot) || null;
        },

        /** A kind deleted from the auto replies, or a level from the ladder. */
        gone(slot) {
            return this.situation(slot) === null;
        },

        slotTitle(slot) {
            const situation = this.situation(slot);

            return situation ? `${situation.group} · ${situation.label}` : t.situations.gone;
        },

        addSlot() {
            if (!this.adding || this.adding in this.slots) {
                return;
            }

            this.slots = { ...this.slots, [this.adding]: { only: false, items: [] } };
            this.adding = '';
        },

        removeSlot(slot) {
            const { [slot]: _removed, ...rest } = this.slots;

            this.slots = rest;
            this.target = null;
        },

        /*
        |----------------------------------------------------------------
        | Items
        |----------------------------------------------------------------
        */

        textId(slot, i) {
            return `pa-text-${slot.replace(/[^a-z0-9]/gi, '-')}-${i}`;
        },

        addText(slot) {
            const items = this.slots[slot].items;

            items.push({ key: nextKey(), type: 'text', text: '' });

            const i = items.length - 1;

            this.target = { slot, i };
            this.$nextTick(() => document.getElementById(this.textId(slot, i))?.focus());
        },

        removeItem(slot, i) {
            this.slots[slot].items.splice(i, 1);
            this.target = null;
        },

        focusText(slot, i) {
            this.target = { slot, i };
        },

        /** Puts {name} where the cursor is in the text edited last. */
        insertPlaceholder(name) {
            const target = this.target;
            const item = target ? this.slots[target.slot]?.items[target.i] : null;

            if (!item || item.type !== 'text') {
                return;
            }

            const token = `{${name}}`;
            const field = document.getElementById(this.textId(target.slot, target.i));
            const text = String(item.text ?? '');
            const start = field ? field.selectionStart : text.length;
            const end = field ? field.selectionEnd : text.length;

            item.text = text.slice(0, start) + token + text.slice(end);

            this.$nextTick(() => {
                if (field) {
                    field.focus();
                    field.setSelectionRange(start + token.length, start + token.length);
                }
            });
        },

        preview(text) {
            const sample = SAMPLE[this.person?.language] || SAMPLE.uz;

            return telegramHtml(String(text || '').replace(
                /\{([a-z_]+)\}/g,
                (match, key) => (key in sample ? escapeHtml(key === 'address' && this.person?.address ? this.person.address : sample[key]) : match),
            ));
        },

        mediaUrl(file) {
            return `${this.endpoints.media}/${encodeURIComponent(file)}`;
        },

        uploadKey(slot, type) {
            return `${slot}-${type}`;
        },

        /** Stored on the server at once, put into the answers with the next save. */
        async uploadMedia(slot, type, event) {
            const input = event.target;
            const file = input.files?.[0];

            input.value = '';

            if (!file) {
                return;
            }

            const key = this.uploadKey(slot, type);
            const items = this.slots[slot].items;

            this.uploading = { ...this.uploading, [key]: true };

            try {
                const media = await uploadAnswerMedia(this.endpoints.upload, this.csrf, type, file);

                items.push({ key: nextKey(), type, ...media });
            } catch (error) {
                this.notify(error?.message || t.media.failed, false);
            } finally {
                this.uploading = { ...this.uploading, [key]: false };
            }
        },

        /** Where the Telegram GIF search (media-picker.js) puts a GIF. */
        telegramGifPicked(slot, gif) {
            this.slots[slot]?.items.push({ key: nextKey(), type: 'gif', ...gif });
        },

        counts(person) {
            const c = person.counts;
            const parts = [];

            if (c.voice) {
                parts.push(`🎤 ${c.voice}`);
            }

            if (c.gif) {
                parts.push(`GIF ${c.gif}`);
            }

            if (c.text) {
                parts.push(`Aa ${c.text}`);
            }

            return parts.join(' · ');
        },
    };
}
