import { describe, expect, it } from 'vitest';
import {
    LOCALES,
    localiseNumber,
    messages,
    normaliseLocale,
    translate,
} from '@/lib/messages';

function placeholders(text: string): string[] {
    return [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]).sort();
}

describe('messages', () => {
    it('has the same keys and placeholders in every language', () => {
        const english = Object.keys(messages.en).sort();

        for (const locale of LOCALES) {
            expect(Object.keys(messages[locale]).sort()).toEqual(english);
        }

        for (const key of Object.keys(
            messages.en,
        ) as (keyof typeof messages.en)[]) {
            expect(placeholders(messages.nl[key])).toEqual(
                placeholders(messages.en[key]),
            );
        }
    });

    it('never leaves a Dutch text empty or copied from English, bar the words that are the same', () => {
        const same = Object.keys(messages.en).filter(
            (key) =>
                messages.nl[key as keyof typeof messages.en] ===
                messages.en[key as keyof typeof messages.en],
        );

        expect(same.sort()).toEqual([
            'deviceType.desktop',
            'deviceType.laptop',
            'share.processor',
            'switch.en',
            'switch.nl',
        ]);
    });

    it('uses no em-dash', () => {
        for (const locale of LOCALES) {
            expect(Object.values(messages[locale]).join(' ')).not.toContain(
                '—',
            );
        }
    });

    it('fills placeholders', () => {
        expect(translate('nl', 'share.moreIssues', { n: 3 })).toBe(
            '+3 om naar te kijken',
        );
        expect(translate('en', 'share.moreIssues', { n: 3 })).toBe(
            '+3 more to look at',
        );
        expect(translate('en', 'switch.label')).toBe('Language');
    });

    it('accepts only the offered locales', () => {
        expect(normaliseLocale('nl')).toBe('nl');
        expect(normaliseLocale('en')).toBe('en');
        expect(normaliseLocale('fr')).toBe('en');
        expect(normaliseLocale(undefined)).toBe('en');
        expect(normaliseLocale(['nl'])).toBe('en');
    });

    it('writes decimals with a comma in Dutch only', () => {
        expect(localiseNumber('16.5 GB (45.25%)', 'nl')).toBe(
            '16,5 GB (45,25%)',
        );
        expect(localiseNumber('16.5 GB', 'en')).toBe('16.5 GB');
        expect(localiseNumber('Core i5 3.1', 'nl')).toBe('Core i5 3,1');
        expect(localiseNumber('32 GB', 'nl')).toBe('32 GB');
    });
});
