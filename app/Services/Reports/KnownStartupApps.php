<?php

declare(strict_types=1);

namespace App\Services\Reports;

final readonly class KnownStartupApps
{
    private const GAME_STORE = 'Game store';

    private const PASSWORD_MANAGER = 'Password manager';

    private const CHAT = 'Chat app';

    private const AI_ASSISTANT = 'AI assistant app';

    private const GRAPHICS = 'Graphics driver helper';

    /**
     * A pattern matches a lower-cased name only with no letter or digit right before it and no letter
     * right after it, so "steam" finds "Steam -silent" but not "steamy". The first app that matches
     * wins, which is why a narrow name (Google Drive) sits above a broad one (Google).
     */
    private const APPS = [
        ['name' => 'OneDrive', 'description' => 'Syncs OneDrive files', 'patterns' => ['onedrive[a-z]*']],
        ['name' => 'Dropbox', 'description' => 'Syncs Dropbox files', 'patterns' => ['dropbox[a-z]*']],
        ['name' => 'Spotify', 'description' => 'Music app', 'patterns' => ['spotify[a-z]*']],
        ['name' => 'Teams', 'description' => 'Chat and video meetings', 'patterns' => ['(?:ms)?teams', 'microsoft\.?teams[a-z]*']],
        ['name' => 'Zoom', 'description' => 'Video meetings', 'patterns' => ['zoom(?:us|opener)?']],
        ['name' => 'Slack', 'description' => self::CHAT, 'patterns' => ['slack', 'com\.tinyspeck\.[a-z]+']],
        ['name' => 'Adobe', 'description' => 'Adobe updater or helper', 'patterns' => ['adobe[a-z]*']],
        ['name' => 'Google Drive', 'description' => 'Syncs Google Drive files', 'patterns' => ['google[ .]?drive[a-z]*', 'backup and sync from google']],
        ['name' => 'Google', 'description' => 'Google updater or helper', 'patterns' => ['(?:google|keystone)[a-z]*']],
        ['name' => 'Steam', 'description' => self::GAME_STORE, 'patterns' => ['steam', 'valvesoftware']],
        ['name' => 'iCloud', 'description' => 'Syncs iCloud files', 'patterns' => ['icloud[a-z]*']],
        ['name' => 'EA app', 'description' => 'Game store from EA', 'patterns' => ['eadm', 'ea ?desktop', 'ea ?app', 'ea ?launcher', 'electronic ?arts']],
        ['name' => 'EA Origin', 'description' => "EA's game launcher, starts a helper at startup and can be switched off if the games are not used", 'patterns' => ['^(?:ea )?origin$', '^com\.ea\.origin']],
        ['name' => 'Loom', 'description' => 'Screen recording app, can be switched off', 'patterns' => ['^loom$', '^loom desktop$', '^com\.loom']],
        ['name' => 'Discord', 'description' => 'Chat and voice app', 'patterns' => ['discord(?:ptb|canary)?']],
        ['name' => 'WhatsApp', 'description' => self::CHAT, 'patterns' => ['whatsapp']],
        ['name' => 'Skype', 'description' => 'Chat and video calls', 'patterns' => ['skype']],
        ['name' => 'Telegram', 'description' => self::CHAT, 'patterns' => ['telegram(?: ?desktop)?']],
        ['name' => 'Battle.net', 'description' => 'Game store from Blizzard', 'patterns' => ['battle\.?net', 'blizzard[a-z]*']],
        ['name' => 'Epic Games Launcher', 'description' => 'Game store from Epic Games', 'patterns' => ['epic ?games[a-z]*']],
        ['name' => 'Ubisoft Connect', 'description' => 'Game store from Ubisoft', 'patterns' => ['ubisoft[a-z]*', 'uplay[a-z]*']],
        ['name' => 'GOG Galaxy', 'description' => 'Game store from GOG', 'patterns' => ['gog ?galaxy[a-z]*', 'galaxy ?client[a-z]*']],
        ['name' => 'Riot Games', 'description' => 'Game launcher from Riot, or its anti-cheat', 'patterns' => ['riot ?(?:client|games|vanguard)[a-z]*', 'vgtray']],
        ['name' => 'CurseForge', 'description' => 'Mod manager for games', 'patterns' => ['curseforge']],
        ['name' => 'Overwolf', 'description' => 'Add-on platform for games', 'patterns' => ['overwolf[a-z]*']],
        ['name' => 'Xbox', 'description' => 'Xbox app for PC games', 'patterns' => ['xbox[a-z]*']],
        ['name' => 'Logitech G HUB', 'description' => 'Settings app for Logitech gaming mice, keyboards and headsets', 'patterns' => ['lghub[a-z]*', 'logitech ?g ?hub', 'logi\.ghub']],
        ['name' => 'Logitech', 'description' => 'Settings app for Logitech mice and keyboards', 'patterns' => ['logitech[a-z]*', 'logi ?options[a-z]*', 'logi ?bolt', 'com\.logi\.[a-z]+']],
        ['name' => 'Razer Synapse', 'description' => 'Settings app for Razer mice, keyboards and headsets', 'patterns' => ['razer[a-z]*']],
        ['name' => 'Corsair iCUE', 'description' => 'Settings app for Corsair keyboards, mice and lighting', 'patterns' => ['corsair[a-z]*', 'icue']],
        ['name' => 'SteelSeries GG', 'description' => 'Settings app for SteelSeries gear, includes Sonar audio', 'patterns' => ['steelseries[a-z]*']],
        ['name' => 'Realtek Audio', 'description' => 'Sound driver helper', 'patterns' => ['rtk ?aud[a-z]*', 'realtek[a-z]*', 'rthdvcpl', 'ravcpl']],
        ['name' => 'NVIDIA', 'description' => self::GRAPHICS, 'patterns' => ['nvidia[a-z]*', 'nv ?backend', 'nvcontainer', 'nvdisplay\.container', 'geforce[a-z]*']],
        ['name' => 'AMD Software', 'description' => self::GRAPHICS, 'patterns' => ['amd ?(?:adrenalin|software|noise|radeon|link|rsserv|ryzen)[a-z]*', 'radeon[a-z]*', 'cncmd']],
        ['name' => 'Intel', 'description' => 'Intel driver helper', 'patterns' => ['intel(?: ?(?:graphics|driver|dsa|optane|rst|unison)[a-z]*)?', 'igfx[a-z]*', 'hkcmd']],
        ['name' => 'Box', 'description' => 'Syncs Box files', 'patterns' => ['^box$', 'box ?(?:drive|sync|tools|edit)[a-z]*', 'com\.box\.[a-z]+']],
        ['name' => 'MEGA', 'description' => 'Syncs MEGA files', 'patterns' => ['^mega$', 'mega ?sync[a-z]*', 'mega\.(?:mac|nz)']],
        ['name' => 'iTunes', 'description' => 'Helper for iTunes and iPhones', 'patterns' => ['itunes[a-z]*']],
        ['name' => 'Microsoft AutoUpdate', 'description' => 'Keeps Microsoft Office up to date', 'patterns' => ['microsoft ?auto ?update']],
        ['name' => 'Brave', 'description' => 'Brave browser or its updater', 'patterns' => ['brave(?:software|update)?']],
        ['name' => 'Firefox', 'description' => 'Firefox browser or its updater', 'patterns' => ['firefox[a-z]*']],
        ['name' => 'Opera', 'description' => 'Opera browser or its updater', 'patterns' => ['opera(?: ?gx)?', 'operasoftware']],
        ['name' => 'Vivaldi', 'description' => 'Vivaldi browser or its updater', 'patterns' => ['vivaldi']],
        ['name' => '1Password', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['1password']],
        ['name' => 'Bitwarden', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['bitwarden']],
        ['name' => 'LastPass', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['lastpass']],
        ['name' => 'Dashlane', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['dashlane']],
        ['name' => 'KeePass', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['keepass(?:xc)?']],
        ['name' => 'NordPass', 'description' => self::PASSWORD_MANAGER, 'patterns' => ['nordpass']],
        ['name' => 'Claude', 'description' => self::AI_ASSISTANT, 'patterns' => ['claude', 'com\.anthropic\.[a-z]+']],
        ['name' => 'ChatGPT', 'description' => self::AI_ASSISTANT, 'patterns' => ['chatgpt', 'com\.openai\.[a-z]+']],
    ];

    /**
     * @return array{name: string, description: string}|null
     */
    public function find(string $key): ?array
    {
        foreach (self::APPS as $app) {
            if (preg_match('/(?<![a-z0-9])(?:'.implode('|', $app['patterns']).')(?![a-z])/', $key) === 1) {
                return ['name' => $app['name'], 'description' => $app['description']];
            }
        }

        return null;
    }
}
