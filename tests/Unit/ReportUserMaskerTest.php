<?php

declare(strict_types=1);

use App\Services\Reports\ReportRedactor;
use App\Services\Reports\ReportUserMasker;

function maskReport(string $body, string $title = 'Mac checkup: Test-MacBook'): string
{
    return (new ReportUserMasker)->mask("# {$title}\n\n- Format: 1\n\n{$body}\n");
}

function macSection(string $title, string $lines): string
{
    return "## {$title}\n\n```\n{$lines}\n```\n";
}

describe('home paths', function () {
    it('masks the name in a Mac home path', function () {
        $out = maskReport(macSection('Performance: biggest folders in home', "38G\t/Users/robbin/Library\n21G\t/Users/robbin/Movies"));

        expect($out)->toContain("38G\t/Users/<user>/Library")
            ->toContain("21G\t/Users/<user>/Movies")
            ->not->toContain('robbin');
    });

    it('masks a Windows profile folder, with a space in the name, either slash and any case', function () {
        $out = maskReport(macSection('Startup programs', "C:\\Users\\John Smith\\AppData\\Roaming\\x.exe\nc:/users/bob/Desktop\nC:\\Users\\kim --flag"));

        expect($out)->toContain('C:\\Users\\<user>\\AppData\\Roaming\\x.exe')
            ->toContain('c:/users/<user>/Desktop')
            ->toContain('C:\\Users\\<user> --flag')
            ->not->toContain('John')->not->toContain('bob')->not->toContain('kim');
    });

    it('keeps shared and system profile folders', function () {
        $body = macSection('Folders', "/Users/Shared/Library\nC:\\Users\\Public\\Desktop\nC:\\Users\\Default User\\x\nC:\\Users\\All Users\\y\nC:\\Users\\Default\\z\n/Users/Guest/a");

        expect(maskReport($body))->toContain('/Users/Shared/Library')->toContain('C:\\Users\\Public\\Desktop')
            ->toContain('C:\\Users\\Default User\\x')->toContain('C:\\Users\\All Users\\y')
            ->toContain('C:\\Users\\Default\\z')->toContain('/Users/Guest/a');
    });

    it('leaves a path with a variable, a wildcard or no name alone', function () {
        $body = macSection('Folders', "/Users/\$USER/x\n/Users/*/x\n/Users/\nC:\\Users\\%USERNAME%\\x\n/Users/{name}/x");

        expect(maskReport($body))->toContain("/Users/\$USER/x\n/Users/*/x\n/Users/\nC:\\Users\\%USERNAME%\\x\n/Users/{name}/x");
    });

    it('leaves a name longer than 64 characters alone', function () {
        $long = str_repeat('x', 65);

        expect(maskReport(macSection('Folders', "/Users/{$long}/a")))->toContain("/Users/{$long}/a");
    });

    it('masks the account even when it is called admin, user or root-like words that are otherwise common', function () {
        expect(maskReport(macSection('Folders', "/Users/admin/a\n/Users/user/b")))->toContain('/Users/<user>/a')->toContain('/Users/<user>/b');
    });
});

describe('account lines', function () {
    it('masks the crontab message', function () {
        $out = maskReport(macSection('Cron jobs', 'crontab: no crontab for robbin'));

        expect($out)->toContain('crontab: no crontab for <user>')->not->toContain('robbin');
    });

    it('masks the user accounts, admin group and console user of a Mac but not system accounts', function () {
        $lines = "daemon\nnobody\nrobbin\nanna\nroot\n\nAdmin group members:\nGroupMembership: root robbin\n\nConsole user:\nrobbin";
        $out = maskReport(macSection('User accounts', $lines));

        expect($out)->toContain("daemon\nnobody\n<user>\n<user>\nroot\n")
            ->toContain('GroupMembership: root <user>')
            ->toContain("Console user:\n<user>")
            ->not->toContain('robbin')->not->toContain('anna');
    });

    it('masks the console user when the line after it is empty first', function () {
        expect(maskReport(macSection('User accounts', "Console user:\n\nrobbin")))->toContain("Console user:\n\n<user>");
    });

    it('masks the accounts table and the administrators group of Windows', function () {
        $lines = "\nName               Enabled LastLogon PasswordRequired\n----               ------- --------- ----------------\nAdministrator      False             True\nrobbin             True    1/1/2026  True\nJohn Smith         True    1/1/2026  True\n\nAdministrators group:\n\nName                ObjectClass\n----                -----------\nTEST-PC\\Administrator User\nTEST-PC\\robbin       User\n";
        $out = maskReport(macSection('User accounts', $lines), 'Windows checkup: TEST-PC');

        expect($out)->toContain('Administrator      False')
            ->toContain('<user>             True    1/1/2026  True')
            ->toContain('TEST-PC\\Administrator User')
            ->toContain('TEST-PC\\<user>       User')
            ->not->toContain('robbin')->not->toContain('John Smith');
    });

    it('masks the user of a login session and of the login history', function () {
        $lines = "robbin   console  Nov  3 09:00\nanna     ttys001  Nov  3 09:05\n\nrobbin   console                   Tue Nov  3 09:00   still logged in\nreboot   ~                         Tue Nov  3 08:59\nshutdown ~                         Mon Nov  2 22:00\n\nwtmp begins Mon Oct  5 10:00";
        $out = maskReport(macSection('Logged in users and recent logins', $lines));

        expect($out)->toContain('<user>   console  Nov  3 09:00')
            ->toContain('<user>     ttys001  Nov  3 09:05')
            ->toContain('reboot   ~')->toContain('shutdown ~')->toContain('wtmp begins')
            ->not->toContain('robbin')->not->toContain('anna');
    });

    it('does not mistake an error line in the login section for a user', function () {
        expect(maskReport(macSection('Logged in users and recent logins', 'last: /var/log/wtmp: Permission denied')))
            ->toContain('last: /var/log/wtmp: Permission denied');
    });

    it('masks the owner column of the listening ports but not system owners', function () {
        $lines = "COMMAND   PID   USER   FD   TYPE\nrapportd  412 robbin    8u  IPv4 0t0  TCP *:49152 (LISTEN)\nmDNSResp  100 _mdnsresponder 7u IPv4 TCP *:5353\nsshd      1 root 3u IPv4 TCP *:22 (LISTEN)";
        $out = maskReport(macSection('Listening network ports', $lines));

        expect($out)->toContain('rapportd  412 <user>    8u')
            ->toContain('_mdnsresponder')->toContain('sshd      1 root')
            ->toContain('COMMAND   PID   USER   FD   TYPE')
            ->not->toContain('robbin');
    });
});

describe('names elsewhere in the report', function () {
    it('masks a known name as a whole word in other text, in any case', function () {
        $body = macSection('Folders', '/Users/robbin/x').'## Notes'."\n\nOwner Robbin and ROBBIN, also (robbin) here.\n";
        $out = maskReport($body);

        expect($out)->toContain('Owner <user> and <user>, also (<user>) here.');
    });

    it('does not mangle words that merely contain the name', function () {
        $body = macSection('Folders', '/Users/marie/x')."\n## Notes\n\nMarieke, mariena, Annemarie, marie.plist and marie are different.\n";
        $out = maskReport($body);

        expect($out)->toContain('Marieke, mariena, Annemarie, marie.plist and <user> are different.');
    });

    it('masks possessive device names and host names built from the name', function () {
        $body = macSection('Folders', '/Users/robbert/x')."\n## Bluetooth\n\nRobbert's iPhone, robbert’s MacBook Pro, MacBook-robbert and Robbert-MacBook-Pro.local\n";
        $out = maskReport($body);

        expect($out)->toContain('<user>\'s iPhone, <user>’s MacBook Pro, MacBook-<user> and <user>-MacBook-Pro.local');
    });

    it('does not turn a name that is also a vendor or common word into noise elsewhere', function () {
        $body = macSection('Folders', '/Users/Dell/x')."\n## Hardware overview\n\nManufacturer: Dell\n";

        expect(maskReport($body))->toContain('/Users/<user>/x')->toContain('Manufacturer: Dell');
    });

    it('does not touch the title or the header lines, so a host name that holds the name survives', function () {
        $out = (new ReportUserMasker)->mask("# Mac checkup: Robbin-MacBook-Air\n\n- Model: Robbin MacBook\n\n".macSection('Folders', '/Users/robbin/x'));

        expect($out)->toContain('# Mac checkup: Robbin-MacBook-Air')->toContain('- Model: Robbin MacBook');
    });

    it('leaves a report without sections to the contextual masks only', function () {
        $out = (new ReportUserMasker)->mask("# Mac checkup: T\n\n/Users/robbin/x and robbin\n");

        expect($out)->toContain('/Users/<user>/x and robbin');
    });

    it('never masks short or common names outside the places that prove they are the account', function (string $name) {
        $body = macSection('Folders', "/Users/{$name}/x")."\n## Notes\n\nA Mac mini, macOS, an admin tool, a user guide, a test, the data and a window.\n";
        $out = maskReport($body);

        expect($out)->toContain('/Users/<user>/x')
            ->toContain('A Mac mini, macOS, an admin tool, a user guide, a test, the data and a window.');
    })->with(['a', 'mac', 'admin', 'user', 'test', 'data', 'Apple', 'ab']);

    it('masks a name made of regex characters literally', function () {
        $body = macSection('Folders', '/Users/j.doe+x/a')."\n## Notes\n\nj.doe+x and jXdoe+x and j.doe+xx\n";
        $out = maskReport($body);

        expect($out)->toContain('<user> and jXdoe+x and j.doe+xx');
    });

    it('masks names with accents regardless of case and keeps longer words', function () {
        $body = macSection('Folders', '/Users/Étienne/a')."\n## Notes\n\nÉTIENNE and Étiennette\n";
        $out = maskReport($body);

        expect($out)->toContain('<user> and Étiennette');
    });

    it('masks several accounts', function () {
        $body = macSection('Folders', "/Users/robbin/a\n/Users/mireille/b")."\n## Notes\n\nrobbin and mireille\n";
        $out = maskReport($body);

        expect($out)->toContain('<user> and <user>')->not->toContain('robbin')->not->toContain('mireille');
    });

    it('copes with more names than the masker will search for elsewhere', function () {
        $paths = collect(range(1, 60))->map(fn (int $n): string => "/Users/person{$n}name/a")->implode("\n");
        $out = maskReport(macSection('Folders', $paths));

        expect($out)->not->toContain('name/a')->toContain('/Users/<user>/a');
    });
});

describe('idempotence and old reports', function () {
    function composite(): string
    {
        return maskReport(
            macSection('Performance: biggest folders in home', "38G\t/Users/robbin/Library")
            .macSection('User accounts', "daemon\nrobbin\n\nConsole user:\nrobbin")
            .macSection('Cron jobs', 'crontab: no crontab for robbin')
            .macSection('Logged in users and recent logins', 'robbin   console  Nov  3 09:00')
            ."## Notes\n\nrobbin was here\n",
        );
    }

    it('gives the same text when run again', function () {
        $once = composite();

        expect((new ReportUserMasker)->mask($once))->toBe($once)->not->toContain('robbin');
    });

    it('leaves a report without any user name exactly as it was', function () {
        $text = macReportFixtureWithoutUser();

        expect((new ReportUserMasker)->mask($text))->toBe($text);
    });

    it('does not fail on text that is not valid UTF-8', function () {
        $text = "# Mac checkup: T\n\n## Folders\n\n/Users/robbin/a\n/Users/x\xff/b\nno crontab for \xff\nC:\\Users\\\xff\\x\nrobbin \xff\n";

        $out = (new ReportUserMasker)->mask($text);

        expect($out)->toContain('/Users/<user>/a')->toContain("robbin \xff");
    });

    it('is reached through the redactor, which also turns CRLF into LF', function () {
        $out = (new ReportRedactor)->redact("# Mac checkup: T\r\n\r\n## Folders\r\n\r\n/Users/robbin/a\r\nC:\\Users\\robbin\\b\r\nrobbin\r\n");

        expect($out)->toBe("# Mac checkup: T\n\n## Folders\n\n/Users/<user>/a\nC:\\Users\\<user>\\b\n<user>\n");
    });

    it('still masks the Mac user in the shipped fixtures and keeps them parseable', function () {
        $out = (new ReportRedactor)->redact(macReportFixture());

        expect($out)->not->toContain('testuser')->toContain('/Users/<user>/Library')->toContain('# Mac checkup: Test-MacBook-Air');
    });
});

function macReportFixtureWithoutUser(): string
{
    return "# Mac checkup: Plain\n\n- Format: 1\n\n## Disk\n\n```\nFilesystem 500Gi\n/Users/Shared/x\n```\n";
}
