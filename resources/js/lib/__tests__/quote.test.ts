import { describe, expect, it } from 'vitest';
import {
    buildQuote,
    editableEuro,
    formatEuro,
    quoteTotals,
    suggestedIds,
    vatCents,
} from '@/lib/quote';
import type { Estimate, PriceItem } from '@/types';

function item(id: number, name: string, price_cents = 1000): PriceItem {
    return { id, name, price_cents, unit: null };
}

describe('formatEuro', () => {
    it('formats cents the Dutch way without floats', () => {
        expect(formatEuro(0)).toBe('0,00');
        expect(formatEuro(1)).toBe('0,01');
        expect(formatEuro(12300)).toBe('123,00');
        expect(formatEuro(123456)).toBe('1.234,56');
        expect(formatEuro(99_999_999)).toBe('999.999,99');
        expect(formatEuro(1999)).toBe('19,99');
    });
});

describe('editableEuro', () => {
    it('gives the text a person would type back in', () => {
        expect(editableEuro(123456)).toBe('1234,56');
        expect(editableEuro(5)).toBe('0,05');
    });
});

describe('vatCents', () => {
    it('rounds half up in whole cents', () => {
        expect(vatCents(10000, 21)).toBe(2100);
        expect(vatCents(1, 21)).toBe(0);
        expect(vatCents(3, 21)).toBe(1);
        expect(vatCents(2381, 21)).toBe(500);
        expect(vatCents(0, 21)).toBe(0);
    });
});

describe('quoteTotals', () => {
    it('adds the ticked items and the vat', () => {
        const items = [item(1, 'A', 1999), item(2, 'B', 1)];

        expect(quoteTotals(items, 21)).toEqual({
            excl: 2000,
            vat: 420,
            incl: 2420,
        });
        expect(quoteTotals([], 21)).toEqual({ excl: 0, vat: 0, incl: 0 });
    });

    it('stays exact over many large items', () => {
        const items = Array.from({ length: 500 }, (_, i) =>
            item(i, `Item ${i}`, 99_999_999),
        );

        expect(quoteTotals(items, 21).excl).toBe(49_999_999_500);
    });
});

describe('suggestedIds', () => {
    const repair = (...reasons: string[]): Estimate => ({
        verdict: 'Repair',
        reasons,
    });

    it('matches names against the reasons', () => {
        const items = [
            item(1, 'New SSD 500 GB'),
            item(2, 'RAM upgrade'),
            item(3, 'Battery replacement'),
            item(4, 'Cleaning and tune-up'),
            item(5, 'Travel costs'),
        ];

        expect(
            suggestedIds(
                items,
                repair('It still has a spinning hard drive Swapping in SSD'),
            ),
        ).toEqual([1]);
        expect(
            suggestedIds(items, repair('Only 4 GB of memory Add memory')),
        ).toEqual([2]);
        expect(
            suggestedIds(items, repair('The battery is worn out A new one')),
        ).toEqual([3]);
        expect(
            suggestedIds(
                items,
                repair('Only 3% of the drive is free Clear out big files'),
            ),
        ).toEqual([4]);
    });

    it('knows the Dutch words the owner is likely to use', () => {
        const items = [
            item(1, 'Nieuwe accu'),
            item(2, 'Geheugen 8 GB'),
            item(3, 'Opschonen'),
            item(4, 'Extra SSD-schijf'),
        ];

        expect(
            suggestedIds(
                items,
                repair('The battery is worn out', 'Only 4 GB of memory'),
            ),
        ).toEqual([1, 2]);
        expect(
            suggestedIds(items, repair('Only 3% of the drive is free')),
        ).toEqual([3]);
        expect(
            suggestedIds(items, repair('It still has a spinning hard drive')),
        ).toEqual([4]);
    });

    it('suggests install and migration items for a replace verdict only', () => {
        const items = [
            item(1, 'Windows installation'),
            item(2, 'Data migration'),
            item(3, 'Battery'),
        ];

        expect(
            suggestedIds(items, { verdict: 'Replace', reasons: [] }),
        ).toEqual([1, 2]);
        expect(suggestedIds(items, repair())).toEqual([]);
    });

    it('does not match words inside other words', () => {
        const items = [item(1, 'Program setup help'), item(2, 'Ramen lunch')];

        expect(
            suggestedIds(items, repair('Only 4 GB of memory Add memory')),
        ).toEqual([]);
    });

    it('suggests nothing without items, reasons or a verdict', () => {
        expect(suggestedIds([], repair('The battery is worn out'))).toEqual([]);
        expect(suggestedIds([item(1, 'Battery')], repair())).toEqual([]);
        expect(suggestedIds([item(1, 'Battery')], null)).toEqual([]);
    });

    it('lists each item once even when two reasons match it', () => {
        expect(
            suggestedIds(
                [item(1, 'Battery and RAM service')],
                repair('The battery is worn out', 'Only 4 GB of memory'),
            ),
        ).toEqual([1]);
    });
});

describe('buildQuote', () => {
    const items: PriceItem[] = [
        { id: 1, name: 'New battery', price_cents: 8900, unit: null },
        { id: 2, name: 'Cleaning', price_cents: 4500, unit: 'per hour' },
    ];

    it('writes the quote with the total excluding vat', () => {
        expect(buildQuote('MacBook Air', items, false, 21)).toBe(
            [
                'Quote for MacBook Air:',
                '- New battery: EUR 89,00',
                '- Cleaning (per hour): EUR 45,00',
                'Total: EUR 134,00 excl. VAT',
            ].join('\n'),
        );
    });

    it('shows the vat and the total including vat', () => {
        expect(buildQuote('MacBook Air', items, true, 21)).toBe(
            [
                'Quote for MacBook Air:',
                '- New battery: EUR 89,00',
                '- Cleaning (per hour): EUR 45,00',
                'Subtotal excl. VAT: EUR 134,00',
                'VAT 21%: EUR 28,14',
                'Total: EUR 162,14 incl. VAT',
            ].join('\n'),
        );
    });

    it('keeps the machine name on one line', () => {
        expect(buildQuote('  Evil\nName\t\tX ', items, false, 21)).toContain(
            'Quote for Evil Name X:',
        );
    });

    it('keeps markup as plain text', () => {
        const xss = [item(1, '<img src=x onerror=alert(1)>', 100)];

        expect(buildQuote('<b>m</b>', xss, false, 21)).toContain(
            '- <img src=x onerror=alert(1)>: EUR 1,00',
        );
    });
});
