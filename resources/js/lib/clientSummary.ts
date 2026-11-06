import type { ClientMachineSummary } from '@/types';

export function summaryLine(summary: ClientMachineSummary): string {
    if (summary.total === 0) {
        return 'No machines yet';
    }

    const parts = (['urgent', 'soon', 'fine'] as const)
        .filter((level) => summary[level] > 0)
        .map((level) => `${summary[level]} ${level}`);

    const noun = summary.total === 1 ? 'machine' : 'machines';

    return `${summary.total} ${noun}: ${parts.join(', ')}`;
}
