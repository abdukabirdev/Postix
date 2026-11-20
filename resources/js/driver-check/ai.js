/*
|--------------------------------------------------------------------------
| Claude in the flows (Driver Check > ИИ)
|--------------------------------------------------------------------------
|
| Three pages, one component:
|   overview  the modes of each area (off / shadow / live), whether the AI
|             may work now and why not, the money and the cap, a test of
|             the driver-name prompt on any names
|   journal   every call: what was asked, what Claude answered, used or not
|   prompts   every prompt, editable, with "back to the default", and the
|             whole prompt of each area as Claude receives it
|
*/

import { requestJson } from './monitoring';

const REFRESH = 20000;

function notify(message, success = true) {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, success } }));
}

export const AI_AREAS = {
    driver_check: { title: 'Проверка водителей', about: 'Код сказал «не совпадает», а в Telegram есть имя — решает ИИ.', ready: true },
    penalties: { title: 'Штрафы', about: 'ИИ пишет все сообщения вокруг штрафа: комментарий, приветствие, «это тоже», напоминания.', ready: false },
    replies: { title: 'Ответы людям', about: 'ИИ отвечает, ставит реакцию или оставляет непрочитанным для Шохруха.', ready: false },
};

export const AI_PROMPTS = {
    driver_check: { title: 'Проверка водителей', about: 'Как решать, тот же человек в Telegram или нет. Только для проверки водителей.' },
    common: { title: 'Общий: кто такой Шохрух и как писать', about: 'Начало промпта штрафов и ответов: роль, стиль, запреты.' },
    examples: { title: 'Примеры реальных переписок', about: 'Как пишут Шохрух и люди на самом деле. ИИ подстраивается под них.' },
    penalties: { title: 'Штрафы: что делать', about: 'Уровни 1–4 и виды сообщений вокруг штрафа.' },
    replies: { title: 'Ответы людям', about: 'Когда отвечать, когда реакция, когда оставить Шохруху.' },
    profile: { title: 'Профиль человека', about: 'Составляется один раз по переписке: как Шохрух общается с этим человеком.' },
};

const MODE_LABELS = { off: 'Выкл', shadow: 'Тень', live: 'Вкл' };

const BLOCKERS = {
    no_key: 'Нет ключа ANTHROPIC_API_KEY в .env — работают бесплатные правила.',
    paused: 'ИИ на паузе — работают бесплатные правила.',
    cap: 'Достигнут месячный лимит — работают бесплатные правила.',
};

export function aiPage(config) {
    return {
        part: config.part,
        endpoints: config.endpoints,

        areas: AI_AREAS,
        promptInfo: AI_PROMPTS,
        modeLabels: MODE_LABELS,

        loading: false,
        error: null,
        state: null,
        busy: false,
        cap: '',
        capFilled: false,
        timer: null,

        calls: [],
        callsLoaded: false,
        more: false,
        open: null,
        filter: { area: '', status: '' },

        prompts: {},
        promptAreas: {},
        drafts: {},
        showDefault: {},
        whole: null,

        test: { expected: '', first_name: '', last_name: '', username: '' },
        testing: false,
        testResult: null,

        init() {
            this.load();

            if (this.part === 'journal') {
                this.loadCalls();
            }

            if (this.part === 'prompts') {
                this.loadPrompts();
            }

            this.timer = setInterval(() => {
                if (document.hidden) {
                    return;
                }

                this.load();

                if (this.part === 'journal' && this.open === null) {
                    this.loadCalls();
                }
            }, REFRESH);
        },

        destroy() {
            clearInterval(this.timer);
        },

        async load() {
            this.loading = true;

            try {
                this.state = (await requestJson(this.endpoints.show)).data;
                this.error = null;

                if (!this.capFilled) {
                    this.cap = this.state.monthly_cap;
                    this.capFilled = true;
                }
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        blockerText() {
            if (!this.state || !this.state.blocker) {
                return null;
            }

            let text = BLOCKERS[this.state.blocker] ?? this.state.blocker;

            if (this.state.blocker === 'paused' && this.state.paused) {
                text += ' Причина: ' + (this.state.paused.reason || '—');

                if (this.state.paused.until) {
                    text += '. Снова попробует в ' + this.time(this.state.paused.until) + '.';
                }
            }

            return text;
        },

        async setMode(area, mode) {
            if (this.busy || !this.state || this.state.modes[area] === mode) {
                return;
            }

            this.busy = true;

            try {
                this.state = (await requestJson(this.endpoints.update, 'PATCH', { modes: { [area]: mode } })).data;
                notify('Сохранено');
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.busy = false;
            }
        },

        async saveCap() {
            this.busy = true;

            try {
                this.state = (await requestJson(this.endpoints.update, 'PATCH', { monthly_cap: Number(this.cap) || 0 })).data;
                this.cap = this.state.monthly_cap;
                notify('Лимит сохранён');
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.busy = false;
            }
        },

        async resume() {
            this.busy = true;

            try {
                this.state = (await requestJson(this.endpoints.resume, 'POST')).data;
                notify('Пауза снята: следующий вызов пойдёт в ИИ');
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.busy = false;
            }
        },

        async runTest() {
            if (!this.test.expected.trim()) {
                notify('Впишите ФИО из базы', false);

                return;
            }

            this.testing = true;
            this.testResult = null;

            try {
                this.testResult = (await requestJson(this.endpoints.testDriver, 'POST', this.test)).data;
                this.load();
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.testing = false;
            }
        },

        /* ---------- journal ---------- */

        callsUrl(before = null) {
            const params = new URLSearchParams();

            if (this.filter.area) params.set('area', this.filter.area);
            if (this.filter.status) params.set('status', this.filter.status);
            if (before) params.set('before', before);
            params.set('limit', 50);

            return this.endpoints.calls + '?' + params.toString();
        },

        async loadCalls() {
            try {
                const rows = (await requestJson(this.callsUrl())).data;
                this.calls = rows;
                this.more = rows.length === 50;
                this.callsLoaded = true;
            } catch (e) {
                this.error = e.message;
            }
        },

        async loadMore() {
            const last = this.calls[this.calls.length - 1];

            if (!last) {
                return;
            }

            const rows = (await requestJson(this.callsUrl(last.id))).data;
            this.calls = this.calls.concat(rows);
            this.more = rows.length === 50;
        },

        setFilter(key, value) {
            this.filter[key] = value;
            this.open = null;
            this.loadCalls();
        },

        areaTitle(area) {
            if (area === 'test') return 'Тест';
            if (area === 'profile') return 'Профиль';

            return this.areas[area]?.title ?? area;
        },

        answerText(call) {
            const a = call.answer;

            if (!a) {
                return call.error || '—';
            }

            if (a.verdict) {
                return (a.verdict === 'match' ? 'совпадает' : 'не совпадает') + (a.reason ? ' — ' + a.reason : '');
            }

            if (a.text) {
                return (a.action ? a.action + ': ' : '') + a.text;
            }

            if (a.action) {
                return a.action + (a.emoji ? ' ' + a.emoji : '');
            }

            return JSON.stringify(a);
        },

        statusTone(call) {
            return { ok: 'ok', failed: 'bad', refused: 'warn' }[call.status];
        },

        /* ---------- prompts ---------- */

        async loadPrompts() {
            try {
                this.applyPrompts((await requestJson(this.endpoints.prompts)).data);
            } catch (e) {
                this.error = e.message;
            }
        },

        applyPrompts(data) {
            this.prompts = data.items;
            this.promptAreas = data.areas;

            for (const [key, item] of Object.entries(data.items)) {
                this.drafts[key] = item.text;
            }
        },

        changed(key) {
            return this.prompts[key] && this.drafts[key] !== this.prompts[key].text;
        },

        async savePrompt(key) {
            this.busy = true;

            try {
                this.applyPrompts((await requestJson(this.endpoints.promptSave.replace('__KEY__', key), 'PUT', { text: this.drafts[key] })).data);
                notify('Промпт сохранён — ИИ берёт его со следующего вызова');
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.busy = false;
            }
        },

        async resetPrompt(key) {
            if (!window.confirm('Вернуть стандартный текст? Ваши правки этого промпта пропадут.')) {
                return;
            }

            this.busy = true;

            try {
                this.applyPrompts((await requestJson(this.endpoints.promptReset.replace('__KEY__', key), 'DELETE')).data);
                notify('Возвращён стандартный текст');
            } catch (e) {
                notify(e.message, false);
            } finally {
                this.busy = false;
            }
        },

        wholePrompt(area) {
            const spec = this.promptAreas[area];

            if (!spec) {
                return '';
            }

            const parts = spec.system.map((key) => '=== ' + (this.promptInfo[key]?.title ?? key) + ' ===\n' + (this.prompts[key]?.text ?? ''));

            let text = 'СИСТЕМНЫЙ ПРОМПТ\n\n' + parts.join('\n\n');

            text += '\n\n\nСООБЩЕНИЕ ДЛЯ ИИ (пример)\n\n' + (spec.user || 'Собирается из события: человек, его профиль, последние сообщения чата, краткая сводка. Появится, когда подключим этот раздел.');

            return text;
        },

        /* ---------- helpers ---------- */

        money(value) {
            const n = Number(value || 0);

            return '$' + (n < 0.01 && n > 0 ? n.toFixed(4) : n.toFixed(2));
        },

        time(iso) {
            if (!iso) {
                return '—';
            }

            const d = new Date(iso);

            return d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        },

        todayOf(area) {
            return this.state?.today?.[area] ?? { total: 0, ok: 0, failed: 0, refused: 0, used: 0 };
        },
    };
}
