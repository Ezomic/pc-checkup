export function newlyCheckedLine(headlines: string[]): string | null {
    if (headlines.length === 0) {
        return null;
    }

    const why =
        headlines.length === 1
            ? 'The earlier scan did not check for this, so it is not counted as new.'
            : 'The earlier scan did not check for these, so they are not counted as new.';

    return `Newly checked: ${headlines.join('; ')}. ${why}`;
}
