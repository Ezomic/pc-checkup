import { describe, expect, it } from 'vitest';
import { useInitials } from '@/composables/useInitials';

describe('useInitials', () => {
    const { getInitials } = useInitials();

    it('returns an empty string when there is no name', () => {
        expect(getInitials()).toBe('');
        expect(getInitials('')).toBe('');
        expect(getInitials('   ')).toBe('');
    });

    it('uses the single initial of a mononym', () => {
        expect(getInitials('Prince')).toBe('P');
    });

    it('combines the first and last initial, skipping middle names', () => {
        expect(getInitials('Robbin Thijssen')).toBe('RT');
        expect(getInitials('Ada Byron King Lovelace')).toBe('AL');
    });

    it('collapses irregular whitespace', () => {
        expect(getInitials('  Grace   Hopper  ')).toBe('GH');
    });

    it('handles multi-byte names one character at a time', () => {
        expect(getInitials('Ólafur Árnalds')).toBe('ÓÁ');
        expect(getInitials('张 伟')).toBe('张伟');
    });
});
