import type { BenchmarkRating } from '@/types';

const ratingClasses: Record<BenchmarkRating, string> = {
    healthy:
        'border-green-600/40 bg-green-600/15 text-green-700 dark:text-green-400',
    fair: 'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-400',
    poor: 'border-red-600/40 bg-red-600/15 text-red-700 dark:text-red-400',
};

const ratingLabels: Record<BenchmarkRating, string> = {
    healthy: 'Healthy',
    fair: 'Fair',
    poor: 'Poor',
};

export function useBenchmark() {
    function formatValue(value: number, unit: string): string {
        return `${new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(value)} ${unit}`;
    }

    function formatDelta(delta: number | null, unit: string): string {
        if (delta === null) {
            return 'first run';
        }

        if (delta === 0) {
            return 'no change';
        }

        const sign = delta > 0 ? '+' : '-';

        return `${sign}${new Intl.NumberFormat('en', { maximumFractionDigits: 1 }).format(Math.abs(delta))} ${unit}`;
    }

    function deltaClass(improved: boolean | null): string {
        if (improved === null) {
            return 'text-muted-foreground';
        }

        return improved
            ? 'text-green-700 dark:text-green-400'
            : 'text-red-700 dark:text-red-400';
    }

    function rangeHint(
        healthy: number,
        poor: number,
        higherIsBetter: boolean,
        unit: string,
    ): string {
        return higherIsBetter
            ? `Healthy from ${healthy} ${unit}, poor under ${poor} ${unit}`
            : `Healthy up to ${healthy} ${unit}, poor over ${poor} ${unit}`;
    }

    function ratingClass(rating: BenchmarkRating): string {
        return ratingClasses[rating];
    }

    function ratingLabel(rating: BenchmarkRating): string {
        return ratingLabels[rating];
    }

    return {
        formatValue,
        formatDelta,
        deltaClass,
        rangeHint,
        ratingClass,
        ratingLabel,
    };
}
