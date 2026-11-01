export const abilityOptions = [
    { value: 'read', label: 'Read', hint: 'List and view machines' },
    {
        value: 'write',
        label: 'Write',
        hint: 'Import reports, set status, record checklist results, add notes',
    },
] as const;

export function describeAbilities(abilities: string[]): string {
    if (abilities.includes('*')) {
        return 'Full access (read and write)';
    }

    const labels = abilityOptions
        .filter((option) => abilities.includes(option.value))
        .map((option) => option.label);

    return labels.length > 0 ? labels.join(' and ') : 'No access';
}
