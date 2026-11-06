import { translate } from '@/lib/messages';
import type { Locale } from '@/lib/messages';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function parseUtc(value: string): Date {
    return new Date(
        /[zZ]|[+-]\d\d:?\d\d$/.test(value)
            ? value
            : `${value.replace(' ', 'T')}Z`,
    );
}

export function scannedAgo(
    value: string,
    now: Date = new Date(),
    locale: Locale = 'en',
): string {
    const elapsed = Math.max(0, now.getTime() - parseUtc(value).getTime());

    if (elapsed < MINUTE) {
        return translate(locale, 'ago.justNow');
    }

    if (elapsed < HOUR) {
        return translate(locale, 'ago.minutes', {
            n: Math.floor(elapsed / MINUTE),
        });
    }

    if (elapsed < DAY) {
        return translate(locale, 'ago.hours', {
            n: Math.floor(elapsed / HOUR),
        });
    }

    const days = Math.floor(elapsed / DAY);

    return days === 1
        ? translate(locale, 'ago.yesterday')
        : translate(locale, 'ago.days', { n: days });
}

export function shortDate(value: string): string {
    return parseUtc(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

export function checkedOn(value: string): string {
    return parseUtc(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}
