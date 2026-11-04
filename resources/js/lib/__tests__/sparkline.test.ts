import { describe, expect, it } from 'vitest';
import { sparkline } from '@/lib/sparkline';

const day = (n: number) => new Date(Date.UTC(2026, 4, 1 + n, 10)).toISOString();

describe('sparkline', () => {
    it('draws a falling series from top left to bottom right', () => {
        const { path, last } = sparkline(
            [
                { at: day(0), value: 100 },
                { at: day(30), value: 50 },
                { at: day(60), value: 0 },
            ],
            100,
            20,
            0,
        );

        expect(path).toBe('M0 0 L50 10 L100 20');
        expect(last).toEqual({ x: 100, y: 20 });
    });

    it('spaces points by time, not by count', () => {
        const { path } = sparkline(
            [
                { at: day(0), value: 0 },
                { at: day(10), value: 10 },
                { at: day(40), value: 20 },
            ],
            100,
            20,
            0,
        );

        expect(path).toBe('M0 20 L25 10 L100 0');
    });

    it('draws a flat series as a straight line in the middle', () => {
        const { path } = sparkline(
            [
                { at: day(0), value: 7 },
                { at: day(10), value: 7 },
                { at: day(20), value: 7 },
            ],
            100,
            20,
            0,
        );

        expect(path).toBe('M0 10 L50 10 L100 10');
    });

    it('spreads points evenly when they share one moment', () => {
        const { path } = sparkline(
            [
                { at: day(0), value: 1 },
                { at: day(0), value: 2 },
                { at: day(0), value: 3 },
            ],
            100,
            20,
            0,
        );

        expect(path).toBe('M0 20 L50 10 L100 0');
    });

    it('gives one point a valid path in the middle', () => {
        const { path, last } = sparkline(
            [{ at: day(0), value: 5 }],
            100,
            20,
            0,
        );

        expect(path).toBe('M50 10');
        expect(last).toEqual({ x: 50, y: 10 });
    });

    it('gives nothing to draw for no usable points', () => {
        expect(sparkline([], 100, 20)).toEqual({ path: '', last: null });
        expect(
            sparkline(
                [
                    { at: day(0), value: Number.NaN },
                    { at: 'not a date', value: 3 },
                    { at: day(1), value: Number.POSITIVE_INFINITY },
                ],
                100,
                20,
            ),
        ).toEqual({ path: '', last: null });
    });

    it('keeps the padding clear of the edges', () => {
        const { path } = sparkline(
            [
                { at: day(0), value: 0 },
                { at: day(10), value: 10 },
            ],
            100,
            20,
            4,
        );

        expect(path).toBe('M4 16 L96 4');
    });

    it('never writes NaN or Infinity into a path', () => {
        const { path } = sparkline(
            [
                { at: day(0), value: 1e308 },
                { at: day(1), value: -1e308 },
                { at: day(2), value: 0 },
            ],
            100,
            20,
        );

        expect(path).not.toMatch(/NaN|Infinity|undefined/);
    });
});
