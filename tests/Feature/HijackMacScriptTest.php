<?php

declare(strict_types=1);

use App\Services\Reports\ReportNetworkSettings;
use App\Services\Reports\ReportRemoteAccess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ScriptTemp;

function hmsMissing(): bool
{
    return PHP_OS_FAMILY === 'Windows' || trim((string) shell_exec('command -v perl 2>/dev/null')) === '';
}

/**
 * @param  array<string, string>  $bins  command name => script body
 * @param  array<string, string>  $env
 */
function hmsRun(string $function, array $bins, array $env = []): ProcessResult
{
    $bin = ScriptTemp::bin('pcc-hms-bin', $bins);

    return Process::path(base_path())->timeout(60)->env(array_merge(['PATH' => $bin.':'.getenv('PATH')], $env))
        ->run(['bash', '-c', 'source scripts/mac-checkup.sh; '.$function]);
}

/**
 * @return list<string>
 */
function hmsLines(ProcessResult $result): array
{
    return array_values(array_filter(explode("\n", trim($result->throw()->output())), fn (string $line): bool => $line !== ''));
}

/**
 * @param  list<string>  $names
 */
function hmsDir(array $names): string
{
    $dir = ScriptTemp::dir('pcc-hms-apps');

    foreach ($names as $name) {
        str_ends_with($name, '.plist') ? file_put_contents("{$dir}/{$name}", '') : File::ensureDirectoryExists("{$dir}/{$name}");
    }

    return $dir;
}

function hmsPrint(string $text, int $exit = 0): string
{
    $file = ScriptTemp::dir('pcc-hms-out').'/out.txt';
    file_put_contents($file, $text);

    return 'cat '.escapeshellarg($file)."\nexit {$exit}";
}

function hmsHosts(string $text): string
{
    $file = ScriptTemp::dir('pcc-hms-hosts').'/hosts';
    file_put_contents($file, $text);

    return $file;
}

function hmsHostsFixture(): string
{
    return implode("\n", [
        '##',
        '# Host Database',
        '##',
        '127.0.0.1       localhost',
        '255.255.255.255 broadcasthost',
        '::1             localhost',
        'fe80::1%lo0     localhost',
        '',
        '   ',
        '10.0.0.5 nas.home printer.home   # my devices',
        '6.6.6.6 www.google.com google.com. LOGIN.ING.NL',
        '0.0.0.0 vortex.data.microsoft.com',
        '::1 telemetry.apple.com',
        '127.0.0.2 paypal.com',
        '6.6.6.6 notgoogle.com google.com.evil.example',
        '6.6.6.6',
        'garbage',
    ])."\n";
}

function hmsProxy(string $body): string
{
    return "<dictionary> {\n  ExceptionsList : <array> {\n    0 : *.local\n    1 : 169.254/16\n  }\n  FTPPassive : 1\n{$body}}\n";
}

function hmsDns(): string
{
    return <<<'TXT'
DNS configuration

resolver #1
  search domain[0] : home
  nameserver[0] : 192.168.1.1
  nameserver[1] : fe80::1%en0
  if_index : 6 (en0)
  flags    : Request A records, Request AAAA records
  reach    : 0x00020002 (Reachable,Directly Reachable Address)

resolver #2
  domain   : local
  options  : mdns
  timeout  : 5
  order    : 300000

DNS configuration (for scoped queries)

resolver #1
  nameserver[0] : 192.168.1.1
  nameserver[1] : 8.8.8.8
  nameserver[2] : bad$(touch)
  nameserver[3] : 1.1.1.1
  nameserver[4] : 9.9.9.9
  nameserver[5] : 10.0.0.1
  nameserver[6] : 10.0.0.2
  nameserver[7] : 10.0.0.3
  nameserver[8] : 10.0.0.4
TXT;
}

/**
 * @param  array<string, string>  $env
 */
function hmsNetwork(string $proxy, string $dns, int $proxyExit = 0, int $dnsExit = 0, array $env = []): ProcessResult
{
    $scutil = "case \"\$1\" in\n--proxy) ".hmsPrint($proxy, $proxyExit)." ;;\n--dns) ".hmsPrint($dns, $dnsExit)." ;;\nesac";

    return hmsRun('pcc_network_report', ['scutil' => $scutil], array_merge(['PCC_HOSTS_FILE' => hmsHosts(hmsHostsFixture())], $env));
}

describe('the Mac remote-access step', function () {
    it('prints only known tool names, with how each was found', function () {
        $dirs = hmsDir(['TeamViewer.app', 'Secret Bank.app', 'LogMeIn Hamachi.app', 'Parsec.app', 'ParsecSoda.app', 'Utilities']).':'.hmsDir(['com.philandro.anydesk.service.plist', 'com.google.keystone.daemon.plist']);
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\nFinder\nAnyDesk\nrustdesk\nHamachi\n")], ['PCC_REMOTE_DIRS' => $dirs]);

        expect(hmsLines($result))->toBe([
            'format: 1',
            'scan: complete',
            'tool|TeamViewer|installed',
            'tool|AnyDesk|installed,running',
            'tool|RustDesk|running',
            'tool|Parsec|installed',
        ])->and($result->output())->not->toContain('Secret')->not->toContain('LogMeIn')->not->toContain('keystone');
    })->skip(hmsMissing(), 'perl is not installed here');

    it('says when none of the known tools are found', function () {
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\nFinder\n")], ['PCC_REMOTE_DIRS' => hmsDir(['Safari.app']).':/does/not/exist:']);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: complete']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('marks the scan partial when running programs cannot be read, and could not check when nothing can', function () {
        $partial = hmsRun('pcc_remote_report', ['ps' => hmsPrint('', 1)], ['PCC_REMOTE_DIRS' => hmsDir(['AnyDesk.app'])]);
        $nothing = hmsRun('pcc_remote_report', ['ps' => hmsPrint('', 1)], ['PCC_REMOTE_DIRS' => '/does/not/exist']);

        expect(hmsLines($partial))->toBe(['format: 1', 'scan: partial (running programs could not be read)', 'tool|AnyDesk|installed'])
            ->and(hmsLines($nothing))->toBe(['format: 1', 'scan: could not check']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('stops at the time cap and leaves no process behind', function () {
        $started = microtime(true);
        $result = hmsRun('pcc_remote_report', ['ps' => 'sleep 30'], ['PCC_REMOTE_DIRS' => hmsDir(['AnyDesk.app']), 'PCC_REMOTE_CAP' => '1']);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: partial (time limit of 1 seconds reached)', 'tool|AnyDesk|installed'])
            ->and(microtime(true) - $started)->toBeLessThan(10.0);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('does not say none were found when no folder could be read', function () {
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\nFinder\n")], ['PCC_REMOTE_DIRS' => '/does/not/exist']);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: partial (installed apps could not be read)']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('marks the scan partial when listing a folder fails', function () {
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\nAnyDesk\n"), 'find' => 'exit 1'], ['PCC_REMOTE_DIRS' => hmsDir(['TeamViewer.app'])]);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: partial (installed apps could not be read)', 'tool|AnyDesk|running']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('leaves out Radmin VPN and other BeyondTrust products', function () {
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\n")], ['PCC_REMOTE_DIRS' => hmsDir(['BeyondTrust Privilege Management.app', 'Radmin VPN.app'])]);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: complete']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('copes with an empty folder list', function () {
        $result = hmsRun('pcc_remote_report', ['ps' => hmsPrint("COMM\nTeamViewer\n")], ['PCC_REMOTE_DIRS' => ':']);

        expect(hmsLines($result))->toBe(['format: 1', 'scan: partial (installed apps could not be read)', 'tool|TeamViewer|running']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('uses only names the app knows', function () {
        $names = hmsLines(hmsRun('pcc_remote_tools | cut -d"|" -f1', []));

        expect($names)->not->toBeEmpty()->and(array_diff($names, ReportRemoteAccess::KNOWN))->toBe([]);
    })->skip(hmsMissing(), 'perl is not installed here');
});

describe('the Mac network step', function () {
    it('summarises the hosts file, the proxy and the DNS servers', function () {
        $result = hmsNetwork(hmsProxy("  HTTPEnable : 1\n  HTTPPort : 8080\n  HTTPProxy : proxy.example.com\n  HTTPSEnable : 0\n"), hmsDns());

        expect(hmsLines($result))->toBe([
            'format: 1',
            'hosts: complete',
            'hosts-entries: 10',
            'hosts-redirected: google.com, ing.nl',
            'hosts-blocked: microsoft.com, apple.com, paypal.com',
            'proxy: on',
            'proxy-host: proxy.example.com:8080',
            'dns: complete',
            'dns-server: 192.168.1.1',
            'dns-server: fe80::1%en0',
            'dns-server: 8.8.8.8',
            'dns-server: 1.1.1.1',
            'dns-server: 9.9.9.9',
            'dns-server: 10.0.0.1',
            'dns-server: 10.0.0.2',
            'dns-server: 10.0.0.3',
        ])->and($result->output())->not->toContain('nas.home')->not->toContain('6.6.6.6')->not->toContain('evil')->not->toContain('touch');
    })->skip(hmsMissing(), 'perl is not installed here');

    it('reads the proxy settings', function (string $body, array $lines) {
        $all = hmsLines(hmsNetwork(hmsProxy($body), ''));

        expect(array_values(array_filter($all, fn (string $line): bool => str_starts_with($line, 'proxy'))))->toBe($lines);
    })->with([
        'off' => ["  HTTPEnable : 0\n  HTTPSEnable : 0\n  ProxyAutoConfigEnable : 0\n", ['proxy: off']],
        'nothing set' => ['', ['proxy: off']],
        'https wins' => ["  HTTPEnable : 1\n  HTTPProxy : a.example\n  HTTPSEnable : 1\n  HTTPSPort : 443\n  HTTPSProxy : b.example\n", ['proxy: on', 'proxy-host: b.example:443']],
        'socks' => ["  SOCKSEnable : 1\n  SOCKSProxy : 10.0.0.9\n", ['proxy: on', 'proxy-host: 10.0.0.9']],
        'ipv6' => ["  HTTPEnable : 1\n  HTTPProxy : [fd00::5]\n  HTTPPort : 3128\n", ['proxy: on', 'proxy-host: [fd00::5]:3128']],
        'too long' => ["  HTTPEnable : 1\n  HTTPProxy : ".str_repeat('a', 101)."\n", ['proxy: on']],
        'script with secrets' => ["  ProxyAutoConfigEnable : 1\n  ProxyAutoConfigURLString : http://user:pw@wpad.example.net/proxy.pac?token=abc\n", ['proxy: on', 'proxy-script-host: wpad.example.net']],
        'slash in the password' => ["  ProxyAutoConfigEnable : 1\n  ProxyAutoConfigURLString : http://u:pa/ss@wpad.example/x.pac\n", ['proxy: on', 'proxy-script-host: wpad.example']],
        'enabled without host' => ["  HTTPEnable : 1\n", ['proxy: on']],
        'odd host' => ["  HTTPEnable : 1\n  HTTPProxy : bad<host>\n", ['proxy: on']],
        'nested key ignored' => ["  ExceptionsList : <array> {\n    HTTPEnable : 1\n  }\n", ['proxy: off']],
    ])->skip(hmsMissing(), 'perl is not installed here');

    it('counts nothing for the default entries', function () {
        $result = hmsRun('pcc_hosts_summary', [], ['PCC_HOSTS_FILE' => hmsHosts("127.0.0.1 localhost\n255.255.255.255 broadcasthost\n::1 localhost\nfe80::1%lo0 localhost\n")]);

        expect(hmsLines($result))->toBe(['hosts: complete', 'hosts-entries: 0']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('reads a hosts file with Windows line endings', function () {
        $result = hmsRun('pcc_hosts_summary', [], ['PCC_HOSTS_FILE' => hmsHosts("127.0.0.1 localhost\r\n127.0.0.1 google.com\r\n6.6.6.6 ing.nl\r\n")]);

        expect(hmsLines($result))->toBe(['hosts: complete', 'hosts-entries: 2', 'hosts-redirected: ing.nl', 'hosts-blocked: google.com']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('says it could not check a missing hosts file', function () {
        expect(hmsLines(hmsRun('pcc_hosts_summary', [], ['PCC_HOSTS_FILE' => '/does/not/exist'])))->toBe(['hosts: could not check']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('stops at its line limit and says so', function () {
        $lines = implode("\n", array_map(fn (int $i): string => "0.0.0.0 ad{$i}.example", range(1, 50010)))."\n6.6.6.6 ing.nl\n";

        expect(hmsLines(hmsRun('pcc_hosts_summary', [], ['PCC_HOSTS_FILE' => hmsHosts($lines)])))
            ->toBe(['hosts: partial (only the first 50000 lines were read)', 'hosts-entries: 50000']);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('says it could not check when scutil fails', function () {
        $lines = hmsLines(hmsNetwork('', '', proxyExit: 1, dnsExit: 1));

        expect($lines)->toContain('proxy: could not check')->toContain('dns: could not check');
    })->skip(hmsMissing(), 'perl is not installed here');

    it('stops scutil at the time cap and leaves no process behind', function () {
        $started = microtime(true);
        $result = hmsRun('pcc_network_report', ['scutil' => 'sleep 30'], ['PCC_HOSTS_FILE' => hmsHosts(''), 'PCC_NETWORK_CAP' => '1']);

        expect(hmsLines($result))->toBe(['format: 1', 'hosts: complete', 'hosts-entries: 0', 'proxy: could not check', 'dns: could not check'])
            ->and(microtime(true) - $started)->toBeLessThan(10.0);
    })->skip(hmsMissing(), 'perl is not installed here');

    it('uses exactly the watched sites the app knows', function () {
        expect(explode(',', hmsLines(hmsRun('echo "$PCC_WATCHED_SITES"', []))[0]))->toBe(ReportNetworkSettings::WATCHED_SITES);
    })->skip(hmsMissing(), 'perl is not installed here');
});

describe('the Mac script as a whole', function () {
    function hmsBody(string $name): string
    {
        preg_match('/^'.preg_quote($name, '/').'\(\) \{.*?^\}$/ms', (string) file_get_contents(base_path('scripts/mac-checkup.sh')), $match);

        expect($match)->not->toBeEmpty();

        return $match[0];
    }

    it('caps every new read with pcc_capped', function () {
        expect(hmsBody('pcc_remote_report'))->toContain('pcc_capped "$PCC_REMOTE_CAP" find')->toContain('pcc_capped "$PCC_REMOTE_CAP" ps')
            ->and(hmsBody('pcc_hosts_summary'))->toContain('pcc_capped "$PCC_NETWORK_CAP" awk')
            ->and(hmsBody('pcc_proxy_summary'))->toContain('pcc_capped "$PCC_NETWORK_CAP" scutil --proxy')
            ->and(hmsBody('pcc_dns_summary'))->toContain('pcc_capped "$PCC_NETWORK_CAP" scutil --dns');
    });

    it('adds the two sections after the hosts file and keeps the update check and the privacy notice', function () {
        $script = (string) file_get_contents(base_path('scripts/mac-checkup.sh'));

        expect(strpos($script, 'section "Remote access tools" pcc_remote_report'))->toBeGreaterThan(strpos($script, 'sh_section "Hosts file (non-default lines)"'))
            ->and($script)->toContain('section "Network settings" pcc_network_report')
            ->toContain("\npcc_update_check || exit 1")
            ->and(substr_count($script, 'EN: This scan only reads'))->toBe(1);
    });
});
