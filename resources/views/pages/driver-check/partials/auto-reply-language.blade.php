{{-- The language being edited: one switch for every text of the auto replies. --}}
<div
    class="dc-chips inline-flex max-w-full shrink-0 gap-0.5 self-start overflow-x-auto rounded-lg bg-gray-100 p-0.5 sm:self-auto dark:bg-white/[0.06]"
    role="group"
    aria-label="{{ __('telegram.auto_replies.sections.language') }}"
>
    <template x-for="lang in languages" :key="'lang-' + lang">
        <button
            type="button"
            x-on:click="language = lang"
            :aria-pressed="language === lang"
            :class="language === lang
                ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                : 'text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
            class="dc-tap inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-md px-3 text-[13px] font-medium outline-none transition
                   focus-visible:ring-2 focus-visible:ring-brand-500/50 sm:h-8"
        >
            <span class="font-mono text-[10px] font-semibold opacity-60" x-text="lang.toUpperCase()"></span>
            <span x-text="languageNames[lang]"></span>
        </button>
    </template>
</div>
