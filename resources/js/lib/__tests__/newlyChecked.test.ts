import { describe, expect, it } from 'vitest';
import { newlyCheckedLine } from '@/lib/newlyChecked';

describe('newlyCheckedLine', () => {
    it('says nothing when every check was in both scans', () => {
        expect(newlyCheckedLine([])).toBeNull();
    });

    it('names one newly checked issue and why it is not new', () => {
        expect(newlyCheckedLine(['No backup was found'])).toBe(
            'Newly checked: No backup was found. The earlier scan did not check for this, so it is not counted as new.',
        );
    });

    it('keeps headlines with commas apart', () => {
        expect(
            newlyCheckedLine([
                'Drive D: is almost full, only 7% left',
                'No backup was found',
            ]),
        ).toBe(
            'Newly checked: Drive D: is almost full, only 7% left; No backup was found. The earlier scan did not check for these, so they are not counted as new.',
        );
    });
});
