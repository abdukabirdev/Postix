/*
|--------------------------------------------------------------------------
| Formatting helpers shared by every driver-check page
|--------------------------------------------------------------------------
|
| Each page used to carry its own copy of these, which is how the same
| value ended up rendered three different ways across the panel.
|
*/

const EM_DASH = '—';

/**
 * The document language drives every number/date format, so switching the
 * app locale switches the panel without touching a single page.
 */
function locale() {
    return document.documentElement.lang || 'uz-UZ';
}

export function formatNumber(value) {
    const number = Number(value ?? 0);

    if (Number.isNaN(number)) {
        return '0';
    }

    return number.toLocaleString(locale());
}

/**
 * Large counters are shortened on narrow screens: "12 480" does not fit a
 * stat tile on a 360px phone, "12.5K" does.
 */
export function formatCompact(value) {
    const number = Number(value ?? 0);

    if (Number.isNaN(number)) {
        return '0';
    }

    if (Math.abs(number) < 10000) {
        return number.toLocaleString(locale());
    }

    try {
        return new Intl.NumberFormat(locale(), {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(number);
    } catch (_) {
        return number.toLocaleString(locale());
    }
}

export function formatPercent(value, digits = 1) {
    if (value === null || value === undefined || value === '') {
        return '0';
    }

    const number = Number(value);

    return Number.isNaN(number) ? '0' : number.toFixed(digits);
}

export function formatScore(value, digits = 1) {
    if (value === null || value === undefined || value === '') {
        return EM_DASH;
    }

    const number = Number(value);

    return Number.isNaN(number) ? EM_DASH : number.toFixed(digits);
}

export function toDate(value) {
    if (!value) {
        return null;
    }

    const date = new Date(String(value).replace(' ', 'T'));

    return Number.isNaN(date.getTime()) ? null : date;
}

/*
 * Uzbek (Latin) has no usable month or relative-time names in many browsers'
 * Intl data: the panel showed "2026 M10 15" and "-29 d". These are written
 * out here for `uz` only; the other languages keep Intl.
 */
const UZ_MONTHS = ['yan', 'fev', 'mar', 'apr', 'may', 'iyn', 'iyl', 'avg', 'sen', 'okt', 'noy', 'dek'];

const isUzbek = () => locale().toLowerCase().startsWith('uz');

function uzDate(date, withTime) {
    const pad = (n) => String(n).padStart(2, '0');
    const day = `${pad(date.getDate())} ${UZ_MONTHS[date.getMonth()]} ${date.getFullYear()}`;

    return withTime ? `${day}, ${pad(date.getHours())}:${pad(date.getMinutes())}` : day;
}

function uzRelative(date) {
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const absolute = Math.abs(seconds);

    if (absolute < 60) {
        return 'hozirgina';
    }

    const units = [
        ['yil', 31536000],
        ['oy', 2592000],
        ['kun', 86400],
        ['soat', 3600],
        ['daqiqa', 60],
    ];

    const [name, size] = units.find(([, size]) => absolute >= size);
    const count = Math.round(absolute / size);

    return seconds < 0 ? `${count} ${name} oldin` : `${count} ${name}dan keyin`;
}

export function formatDate(value, withTime = true) {
    const date = toDate(value);

    if (!date) {
        return value ? String(value) : EM_DASH;
    }

    if (isUzbek()) {
        return uzDate(date, withTime);
    }

    return new Intl.DateTimeFormat(locale(), {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(date);
}

/**
 * "3 soat oldin" answers "is this fresh?" at a glance, which is the only
 * question a "last check" column is ever asked. The absolute date stays
 * available as the element title.
 */
export function formatRelative(value) {
    const date = toDate(value);

    if (!date) {
        return EM_DASH;
    }

    if (isUzbek()) {
        return uzRelative(date);
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const absolute = Math.abs(seconds);

    const units = [
        ['year', 31536000],
        ['month', 2592000],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    try {
        const formatter = new Intl.RelativeTimeFormat(locale(), {
            numeric: 'auto',
        });

        for (const [unit, size] of units) {
            if (absolute >= size) {
                return formatter.format(Math.round(seconds / size), unit);
            }
        }

        return formatter.format(Math.round(seconds / 60), 'minute');
    } catch (_) {
        return formatDate(value);
    }
}

/**
 * The first letter of the first two words that have one. Telegram names are
 * full of emoji: charAt(0) of one is half of it and showed as "М�", and a
 * word that is only an emoji has no letter to give.
 */
export function initials(name) {
    const letters = String(name ?? '')
        .trim()
        .split(/\s+/)
        .map((part) => part.match(/[\p{L}\p{N}]/u)?.[0])
        .filter(Boolean)
        .slice(0, 2)
        .map((letter) => letter.toUpperCase())
        .join('');

    return letters || '?';
}

export function telegramHandle(username) {
    if (!username) {
        return null;
    }

    return '@' + String(username).replace(/^@/, '');
}

/**
 * A t.me address for anything the panel shows as a Telegram handle: a
 * @username, a t.me link in any spelling, or a tg:// deep link.
 *
 * Returns null when the value cannot be opened - a numeric chat or user id
 * is not addressable by URL - and the caller then renders plain text,
 * because an <a> whose href is null is exactly that.
 */
export function telegramUrl(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const raw = String(value).trim();

    if (raw === '') {
        return null;
    }

    /* -100123... and 123...: an id, not an address. */
    if (/^-?\d+$/.test(raw)) {
        return null;
    }

    const resolve = raw.match(/^tg:\/\/resolve\?domain=([A-Za-z0-9_]+)/i);

    if (resolve) {
        return `https://t.me/${resolve[1]}`;
    }

    const join = raw.match(/^tg:\/\/join\?invite=([A-Za-z0-9_-]+)/i);

    if (join) {
        return `https://t.me/+${join[1]}`;
    }

    const bare = raw
        .replace(/^(https?:\/\/)?(www\.)?/i, '')
        .replace(/\/+$/, '');

    /* Invite hashes keep the "+" form; joinchat/ is its older spelling. */
    const invite = bare.match(/^t\.me\/(?:joinchat\/|\+)([A-Za-z0-9_-]+)$/i);

    if (invite) {
        return `https://t.me/+${invite[1]}`;
    }

    /* A public link, optionally pointing at one message. */
    const path = bare.match(/^t\.me\/([A-Za-z0-9_]{4,32}(?:\/\d+)?)$/i);

    if (path) {
        return `https://t.me/${path[1]}`;
    }

    const username = raw.match(/^@?([A-Za-z0-9_]{4,32})$/);

    return username ? `https://t.me/${username[1]}` : null;
}

/**
 * Uzbek numbers arrive as a bare +998XXXXXXXXX string; grouping them makes
 * them scannable in a list without changing the value that gets copied.
 */
/*
 * One look for every phone number, whatever country it is from:
 * +998 99 910 62 35, +90 505 516 88 68, +1 620 220 22 67. Stored numbers are
 * +<digits> (TelegramAccount::normalizePhone); this only groups them.
 *
 * country code -> how the rest is split. A number whose length does not fit
 * its code, or whose code is not listed, is shown as +digits in threes.
 */
const PHONE_FORMATS = {
    998: [2, 3, 2, 2],
    996: [3, 3, 3],
    992: [2, 3, 4],
    993: [2, 6],
    994: [2, 3, 2, 2],
    995: [3, 3, 3],
    380: [2, 3, 2, 2],
    375: [2, 3, 2, 2],
    374: [2, 6],
    373: [2, 3, 3],
    971: [2, 3, 4],
    966: [2, 3, 4],
    90: [3, 3, 2, 2],
    86: [3, 4, 4],
    82: [2, 4, 4],
    81: [2, 4, 4],
    49: [3, 8],
    44: [4, 6],
    33: [1, 2, 2, 2, 2],
    7: [3, 3, 2, 2],
    1: [3, 3, 4],
};

export function formatPhone(value) {
    if (!value) {
        return EM_DASH;
    }

    let digits = String(value).replace(/\D/g, '');

    if (digits.startsWith('00')) {
        digits = digits.slice(2);
    }

    if (digits === '') {
        return String(value);
    }

    for (const length of [3, 2, 1]) {
        const code = digits.slice(0, length);
        const pattern = PHONE_FORMATS[code];

        if (!pattern) {
            continue;
        }

        const rest = digits.slice(length);

        if (rest.length === pattern.reduce((sum, size) => sum + size, 0)) {
            const groups = [];
            let at = 0;

            pattern.forEach((size) => {
                groups.push(rest.slice(at, at + size));
                at += size;
            });

            return `+${code} ${groups.join(' ')}`;
        }
    }

    return `+${digits.replace(/(\d{3})(?=\d)/g, '$1 ')}`;
}

export { EM_DASH };
