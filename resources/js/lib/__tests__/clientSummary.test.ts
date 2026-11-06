import { describe, expect, it } from 'vitest';
import { summaryLine } from '@/lib/clientSummary';

describe('summaryLine', () => {
    it('lists every level that has machines', () => {
        expect(summaryLine({ total: 3, urgent: 1, soon: 1, fine: 1 })).toBe(
            '3 machines: 1 urgent, 1 soon, 1 fine',
        );
    });

    it('leaves out levels without machines', () => {
        expect(summaryLine({ total: 2, urgent: 0, soon: 0, fine: 2 })).toBe(
            '2 machines: 2 fine',
        );
    });

    it('uses the singular for one machine', () => {
        expect(summaryLine({ total: 1, urgent: 1, soon: 0, fine: 0 })).toBe(
            '1 machine: 1 urgent',
        );
    });

    it('says so when the client has no machines', () => {
        expect(summaryLine({ total: 0, urgent: 0, soon: 0, fine: 0 })).toBe(
            'No machines yet',
        );
    });
});
