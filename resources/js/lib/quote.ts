import type { Estimate, PriceItem } from '@/types';

type Concept = {
    reason: RegExp | null;
    replaceOnly: boolean;
    name: RegExp;
};

const CONCEPTS: Concept[] = [
    {
        reason: /spinning hard drive|solid state/i,
        replaceOnly: false,
        name: /\bssd\b|solid.?state|harde schijf|hard drive|\bhdd\b|schijf/i,
    },
    {
        reason: /memory/i,
        replaceOnly: false,
        name: /\bram\b|memory|geheugen/i,
    },
    {
        reason: /battery/i,
        replaceOnly: false,
        name: /batter|\baccu/i,
    },
    {
        reason: /drive is free|clear out/i,
        replaceOnly: false,
        name: /clean|schoon|opschon/i,
    },
    {
        reason: null,
        replaceOnly: true,
        name: /install|set-?up|inrichten/i,
    },
    {
        reason: null,
        replaceOnly: true,
        name: /migrat|overzet|data.?transfer/i,
    },
];

const numbers = new Intl.NumberFormat('nl-NL', { useGrouping: true });

export function formatEuro(cents: number): string {
    const whole = Math.trunc(cents / 100);
    const rest = String(cents % 100).padStart(2, '0');

    return `${numbers.format(whole)},${rest}`;
}

export function editableEuro(cents: number): string {
    return `${Math.trunc(cents / 100)},${String(cents % 100).padStart(2, '0')}`;
}

export function vatCents(excl: number, percent: number): number {
    return Math.floor((excl * percent + 50) / 100);
}

export function quoteTotals(
    items: PriceItem[],
    percent: number,
): { excl: number; vat: number; incl: number } {
    const excl = items.reduce((sum, entry) => sum + entry.price_cents, 0);
    const vat = vatCents(excl, percent);

    return { excl, vat, incl: excl + vat };
}

export function suggestedIds(
    items: PriceItem[],
    estimate: Estimate | null,
): number[] {
    if (estimate === null) {
        return [];
    }

    const reasons = estimate.reasons.join(' ');
    const replace = estimate.verdict === 'Replace';
    const applicable = CONCEPTS.filter((concept) =>
        concept.reason === null
            ? replace
            : !concept.replaceOnly && concept.reason.test(reasons),
    );

    return items
        .filter((entry) =>
            applicable.some((concept) => concept.name.test(entry.name)),
        )
        .map((entry) => entry.id);
}

export function buildQuote(
    machine: string,
    chosen: PriceItem[],
    includeVat: boolean,
    percent: number,
): string {
    const { excl, vat, incl } = quoteTotals(chosen, percent);
    const lines = chosen.map(
        (entry) =>
            `- ${entry.name}${entry.unit === null ? '' : ` (${entry.unit})`}: EUR ${formatEuro(entry.price_cents)}`,
    );

    return [
        `Quote for ${machine.replace(/\s+/g, ' ').trim()}:`,
        ...lines,
        ...(includeVat
            ? [
                  `Subtotal excl. VAT: EUR ${formatEuro(excl)}`,
                  `VAT ${percent}%: EUR ${formatEuro(vat)}`,
                  `Total: EUR ${formatEuro(incl)} incl. VAT`,
              ]
            : [`Total: EUR ${formatEuro(excl)} excl. VAT`]),
    ].join('\n');
}
