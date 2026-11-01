import { describe, expect, it } from 'vitest';
import { describeAbilities } from '@/lib/abilities';

describe('describeAbilities', () => {
    it('names a single ability', () => {
        expect(describeAbilities(['read'])).toBe('Read');
        expect(describeAbilities(['write'])).toBe('Write');
    });

    it('joins both in a fixed order, whatever order they were stored in', () => {
        expect(describeAbilities(['write', 'read'])).toBe('Read and Write');
    });

    it('reads the wildcard of a token from before abilities existed as full access', () => {
        expect(describeAbilities(['*'])).toBe('Full access (read and write)');
    });

    it('says so when a token holds nothing it recognises', () => {
        expect(describeAbilities([])).toBe('No access');
        expect(describeAbilities(['admin'])).toBe('No access');
    });
});
