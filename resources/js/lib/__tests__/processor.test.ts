import { describe, expect, it } from 'vitest';
import { processor } from '@/lib/processor';

describe('processor', () => {
    it('drops the trademark marks and keeps the cores and threads apart', () => {
        expect(
            processor('Intel(R) Core(TM) i7-14700K (20 cores, 28 threads)'),
        ).toEqual({
            name: 'Intel Core i7-14700K',
            detail: '20 cores, 28 threads',
        });
    });

    it('drops the word CPU and the clock speed', () => {
        expect(processor('Intel(R) Core(TM) i5-5350U CPU @ 1.80GHz')).toEqual({
            name: 'Intel Core i5-5350U',
            detail: null,
        });
        expect(
            processor(
                'AMD Ryzen 7 5800X 8-Core Processor (8 cores, 16 threads)',
            ),
        ).toEqual({
            name: 'AMD Ryzen 7 5800X 8-Core Processor',
            detail: '8 cores, 16 threads',
        });
    });

    it('leaves a Mac chip name as it is', () => {
        expect(processor('Apple M2 Pro')).toEqual({
            name: 'Apple M2 Pro',
            detail: null,
        });
        expect(processor('Intel Core i5')).toEqual({
            name: 'Intel Core i5',
            detail: null,
        });
    });

    it('handles other trademark spellings and extra spaces', () => {
        expect(processor('  Intel®  Core™ i9-9900K  CPU @ 3,60GHz ')).toEqual({
            name: 'Intel Core i9-9900K',
            detail: null,
        });
        expect(processor('Intel(r) Pentium(tm) Gold G6400')).toEqual({
            name: 'Intel Pentium Gold G6400',
            detail: null,
        });
    });

    it('gives nothing for a missing or empty value', () => {
        expect(processor(null)).toBeNull();
        expect(processor('   ')).toBeNull();
        expect(processor('(R) (TM)')).toBeNull();
    });
});
