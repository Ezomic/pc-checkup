import { describe, expect, it } from 'vitest';
import { useBenchmark } from '@/composables/useBenchmark';

describe('useBenchmark', () => {
    const benchmark = useBenchmark();

    it('formats a value with its unit and thousands separators', () => {
        expect(benchmark.formatValue(2100, 'MB/s')).toBe('2,100 MB/s');
        expect(benchmark.formatValue(12.34, 'ms')).toBe('12.3 ms');
    });

    it('formats a delta with a sign', () => {
        expect(benchmark.formatDelta(150, 'MB/s')).toBe('+150 MB/s');
        expect(benchmark.formatDelta(-40, 'ms')).toBe('-40 ms');
    });

    it('says so when there is nothing to compare or nothing changed', () => {
        expect(benchmark.formatDelta(null, 'MB/s')).toBe('first run');
        expect(benchmark.formatDelta(0, 'MB/s')).toBe('no change');
    });

    it('colours a delta by whether it improved', () => {
        expect(benchmark.deltaClass(true)).toContain('green');
        expect(benchmark.deltaClass(false)).toContain('red');
        expect(benchmark.deltaClass(null)).toContain('muted');
    });

    it('describes the range in the direction that matters', () => {
        expect(benchmark.rangeHint(500, 100, true, 'MB/s')).toBe(
            'Healthy from 500 MB/s, poor under 100 MB/s',
        );
        expect(benchmark.rangeHint(800, 2000, false, 'ms')).toBe(
            'Healthy up to 800 ms, poor over 2000 ms',
        );
    });

    it('maps a rating to a label and a colour', () => {
        expect(benchmark.ratingLabel('healthy')).toBe('Healthy');
        expect(benchmark.ratingLabel('fair')).toBe('Fair');
        expect(benchmark.ratingClass('poor')).toContain('red');
    });
});
