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
    'ba.title': 'Before and after',
    'ba.link': 'Before and after',
    'ba.linkHint': 'See what was fixed between two checks',
    'ba.back': 'Back to {name}',
    'ba.print': 'Print / save as PDF',
    'ba.before': 'Before',
    'ba.after': 'After',
    'ba.fixedOne': '{n} problem fixed',
    'ba.fixedMany': '{n} problems fixed',
    'ba.todo': '{n} still to do',
    'ba.newCount': '{n} new',
    'ba.unchanged': 'Nothing changed between these two checks.',
    'ba.rightsSummary':
        'These checks were run with different access rights, so we cannot say for sure what was fixed.',
    'ba.rightsNote':
        'Because of that, the items below that are no longer reported, or are new, may simply not have been visible in the other check.',
    'ba.fixed': 'Fixed',
    'ba.noLongerReported': 'No longer reported',
    'ba.open': 'Still to do',
    'ba.new': 'New',
    'ba.allClear': 'Nothing left to do.',
    'ba.numbers': 'Numbers',
    'ba.improved': 'Better',
    'ba.worse': 'Worse',
    'ba.number.free_disk': 'Free space',
    'ba.number.memory': 'Memory',
    'ba.number.startup': 'Programs that start with the computer',
    'ba.number.battery_cycles': 'Battery charge cycles',
    'ba.number.battery_condition': 'Battery condition',
    'ba.number.disk_read': 'Disk read speed',
    'ba.number.disk_write': 'Disk write speed',
    'ba.condition.normal': 'Normal',
    'ba.condition.service_recommended': 'Service recommended',
    'ba.condition.replace_soon': 'Replace soon',
    'ba.condition.replace_now': 'Replace now',
    'ba.condition.poor': 'Poor',
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
    'ba.title': 'Voor en na',
    'ba.link': 'Voor en na',
    'ba.linkHint': 'Bekijk wat er tussen twee controles is opgelost',
    'ba.back': 'Terug naar {name}',
    'ba.print': 'Afdrukken / opslaan als pdf',
    'ba.before': 'Voor',
    'ba.after': 'Na',
    'ba.fixedOne': '{n} probleem opgelost',
    'ba.fixedMany': '{n} problemen opgelost',
    'ba.todo': '{n} nog te doen',
    'ba.newCount': '{n} nieuw',
    'ba.unchanged': 'Er is niets veranderd tussen deze twee controles.',
    'ba.rightsSummary':
        'Deze controles zijn met andere toegangsrechten uitgevoerd, dus we kunnen niet zeker zeggen wat er is opgelost.',
    'ba.rightsNote':
        'Daarom kan het zijn dat wat hieronder niet meer wordt gemeld, of nieuw is, bij de andere controle gewoon niet zichtbaar was.',
    'ba.fixed': 'Opgelost',
    'ba.noLongerReported': 'Niet meer gemeld',
    'ba.open': 'Nog te doen',
    'ba.new': 'Nieuw',
    'ba.allClear': 'Er is niets meer te doen.',
    'ba.numbers': 'Cijfers',
    'ba.improved': 'Beter',
    'ba.worse': 'Slechter',
    'ba.number.free_disk': 'Vrije ruimte',
    'ba.number.memory': 'Werkgeheugen',
    'ba.number.startup': "Programma's die met de computer starten",
    'ba.number.battery_cycles': 'Laadcycli van de accu',
    'ba.number.battery_condition': 'Staat van de accu',
    'ba.number.disk_read': 'Leessnelheid van de schijf',
    'ba.number.disk_write': 'Schrijfsnelheid van de schijf',
    'ba.condition.normal': 'Normaal',
    'ba.condition.service_recommended': 'Onderhoud aanbevolen',
    'ba.condition.replace_soon': 'Binnenkort vervangen',
    'ba.condition.replace_now': 'Nu vervangen',
    'ba.condition.poor': 'Slecht',
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
