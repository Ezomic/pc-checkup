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
    'privacy.link': 'What the scan reads and does not read',
    'privacy.title': 'What this scan reads and does not read',
    'privacy.readsHeading': 'What it reads',
    'privacy.reads':
        'Hardware details, the battery, disk usage and the names of the folders in your user folder, the names of installed apps, startup items and security settings. It also reads how strong the Wi-Fi connection in use is and how long the computer takes to start, and the names of apps that crashed recently (names only). It reads when updates were last installed and, on Windows, whether a restart is waiting and the names of devices that report a problem in Device Manager (no hardware ids or serial numbers). For the Wi-Fi in use only the strength and band are read. It looks for a fixed list of known remote-access tools (such as TeamViewer and AnyDesk) and records only the names of those it finds. It records how many extra entries the hosts file has and whether it sends well-known sites such as banks elsewhere, whether a proxy is set (only its address), which DNS servers are used and, on Windows, which antivirus programs are active. On a Mac it counts how many certificates have custom trust settings (only the number).',
    'privacy.notReadHeading': 'What it does not read',
    'privacy.notRead':
        'File contents, passwords (Wi-Fi passwords included), browser history, emails or photos. It records nothing about nearby or saved Wi-Fi networks, VPN names, the names of certificates or which apps hold privacy permissions, and no line of the hosts file, only the summary above. Your account name on the computer is replaced by a placeholder before the report is stored.',
    'privacy.changesHeading': 'What it changes',
    'privacy.changes':
        'Nothing. It runs once, only when someone starts it. It installs nothing, deletes nothing and leaves nothing running afterwards.',
    'privacy.keptHeading': 'What happens to the report',
    'privacy.kept':
        'The report is only sent when the person running the scan enters a scan code or token. It is kept only to give advice about this computer, and only the owner and the client it belongs to can see it.',
    'privacy.deleteHeading': 'Deleting it',
    'privacy.delete':
        'On request the computer and all of its reports are deleted permanently.',
    'privacy.ownerHeading': 'Who is responsible',
    'privacy.owner':
        '{name} runs this service. Questions or a deletion request:',
    'privacy.scriptHeading': 'See for yourself',
    'privacy.script':
        'The scan is a plain text script that you can read before you run it.',
    'privacy.scriptWindows': 'Windows script',
    'privacy.scriptMac': 'Mac script',
    'privacy.scriptVersion': 'version {version}',
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
    'privacy.link': 'Wat de scan wel en niet leest',
    'privacy.title': 'Wat deze scan wel en niet leest',
    'privacy.readsHeading': 'Wat hij leest',
    'privacy.reads':
        "Hardware, de accu, schijfgebruik en de namen van de mappen in je gebruikersmap, de namen van geïnstalleerde apps, opstartonderdelen en beveiligingsinstellingen. Ook leest hij hoe sterk de wifi-verbinding is die je nu gebruikt, hoe lang de computer erover doet om op te starten en de namen van apps die onlangs zijn gecrasht (alleen namen). Hij leest wanneer er voor het laatst updates zijn geïnstalleerd en op Windows ook of er een herstart klaarstaat en de namen van apparaten die in Apparaatbeheer een probleem melden (geen hardware-id's of serienummers). Van de wifi die je gebruikt worden alleen de sterkte en de frequentieband gelezen. Hij zoekt naar een vaste lijst bekende programma's voor hulp op afstand (zoals TeamViewer en AnyDesk) en noteert alleen de namen van de programma's die hij vindt. Hij noteert hoeveel extra regels het hosts-bestand heeft en of het bekende sites zoals banken ergens anders heen stuurt, of er een proxy is ingesteld (alleen het adres), welke DNS-servers worden gebruikt en, op Windows, welke antivirusprogramma's actief zijn. Op een Mac telt hij hoeveel certificaten een aangepaste vertrouwensinstelling hebben (alleen het aantal).",
    'privacy.notReadHeading': 'Wat hij niet leest',
    'privacy.notRead':
        "Bestandsinhoud, wachtwoorden (ook wifi-wachtwoorden), browsergeschiedenis, e-mail of foto's. Hij legt niets vast over wifi-netwerken in de buurt of opgeslagen wifi-netwerken, namen van vpn-verbindingen, de namen van certificaten of welke apps privacytoestemmingen hebben, en geen enkele regel van het hosts-bestand, alleen de samenvatting hierboven. Je accountnaam op de computer wordt vervangen door een plaatsvervanger voordat het rapport wordt opgeslagen.",
    'privacy.changesHeading': 'Wat hij verandert',
    'privacy.changes':
        'Niets. Hij draait één keer, alleen als iemand hem start. Hij installeert niets, verwijdert niets en laat niets draaien.',
    'privacy.keptHeading': 'Wat er met het rapport gebeurt',
    'privacy.kept':
        'Het rapport wordt alleen verstuurd als degene die de scan draait een scancode of token invoert. Het wordt alleen bewaard om advies over deze computer te geven, en alleen de eigenaar en de klant van wie de computer is kunnen het zien.',
    'privacy.deleteHeading': 'Laten verwijderen',
    'privacy.delete':
        'Op verzoek worden de computer en al zijn rapporten definitief verwijderd.',
    'privacy.ownerHeading': 'Wie er verantwoordelijk is',
    'privacy.owner':
        '{name} beheert deze dienst. Vragen of een verzoek tot verwijdering:',
    'privacy.scriptHeading': 'Kijk zelf mee',
    'privacy.script':
        'De scan is een script in gewone tekst dat je kunt lezen voordat je het draait.',
    'privacy.scriptWindows': 'Windows-script',
    'privacy.scriptMac': 'Mac-script',
    'privacy.scriptVersion': 'versie {version}',
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
