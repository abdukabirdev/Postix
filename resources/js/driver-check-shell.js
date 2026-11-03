/*
|--------------------------------------------------------------------------
| Driver check shell
|--------------------------------------------------------------------------
|
| The chrome around the pages: theme, the command palette, the toast stack,
| the route line and hover prefetching, which together make moving between
| pages feel instant.
|
*/

const THEME_KEY = 'theme';

function applyTheme(theme) {
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

function readTheme() {
    try {
        const saved = localStorage.getItem(THEME_KEY);

        if (saved === 'dark' || saved === 'light') {
            return saved;
        }
    } catch (e) {
        /* storage blocked: fall through to the system theme */
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/* Normalised text for the palette: case and diacritics do not matter. */
function fold(text) {
    return String(text || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/ё/g, 'е');
}

export function registerShell(Alpine) {
    Alpine.store('dcTheme', {
        theme: readTheme(),

        toggle() {
            this.theme = this.theme === 'dark' ? 'light' : 'dark';

            try {
                localStorage.setItem(THEME_KEY, this.theme);
            } catch (e) {
                /* not persisted, still applied */
            }

            applyTheme(this.theme);
        },
    });

    /* Section menu on a phone, palette anywhere. */
    Alpine.store('dcShell', {
        menuOpen: false,
        paletteOpen: false,
    });

    /*
     * The left menu: one section open at a time, the one of the open page
     * first. The open page's link is scrolled into view when the menu is
     * longer than the screen.
     */
    Alpine.data('dcNav', (current) => ({
        open: current,

        init() {
            this.$nextTick(() => {
                const box = this.$root.closest('.dc-side, [data-drawer]');
                const link = this.$root.querySelector('[aria-current="page"]');

                if (box && link && link.offsetTop > box.clientHeight - 80) {
                    box.scrollTop = link.offsetTop - box.clientHeight / 2;
                }
            });
        },

        isOpen(key) {
            return this.open === key;
        },

        toggle(key) {
            this.open = this.open === key ? null : key;
        },
    }));

    Alpine.data('dcPalette', (items) => ({
        items,
        query: '',
        index: 0,

        get open() {
            return Alpine.store('dcShell').paletteOpen;
        },

        get results() {
            const q = fold(this.query).trim();

            if (q === '') {
                return this.items;
            }

            return this.items.filter((item) => fold(`${item.name} ${item.group}`).includes(q));
        },

        init() {
            window.addEventListener('keydown', (event) => {
                const mod = event.ctrlKey || event.metaKey;
                const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(event.target?.tagName || '')
                    || event.target?.isContentEditable;

                /* By the physical key, not the letter: on a Russian layout K types «л». */
                const keyK = event.code === 'KeyK' || event.key?.toLowerCase() === 'k';
                const slash = event.code === 'Slash' || event.key === '/';

                if ((mod && keyK) || (!typing && !mod && slash)) {
                    event.preventDefault();
                    this.show();
                }
            });

            this.$watch('query', () => {
                this.index = 0;
            });
        },

        show() {
            Alpine.store('dcShell').paletteOpen = true;
            Alpine.store('dcShell').menuOpen = false;
            this.query = '';
            this.index = 0;
            this.$nextTick(() => this.$refs.field?.focus());
        },

        hide() {
            Alpine.store('dcShell').paletteOpen = false;
        },

        move(step) {
            const total = this.results.length;

            if (total === 0) {
                return;
            }

            this.index = (this.index + step + total) % total;

            this.$nextTick(() => {
                this.$refs.list?.querySelector('[data-on]')?.scrollIntoView({ block: 'nearest' });
            });
        },

        go() {
            const item = this.results[this.index];

            if (item) {
                this.hide();
                window.location.href = item.path;
            }
        },
    }));

    Alpine.data('dcToasts', () => ({
        toasts: [],
        nextId: 1,

        add({ message = '', success = true, color = null, timeout = 4200 } = {}) {
            const id = this.nextId++;

            this.toasts.push({ id, message, success: !!success, color, show: true });

            if (timeout > 0) {
                setTimeout(() => this.remove(id), timeout);
            }
        },

        remove(id) {
            const toast = this.toasts.find((t) => t.id === id);

            if (!toast) {
                return;
            }

            toast.show = false;
            setTimeout(() => {
                this.toasts = this.toasts.filter((t) => t.id !== id);
            }, 200);
        },

        tone(toast) {
            const key = String(toast.color || '').toLowerCase();

            if (key === 'amber' || key === 'warning') return 'warning';
            if (key === 'blue') return 'info';
            if (key === 'red') return 'error';
            if (key === 'emerald' || key === 'green') return 'success';

            return toast.success ? 'success' : 'error';
        },

        init() {
            window.addEventListener('toast', (event) => {
                const detail = event.detail ?? {};

                if (typeof detail === 'string') {
                    this.add({ message: detail });

                    return;
                }

                this.add({
                    message: detail.message ?? detail.msg ?? '',
                    success: typeof detail.success === 'boolean' ? detail.success : detail.type !== 'error',
                    color: detail.color ?? null,
                    timeout: typeof detail.timeout === 'number' ? detail.timeout : 4200,
                });
            });
        },
    }));

    initRouteMotion();
}

/*
 * Prefetch a page when the pointer is about to click it, and show the route
 * line the moment it is clicked. Together with the view transition this makes
 * a page change feel like switching a tab.
 */
function initRouteMotion() {
    const line = () => document.querySelector('[data-route-line]');
    const warmed = new Set();

    const internal = (anchor) => {
        if (!anchor || !anchor.href || anchor.target === '_blank' || anchor.hasAttribute('download')) {
            return false;
        }

        const url = new URL(anchor.href, window.location.href);

        return url.origin === window.location.origin
            && url.pathname !== window.location.pathname
            && !url.pathname.includes('/export');
    };

    const warm = (event) => {
        const anchor = event.target.closest?.('a[href]');

        if (!internal(anchor) || warmed.has(anchor.href)) {
            return;
        }

        warmed.add(anchor.href);

        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = anchor.href;
        link.as = 'document';
        document.head.appendChild(link);
    };

    document.addEventListener('pointerover', warm, { passive: true });
    document.addEventListener('touchstart', warm, { passive: true });

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) {
            return;
        }

        const anchor = event.target.closest?.('a[href]');

        if (internal(anchor)) {
            line()?.setAttribute('data-run', '');
        }
    });

    /* Back/forward restores the page from cache: the line must not stay lit. */
    window.addEventListener('pageshow', () => line()?.removeAttribute('data-run'));
}
