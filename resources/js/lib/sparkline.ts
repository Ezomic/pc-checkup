import type { TrendPoint } from '@/types';

export type Sparkline = {
    path: string;
    last: { x: number; y: number } | null;
};

const round = (value: number): number => Number(value.toFixed(2));

export function sparkline(
    points: Pick<TrendPoint, 'at' | 'value'>[],
    width: number,
    height: number,
    padding = 4,
): Sparkline {
    const usable = points
        .map((point) => ({ time: Date.parse(point.at), value: point.value }))
        .filter(
            (point) =>
                Number.isFinite(point.time) && Number.isFinite(point.value),
        );

    if (usable.length === 0) {
        return { path: '', last: null };
    }

    const times = usable.map((point) => point.time);
    const values = usable.map((point) => point.value);
    const firstTime = Math.min(...times);
    const timeRange = Math.max(...times) - firstTime;
    const lowest = Math.min(...values);
    const valueRange = Math.max(...values) - lowest;
    const innerWidth = width - padding * 2;
    const innerHeight = height - padding * 2;

    const coordinates = usable.map((point, index) => {
        let x = width / 2;

        if (usable.length > 1) {
            x =
                padding +
                (Number.isFinite(timeRange) && timeRange > 0
                    ? ((point.time - firstTime) / timeRange) * innerWidth
                    : (index / (usable.length - 1)) * innerWidth);
        }

        const y =
            Number.isFinite(valueRange) && valueRange > 0
                ? padding +
                  (1 - (point.value - lowest) / valueRange) * innerHeight
                : height / 2;

        return { x: round(x), y: round(y) };
    });

    return {
        path: coordinates
            .map(({ x, y }, index) => `${index === 0 ? 'M' : 'L'}${x} ${y}`)
            .join(' '),
        last: coordinates[coordinates.length - 1],
    };
}
