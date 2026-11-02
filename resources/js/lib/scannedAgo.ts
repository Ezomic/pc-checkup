const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

function parseUtc(value: string): Date {
    return new Date(
        /[zZ]|[+-]\d\d:?\d\d$/.test(value)
            ? value
            : `${value.replace(' ', 'T')}Z`,
    );
}

export function scannedAgo(value: string, now: Date = new Date()): string {
    const elapsed = Math.max(0, now.getTime() - parseUtc(value).getTime());

    if (elapsed < MINUTE) {
        return 'just now';
    }

    if (elapsed < HOUR) {
        return `${Math.floor(elapsed / MINUTE)} min ago`;
    }

    if (elapsed < DAY) {
        return `${Math.floor(elapsed / HOUR)} h ago`;
    }

    const days = Math.floor(elapsed / DAY);

    return days === 1 ? 'yesterday' : `${days} days ago`;
}
