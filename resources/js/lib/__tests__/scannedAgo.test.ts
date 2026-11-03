import { describe, expect, it } from 'vitest';
import { checkedOn, scannedAgo } from '@/lib/scannedAgo';

const now = new Date('2026-11-02T12:00:00Z');

describe('scannedAgo', () => {
    it.each([
        ['2026-11-02 11:59:30', 'just now'],
        ['2026-11-02 11:15:00', '45 min ago'],
        ['2026-11-02 09:00:00', '3 h ago'],
        ['2026-11-01 09:00:00', 'yesterday'],
        ['2026-10-28 12:00:00', '5 days ago'],
    ])('reads the database time %s as UTC', (value, expected) => {
        expect(scannedAgo(value, now)).toBe(expected);
    });

    it('accepts an ISO string with a timezone', () => {
        expect(scannedAgo('2026-11-02T10:00:00.000000Z', now)).toBe('2 h ago');
        expect(scannedAgo('2026-11-02T13:00:00+02:00', now)).toBe('1 h ago');
    });

    it('never goes negative for a time in the future', () => {
        expect(scannedAgo('2026-11-02 13:00:00', now)).toBe('just now');
    });

    it('defaults to the current time', () => {
        expect(scannedAgo(new Date().toISOString())).toBe('just now');
    });
});

describe('checkedOn', () => {
    it('writes the full date', () => {
        expect(checkedOn('2026-11-03 12:00:00')).toBe('3 November 2026');
    });
});
