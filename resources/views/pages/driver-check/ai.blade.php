@extends('layouts.driver-check')

@section('title', __('driver_check.tree.pages.ai_' . $part))

@section('content')

@php
    $descriptions = [
        'overview' => 'Где работает Claude Haiku и сколько это стоит. «Тень» — ИИ пишет, но ничего не отправляется: в журнале видно, что он написал бы. Кончатся деньги или лимит — всё само переходит на бесплатные правила.',
        'journal' => 'Каждый вызов ИИ: что спросили, что он ответил, ушло ли это людям. Нажмите на строку, чтобы увидеть полностью.',
        'prompts' => 'Все тексты, которые получает ИИ. Можно править: ИИ берёт новый текст со следующего вызова. «Вернуть стандартный» отменяет правки.',
    ];
@endphp

<div
    x-data="dcAi({
        part: @js($part),
        endpoints: {
            show: @js(route('api.telegram.ai')),
            update: @js(route('api.telegram.ai.update')),
            resume: @js(route('api.telegram.ai.resume')),
            calls: @js(route('api.telegram.ai.calls')),
            prompts: @js(route('api.telegram.ai.prompts')),
            promptSave: @js(route('api.telegram.ai.prompts.save', '__KEY__')),
            promptReset: @js(route('api.telegram.ai.prompts.reset', '__KEY__')),
            testDriver: @js(route('api.telegram.ai.test.driver')),
        },
    })"
>
    <x-driver-check.shell>

        <x-driver-check.page-header
            :title="__('driver_check.tree.pages.ai_' . $part)"
            :description="$descriptions[$part]"
        >
            <x-slot:actions>
                <x-driver-check.button
                    size="icon"
                    x-on:click="load(); part === 'journal' && loadCalls(); part === 'prompts' && loadPrompts()"
                    ::disabled="loading"
                    :title="__('telegram.ui.refresh')"
                    :aria-label="__('telegram.ui.refresh')"
                >
                    <x-driver-check.icon name="refresh" class="h-4 w-4" ::class="loading && 'animate-spin'" />
                </x-driver-check.button>
            </x-slot:actions>
        </x-driver-check.page-header>

        <p x-show="error" x-cloak class="mb-4 rounded-lg bg-error-50 px-4 py-2.5 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-400" x-text="error"></p>

        {{-- The state: may the AI work now, and if not why --}}
        <div x-show="state" x-cloak class="mb-4">
            <div
                x-show="state && state.blocker"
                class="flex flex-col gap-3 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-warning-500/30 dark:bg-warning-500/10"
            >
                <p class="text-[13px] text-warning-800 dark:text-warning-300" x-text="blockerText()"></p>
                <x-driver-check.button size="sm" x-show="state && state.blocker === 'paused'" x-on:click="resume()" ::disabled="busy">
                    Попробовать ИИ снова
                </x-driver-check.button>
            </div>
            <p
                x-show="state && !state.blocker"
                class="flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300"
            >
                <span class="dc-dot bg-success-500"></span>
                <span>ИИ доступен · <span x-text="state && state.model"></span> · уровень рассуждений <span x-text="state && state.effort"></span></span>
            </p>
        </div>

        @if ($part === 'overview')
        {{-- ===================== Modes and money ===================== --}}

        <div class="grid gap-4">
            <x-driver-check.block title="Где работает ИИ">
                <x-driver-check.table min="640px">
                    <thead>
                        <tr>
                            <th>Раздел</th>
                            <th>Режим</th>
                            <th class="dc-num">Сегодня вызовов</th>
                            <th class="dc-num">Использовано</th>
                            <th class="dc-num">Ошибок</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(area, key) in areas" :key="key">
                            <tr>
                                <td class="dc-main">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">
                                        <span x-text="area.title"></span>
                                        <span x-show="!area.ready" class="dc-tag ml-1" data-tone="warn">подключим следующим шагом</span>
                                    </p>
                                    <p class="dc-sub2" x-text="area.about"></p>
                                </td>
                                <td>
                                    <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                                        <template x-for="mode in ['off', 'shadow', 'live']" :key="mode">
                                            <button
                                                type="button"
                                                class="px-3 py-1.5 text-[12.5px] font-medium transition"
                                                :class="state && state.modes[key] === mode
                                                    ? (mode === 'live' ? 'bg-success-600 text-white' : (mode === 'shadow' ? 'bg-brand-500 text-white' : 'bg-gray-800 text-white dark:bg-white dark:text-gray-900'))
                                                    : 'bg-white text-gray-600 hover:bg-gray-50 dark:bg-transparent dark:text-gray-300 dark:hover:bg-white/[0.06]'"
                                                :disabled="busy || !state"
                                                x-on:click="setMode(key, mode)"
                                                x-text="modeLabels[mode]"
                                            ></button>
                                        </template>
                                    </div>
                                    <p
                                        x-show="state && state.modes[key] !== 'off' && state.effective[key] === 'off'"
                                        class="dc-sub2 mt-1 text-warning-600"
                                    >сейчас работают бесплатные правила</p>
                                </td>
                                <td class="dc-num" x-text="todayOf(key).total"></td>
                                <td class="dc-num" x-text="todayOf(key).used"></td>
                                <td class="dc-num" x-text="todayOf(key).failed + todayOf(key).refused"></td>
                            </tr>
                        </template>
                    </tbody>
                </x-driver-check.table>
            </x-driver-check.block>

            <div class="grid gap-4 lg:grid-cols-2">
                <x-driver-check.block title="Расходы" :flush="false">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-[11.5px] font-medium uppercase tracking-wide text-gray-400">Сегодня</p>
                            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white" x-text="money(state && state.spend.today)"></p>
                        </div>
                        <div>
                            <p class="text-[11.5px] font-medium uppercase tracking-wide text-gray-400">С начала месяца</p>
                            <p class="text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                                <span x-text="money(state && state.spend.month)"></span>
                                <span class="text-[13px] font-normal text-gray-400" x-show="state && state.monthly_cap > 0" x-text="'из ' + money(state && state.monthly_cap)"></span>
                            </p>
                        </div>
                    </div>

                    <form class="mt-5 flex flex-wrap items-end gap-2" x-on:submit.prevent="saveCap()">
                        <label class="block">
                            <span class="mb-1 block text-[12.5px] text-gray-500">Лимит в месяц, $ (0 — без лимита)</span>
                            <input type="number" min="0" step="0.5" x-model="cap" class="w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] tabular-nums dark:border-gray-700 dark:bg-white/[0.03] dark:text-white">
                        </label>
                        <x-driver-check.button type="submit" size="sm" ::disabled="busy">Сохранить</x-driver-check.button>
                    </form>
                    <p class="mt-2 text-[12px] text-gray-400">Дойдёт до лимита — ИИ остановится сам, до конца месяца ответят бесплатные правила.</p>
                </x-driver-check.block>

                <x-driver-check.block title="Проверить имена на ИИ" :flush="false">
                    <form class="grid gap-2 sm:grid-cols-2" x-on:submit.prevent="runTest()">
                        <label class="sm:col-span-2">
                            <span class="mb-1 block text-[12.5px] text-gray-500">ФИО водителя из базы</span>
                            <input type="text" x-model="test.expected" placeholder="GIYASOV KURBONALI ABDURAHIMOVICH" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] dark:border-gray-700 dark:bg-white/[0.03] dark:text-white">
                        </label>
                        <label>
                            <span class="mb-1 block text-[12.5px] text-gray-500">Имя в Telegram</span>
                            <input type="text" x-model="test.first_name" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] dark:border-gray-700 dark:bg-white/[0.03] dark:text-white">
                        </label>
                        <label>
                            <span class="mb-1 block text-[12.5px] text-gray-500">Фамилия в Telegram</span>
                            <input type="text" x-model="test.last_name" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] dark:border-gray-700 dark:bg-white/[0.03] dark:text-white">
                        </label>
                        <label>
                            <span class="mb-1 block text-[12.5px] text-gray-500">@username</span>
                            <input type="text" x-model="test.username" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-[14px] dark:border-gray-700 dark:bg-white/[0.03] dark:text-white">
                        </label>
                        <div class="flex items-end">
                            <x-driver-check.button type="submit" variant="primary" size="sm" ::disabled="testing">
                                <span x-text="testing ? 'Спрашиваю…' : 'Проверить'"></span>
                            </x-driver-check.button>
                        </div>
                    </form>

                    <div x-show="testResult" x-cloak class="mt-4 space-y-2 border-t border-gray-100 pt-3 text-[13px] dark:border-gray-800">
                        <p>
                            <span class="text-gray-500">Код:</span>
                            <span class="dc-tag" :data-tone="testResult && testResult.code.matched ? 'ok' : 'bad'" x-text="testResult && (testResult.code.matched ? 'совпадает' : 'не совпадает')"></span>
                            <span class="tabular-nums text-gray-500" x-text="testResult && '(' + testResult.code.score + ')'"></span>
                        </p>
                        <p x-show="testResult && testResult.ai">
                            <span class="text-gray-500">ИИ:</span>
                            <span class="dc-tag" :data-tone="testResult && testResult.ai && testResult.ai.verdict === 'match' ? 'ok' : 'bad'" x-text="testResult && testResult.ai && (testResult.ai.verdict === 'match' ? 'совпадает' : 'не совпадает')"></span>
                            <span class="text-gray-800 dark:text-gray-100" x-text="testResult && testResult.ai && testResult.ai.reason"></span>
                        </p>
                        <p x-show="testResult && testResult.error" class="text-error-600" x-text="testResult && testResult.error"></p>
                        <p class="text-[12px] text-gray-400" x-show="testResult && testResult.duration_ms" x-text="testResult && (testResult.duration_ms + ' мс · ' + money(testResult.cost_usd))"></p>
                    </div>
                </x-driver-check.block>
            </div>
        </div>
        @endif

        @if ($part === 'journal')
        {{-- ===================== Journal ===================== --}}

        <x-driver-check.block title="Вызовы ИИ" count="calls.length">
            <x-slot:actions>
                <select class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-[13px] dark:border-gray-700 dark:bg-gray-900 dark:text-white" x-on:change="setFilter('area', $event.target.value)">
                    <option value="">Все разделы</option>
                    <option value="driver_check">Проверка водителей</option>
                    <option value="penalties">Штрафы</option>
                    <option value="replies">Ответы людям</option>
                    <option value="profile">Профиль</option>
                    <option value="test">Тест</option>
                </select>
                <select class="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-[13px] dark:border-gray-700 dark:bg-gray-900 dark:text-white" x-on:change="setFilter('status', $event.target.value)">
                    <option value="">Любой итог</option>
                    <option value="ok">Ответил</option>
                    <option value="failed">Ошибка</option>
                    <option value="refused">Отказ</option>
                </select>
            </x-slot:actions>

            <x-driver-check.table min="880px">
                <thead>
                    <tr>
                        <th>Когда</th>
                        <th>Раздел</th>
                        <th>Кому / о чём</th>
                        <th>Ответ ИИ</th>
                        <th>Режим</th>
                        <th class="dc-num">$</th>
                        <th class="dc-num">мс</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="call in calls" :key="call.id">
                        <tr data-click x-on:click="open = open === call.id ? null : call.id">
                            <td class="whitespace-nowrap tabular-nums" x-text="time(call.at)"></td>
                            <td>
                                <span x-text="areaTitle(call.area)"></span>
                                <span class="dc-sub2 block" x-text="call.kind"></span>
                            </td>
                            <td class="dc-main">
                                <span x-text="call.person || call.subject || '—'"></span>
                                <template x-if="open === call.id">
                                    <pre class="mt-2 max-w-xl whitespace-pre-wrap rounded-lg bg-gray-50 p-2 text-[12px] text-gray-700 dark:bg-white/[0.04] dark:text-gray-300" x-text="call.request"></pre>
                                </template>
                            </td>
                            <td class="dc-main">
                                <span class="dc-tag mr-1" :data-tone="statusTone(call)" x-text="{ ok: 'ответил', failed: 'ошибка', refused: 'отказ' }[call.status]"></span>
                                <span :class="open === call.id ? '' : 'line-clamp-2'" x-text="answerText(call)"></span>
                                <template x-if="open === call.id && call.free_text">
                                    <p class="mt-2 text-[12px] text-gray-500"><b>Ушло бесплатным режимом:</b> <span x-text="call.free_text"></span></p>
                                </template>
                                <template x-if="open === call.id && call.error && call.answer">
                                    <p class="mt-2 text-[12px] text-error-600" x-text="call.error"></p>
                                </template>
                            </td>
                            <td>
                                <span class="dc-tag" :data-tone="call.mode === 'live' ? (call.used ? 'ok' : undefined) : 'run'" x-text="call.mode === 'live' ? (call.used ? 'использован' : 'вкл') : 'тень'"></span>
                            </td>
                            <td class="dc-num" x-text="money(call.cost_usd)"></td>
                            <td class="dc-num" x-text="call.duration_ms"></td>
                        </tr>
                    </template>
                </tbody>
            </x-driver-check.table>

            <p x-show="callsLoaded && calls.length === 0" x-cloak class="dc-none">Вызовов пока не было</p>

            <x-slot:foot>
                <div x-show="more" x-cloak class="flex justify-center p-3">
                    <x-driver-check.button size="sm" x-on:click="loadMore()">Показать ещё</x-driver-check.button>
                </div>
            </x-slot:foot>
        </x-driver-check.block>
        @endif

        @if ($part === 'prompts')
        {{-- ===================== Prompts ===================== --}}

        <x-driver-check.block title="Весь промпт по разделам" :flush="false">
            <p class="mb-3 text-[13px] text-gray-500">Что именно получает ИИ, по порядку. Части ниже можно править.</p>
            <div class="flex flex-wrap gap-2">
                <template x-for="(area, key) in areas" :key="key">
                    <x-driver-check.button size="sm" x-on:click="whole = whole === key ? null : key" ::class="whole === key && 'ring-2 ring-brand-500/40'">
                        <span x-text="area.title"></span>
                    </x-driver-check.button>
                </template>
            </div>
            <template x-if="whole">
                <div class="mt-3">
                    <p class="mb-2 text-[12.5px] text-gray-500">
                        Части: <span x-text="(promptAreas[whole]?.system || []).map(k => promptInfo[k]?.title || k).join(' → ')"></span>
                    </p>
                    <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-[12.5px] leading-relaxed text-gray-800 dark:bg-white/[0.04] dark:text-gray-200" x-text="wholePrompt(whole)"></pre>
                </div>
            </template>
        </x-driver-check.block>

        <div class="mt-4 grid gap-4">
            <template x-for="(item, key) in prompts" :key="key">
                <x-driver-check.block :flush="false">
                    <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="text-[15px] font-semibold text-gray-950 dark:text-white">
                                <span x-text="promptInfo[key]?.title || key"></span>
                                <span x-show="item.edited" class="dc-tag ml-1" data-tone="run">изменён</span>
                                <span x-show="changed(key)" class="dc-tag ml-1" data-tone="warn">не сохранено</span>
                            </h2>
                            <p class="text-[12.5px] text-gray-500" x-text="promptInfo[key]?.about"></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-driver-check.button size="sm" variant="primary" x-on:click="savePrompt(key)" ::disabled="busy || !changed(key)">Сохранить</x-driver-check.button>
                            <x-driver-check.button size="sm" x-show="item.edited" x-on:click="resetPrompt(key)" ::disabled="busy">Вернуть стандартный</x-driver-check.button>
                            <x-driver-check.button size="sm" variant="ghost" x-show="item.edited" x-on:click="showDefault[key] = !showDefault[key]">
                                <span x-text="showDefault[key] ? 'Скрыть стандартный' : 'Показать стандартный'"></span>
                            </x-driver-check.button>
                        </div>
                    </div>

                    <textarea
                        x-model="drafts[key]"
                        rows="12"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-[12.5px] leading-relaxed text-gray-900 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-100"
                        x-init="$nextTick(() => { $el.style.height = Math.min(640, Math.max(160, $el.scrollHeight + 4)) + 'px' })"
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min(640, $el.scrollHeight + 4) + 'px'"
                    ></textarea>
                    <p class="mt-1 text-right text-[11.5px] tabular-nums text-gray-400" x-text="(drafts[key] || '').length + ' символов'"></p>

                    <template x-if="showDefault[key]">
                        <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-[12.5px] text-gray-600 dark:bg-white/[0.04] dark:text-gray-300" x-text="item.default"></pre>
                    </template>
                </x-driver-check.block>
            </template>
        </div>
        @endif

    </x-driver-check.shell>
</div>

@endsection
