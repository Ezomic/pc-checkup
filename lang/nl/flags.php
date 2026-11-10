<?php

declare(strict_types=1);

return [
    'disk_problem' => [
        'headline' => 'Een schijf meldt problemen',
        'advice' => 'Maak nu een back-up van je bestanden en laat de schijf nakijken of vervangen.',
    ],
    'disk_errors' => [
        'headline' => 'Er zijn schijffouten vastgelegd',
        'advice' => 'Slechte sectoren of fouten in de schijfaansturing kunnen betekenen dat de schijf op is. Maak een back-up van je bestanden en laat de schijf controleren.',
    ],
    'ssd_wear' => [
        'headline' => 'Een SSD is voor :percent% versleten',
        'advice' => 'De SSD zit bijna aan het einde van zijn levensduur. Maak een back-up van je bestanden en plan een vervanging.',
    ],
    'unexpected_shutdowns' => [
        'headline' => 'Deze maand :count keer vastgelopen of zonder stroom komen te zitten',
        'advice' => 'Laat de voeding, de temperaturen en het geheugen controleren. Herhaaldelijk vastlopen wijst vaak op defecte onderdelen.',
    ],
    'kernel_panics' => [
        'headline' => 'De Mac is :count keer vastgelopen',
        'advice' => 'Herhaalde kernel panics wijzen meestal op een fout in de hardware of in een stuurprogramma.',
    ],
    'drive_full' => [
        'headline' => 'Schijf :drive zit bijna vol, nog maar :percent% vrij',
        'advice' => 'Een volle schijf maakt het apparaat traag en kan updates tegenhouden. Maak ruimte vrij of verplaats bestanden naar een andere plek.',
    ],
    'disk_full' => [
        'headline' => 'De schijf zit bijna vol, nog maar :percent% vrij',
        'advice' => 'Een volle schijf maakt het apparaat traag en kan updates tegenhouden. Maak ruimte vrij of verplaats bestanden naar een andere plek.',
    ],
    'no_antivirus' => [
        'headline' => 'Geen enkele virusscanner beschermt deze pc',
        'advice' => 'Zet de realtimebescherming aan in Windows-beveiliging, of installeer een virusscanner.',
    ],
    'unsupported_os' => [
        'headline' => 'Het besturingssysteem wordt niet meer ondersteund',
        'advice' => 'Er komen geen gratis beveiligingsupdates meer voor. Werk het besturingssysteem bij naar een nieuwere versie of vervang het apparaat.',
    ],
    'sip_disabled' => [
        'headline' => 'Een belangrijke beveiliging van macOS staat uit',
        'advice' => 'Zet System Integrity Protection weer aan, tenzij je het bewust hebt uitgezet.',
    ],
    'gatekeeper_disabled' => [
        'headline' => 'macOS controleert apps niet voordat ze starten',
        'advice' => 'Zet Gatekeeper weer aan bij Privacy en beveiliging.',
    ],
    'smbv1' => [
        'headline' => 'Een verouderde manier van bestanden delen staat aan',
        'advice' => 'SMBv1 is tientallen jaren oud en gijzelsoftware heeft het gebruikt om zich te verspreiden. Zet het uit bij Windows-onderdelen, tenzij een oud apparaat het nodig heeft.',
    ],
    'rdp' => [
        'headline' => 'Extern bureaublad staat aan',
        'advice' => 'Hiermee kunnen mensen via het netwerk inloggen op deze pc. Zet het uit bij Instellingen, onder Systeem, Extern bureaublad, tenzij je het gebruikt.',
    ],
    'no_password_accounts' => [
        'headline' => 'Sommige accounts hebben geen wachtwoord nodig',
        'advice' => 'Deze accounts mogen een leeg wachtwoord hebben. Zorg dat elk account een wachtwoord heeft.',
    ],
    'windows_not_activated' => [
        'headline' => 'Windows is niet geactiveerd',
        'advice' => 'Activeer Windows met een geldige licentie, dan heb je alle functies en updates.',
    ],
    'encryption_off' => [
        'headline' => 'Schijfversleuteling staat uit',
        'advice' => 'Als het apparaat kwijtraakt of gestolen wordt, kan iedereen de bestanden lezen. Zet schijfversleuteling aan.',
    ],
    'secure_boot' => [
        'headline' => 'Secure Boot staat uit',
        'advice' => 'Het houdt schadelijke software tegen die al start voordat Windows opstart. Zet het aan in de BIOS- of UEFI-instellingen.',
    ],
    'no_tpm' => [
        'headline' => 'Geen TPM gevonden',
        'advice' => 'Windows 11 vraagt om TPM 2.0. Zoek in de BIOS naar fTPM of PTT. Zonder TPM kan dit apparaat officieel geen Windows 11 draaien.',
    ],
    'tpm_not_ready' => [
        'headline' => 'De TPM is niet klaar voor gebruik',
        'advice' => 'Zet de TPM klaar via Windows-beveiliging, onder Apparaatbeveiliging.',
    ],
    'firewall_off' => [
        'headline' => 'De firewall staat uit',
        'advice' => 'Zet hem aan, dan worden ongewenste verbindingen geblokkeerd.',
    ],
    'defender_old' => [
        'headline' => 'De virusdefinities zijn :days dagen oud',
        'advice' => 'Voer Windows Update uit, zodat de bescherming actueel blijft.',
    ],
    'windows_updates_pending' => [
        'headline' => ':count Windows-updates staan klaar',
        'advice' => 'Installeer ze. Updates bevatten beveiligingsverbeteringen.',
    ],
    'software_updates_pending' => [
        'headline' => 'Er staan software-updates klaar',
        'advice' => 'Installeer ze. Updates bevatten beveiligingsverbeteringen.',
    ],
    'low_ram' => [
        'headline' => 'Slechts :gb GB werkgeheugen',
        'advice' => 'Meer geheugen bijplaatsen is de goedkoopste manier om een ouder apparaat sneller te maken.',
    ],
    'short_memory' => [
        'headline' => 'Het apparaat heeft te weinig werkgeheugen',
        'advice' => 'Sluit zware programma\'s af of plaats extra geheugen bij.',
    ],
    'spinning_drive' => [
        'headline' => 'Er zit alleen een draaiende harde schijf in',
        'advice' => 'Met een SSD voelt een ouder apparaat veel sneller aan.',
    ],
    'driver_problems' => [
        'headline' => 'Stuurprogrammaproblemen bij apparaten: :count',
        'advice' => 'Open Apparaatbeheer en werk de stuurprogramma\'s bij of installeer ze opnieuw.',
    ],
    'battery_worn' => [
        'headline' => 'De accu is versleten',
        'advice' => 'Hij houdt minder lading vast dan toen hij nieuw was. Overweeg een nieuwe accu.',
    ],
    'activation_lock' => [
        'headline' => 'Activeringsslot staat aan',
        'advice' => 'Het Apple ID van de vorige eigenaar moet eerst worden verwijderd voordat deze Mac gereset of verkocht kan worden.',
    ],
    'remote_login' => [
        'headline' => 'Inloggen op afstand staat aan',
        'advice' => 'Andere computers kunnen proberen in te loggen op deze Mac. Zet het uit in Systeminstellingen, onder Algemeen, Delen, tenzij je het gebruikt.',
    ],
    'screen_sharing' => [
        'headline' => 'Scherm delen staat aan',
        'advice' => 'Andere mensen op het netwerk kunnen vragen om deze Mac te bekijken of over te nemen. Zet het uit in Systeminstellingen, onder Algemeen, Delen, tenzij je het gebruikt.',
    ],
    'auto_signin' => [
        'headline' => 'De computer logt zelf in',
        'advice' => 'Iedereen die de computer aanzet, kan je bestanden openen. Zet automatisch inloggen uit, tenzij dit bewust een gedeelde computer is.',
    ],
    'no_backup' => [
        'headline' => 'Er is geen back-up gevonden',
        'advice' => 'Als de schijf kapotgaat of het apparaat kwijtraakt, zijn de bestanden weg. Zorg voor een back-up op een externe schijf of in de cloud.',
    ],
    'old_backup' => [
        'headline' => 'De laatste back-up was :days dagen geleden',
        'advice' => 'Sluit de back-upschijf aan of controleer of de back-up nog draait, zodat recente bestanden ook veilig zijn.',
    ],
    'no_updates_installed' => [
        'headline' => 'Al :days dagen geen updates geïnstalleerd',
        'advice' => 'Installeer de beschikbare updates. Ze bevatten beveiligingsverbeteringen.',
        'advice_no_more_updates' => 'Voor :running komen geen updates meer uit. De nieuwste macOS die op deze Mac kan draaien, is :newest.',
        'advice_newest_unsupported' => 'Voor :running komen geen updates meer uit. De nieuwste macOS die op deze Mac kan draaien, :newest, krijgt ook geen beveiligingsupdates meer.',
        'advice_already_newest' => 'Deze Mac heeft al de nieuwste macOS die erop kan draaien, :running, en krijgt geen beveiligingsupdates meer.',
    ],
    'many_admins' => [
        'headline' => ':count accounts hebben beheerdersrechten',
        'advice' => 'Hoe minder beheerders, hoe minder schade als één account in verkeerde handen valt. Maak accounts voor dagelijks gebruik gewone gebruikers.',
    ],
    'old_bios' => [
        'headline' => 'De BIOS is :years jaar oud',
        'advice' => 'Kijk op de website van de fabrikant of er een firmware-update is. Nieuwere versies verhelpen problemen met beveiliging en stabiliteit.',
    ],
    'tamper_protection' => [
        'headline' => 'Manipulatiebeveiliging staat uit',
        'advice' => 'Zet het aan in Windows-beveiliging, zodat schadelijke software de beveiliging niet kan uitschakelen.',
    ],
    'restart_pending' => [
        'headline' => 'Een herstart is nog nodig',
        'advice' => 'Start opnieuw op om het installeren van updates af te ronden.',
    ],
    'not_restarted' => [
        'headline' => 'Al :days dagen niet opnieuw opgestart',
        'advice' => 'Af en toe opnieuw opstarten lost problemen op en past updates toe.',
    ],
    'startup_programs' => [
        'headline' => ':count programma\'s starten mee met Windows',
        'advice' => 'Zet de programma\'s die je niet nodig hebt uit in Taakbeheer, onder Opstarten.',
    ],
    'remote_agents' => [
        'headline' => 'Software voor toegang of beheer op afstand draait',
        'advice' => 'Controleer of je deze software kent. Met sommige programma\'s kunnen anderen het apparaat overnemen.',
    ],
    'managed' => [
        'headline' => 'Het lijkt beheerd te worden door een organisatie',
        'advice' => 'Regels van werk of school kunnen beperken wat je zelf mag aanpassen.',
    ],
    'running_slow' => [
        'headline' => 'Het apparaat werkt nu traag',
        'advice' => 'Zware belasting, de zuinige stand of hitte vertraagt het apparaat. Kijk later nog eens.',
    ],
    'unknown' => [
        'headline' => 'Er is iets om naar te kijken',
    ],
];
