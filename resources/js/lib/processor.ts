export type Processor = { name: string; detail: string | null };

const MARKS = /\((?:r|tm)\)|[®™]/gi;
const SPEED = /\s+(?:CPU\s+)?@\s*[\d.,]+\s*GHz/i;
const TRAILING_CPU = /\s+CPU$/i;
const COUNTS = /\s*\((\d+ cores?(?:, \d+ threads?)?)\)\s*$/i;

export function processor(raw: string | null): Processor | null {
    if (raw === null) {
        return null;
    }

    const counts = raw.match(COUNTS);
    const name = raw
        .replace(COUNTS, '')
        .replace(MARKS, '')
        .replace(SPEED, '')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(TRAILING_CPU, '');

    return name === '' ? null : { name, detail: counts ? counts[1] : null };
}
