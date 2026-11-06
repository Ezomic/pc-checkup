export const LOCALES = ['nl', 'en'] as const;

export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = 'en';

const en = {
    'status.ok': 'Looking good',
    'status.needs_work': 'Needs work',
    'status.reject': 'Reject',
    'deviceType.laptop': 'laptop',
    'deviceType.desktop': 'desktop',
    'ago.justNow': 'just now',
    'ago.minutes': '{n} min ago',
    'ago.hours': '{n} h ago',
    'ago.yesterday': 'yesterday',
    'ago.days': '{n} days ago',
    'advice.more': '{n} more to look at',
    'share.unnamedMachine': 'Unnamed machine',
    'share.notChecked': 'Not checked yet',
    'share.notCheckedDot': 'Not checked yet.',
    'share.nothingFlagged': 'Nothing flagged in the latest check',
    'share.nothingFlaggedDot': 'Nothing flagged in the latest check.',
    'share.intro': 'The latest check of each computer and its biggest problem.',
    'share.noComputers': 'No computers to show yet.',
    'share.moreIssues': '+{n} more to look at',
    'share.lastChecked': 'Last checked {when}',
    'share.fixedOne': '{n} thing fixed since the last check',
    'share.fixedMany': '{n} things fixed since the last check',
    'share.freeSpace': 'Free space',
    'share.memory': 'Memory',
    'share.processor': 'Processor',
    'share.whatFirst': 'What to do first',
    'share.unavailableTitle': 'Link not available',
    'share.missingHeading': 'This link is not available',
    'share.missingBody':
        'It may have been replaced or switched off. Ask whoever sent it to you for a new one.',
    'share.errorHeading': 'Something went wrong',
    'share.errorBody': 'Please try again in a moment.',
    'switch.label': 'Language',
    'switch.nl': 'Nederlands',
    'switch.en': 'English',
} as const;

export type MessageKey = keyof typeof en;

type Messages = Record<MessageKey, string>;

const nl: Messages = {
    'status.ok': 'Ziet er goed uit',
    'status.needs_work': 'Aandacht nodig',
    'status.reject': 'Afgekeurd',
    'deviceType.laptop': 'laptop',
    'deviceType.desktop': 'desktop',
    'ago.justNow': 'zojuist',
    'ago.minutes': '{n} min geleden',
    'ago.hours': '{n} uur geleden',
    'ago.yesterday': 'gisteren',
    'ago.days': '{n} dagen geleden',
    'advice.more': 'Nog {n} om naar te kijken',
    'share.unnamedMachine': 'Computer zonder naam',
    'share.notChecked': 'Nog niet gecontroleerd',
    'share.notCheckedDot': 'Nog niet gecontroleerd.',
    'share.nothingFlagged': 'Bij de laatste controle viel niets op',
    'share.nothingFlaggedDot': 'Bij de laatste controle viel niets op.',
    'share.intro':
        'De laatste controle van elke computer en het grootste probleem.',
    'share.noComputers': 'Er zijn nog geen computers om te laten zien.',
    'share.moreIssues': '+{n} om naar te kijken',
    'share.lastChecked': 'Laatst gecontroleerd: {when}',
    'share.fixedOne': '{n} ding opgelost sinds de vorige controle',
    'share.fixedMany': '{n} dingen opgelost sinds de vorige controle',
    'share.freeSpace': 'Vrije ruimte',
    'share.memory': 'Werkgeheugen',
    'share.processor': 'Processor',
    'share.whatFirst': 'Wat eerst te doen',
    'share.unavailableTitle': 'Link niet beschikbaar',
    'share.missingHeading': 'Deze link is niet beschikbaar',
    'share.missingBody':
        'Hij is misschien vervangen of uitgezet. Vraag degene die hem je stuurde om een nieuwe.',
    'share.errorHeading': 'Er ging iets mis',
    'share.errorBody': 'Probeer het zo meteen opnieuw.',
    'switch.label': 'Taal',
    'switch.nl': 'Nederlands',
    'switch.en': 'English',
};

export const messages: Record<Locale, Messages> = { en, nl };

export function normaliseLocale(value: unknown): Locale {
    return LOCALES.find((locale) => locale === value) ?? DEFAULT_LOCALE;
}

export function translate(
    locale: Locale,
    key: MessageKey,
    params: Record<string, string | number> = {},
): string {
    return Object.entries(params).reduce(
        (text, [name, value]) => text.replaceAll(`{${name}}`, String(value)),
        messages[locale][key],
    );
}

export function localiseNumber(value: string, locale: Locale): string {
    return locale === 'nl' ? value.replace(/(\d)\.(\d)/g, '$1,$2') : value;
}
