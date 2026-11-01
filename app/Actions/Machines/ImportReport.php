<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ParsedReport;
use App\Services\Reports\ReportParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportReport
{
    public function handle(User $user, string $markdown): Report
    {
        if (! mb_check_encoding($markdown, 'UTF-8')) {
            throw ValidationException::withMessages(['report' => 'The report is not valid UTF-8 text.']);
        }

        $markdown = $this->normalise($markdown);
        $parsed = (new ReportParser)->parse($markdown);
        $identity = $parsed->identityKey();

        if ($identity === null) {
            throw ValidationException::withMessages([
                'report' => 'The report has no usable serial number or hardware UUID, so it cannot be matched to a machine.',
            ]);
        }

        return DB::transaction(function () use ($user, $markdown, $parsed, $identity): Report {
            $machine = $this->machine($user, $identity, $parsed);

            return $this->report($machine, $markdown, $parsed);
        });
    }

    // A byte order mark and CRLF are what a Windows editor adds to the same text, so
    // neither may make an identical report look new.
    private function normalise(string $markdown): string
    {
        return str_replace(["\r\n", "\r"], "\n", ltrim($markdown, "\u{FEFF}"));
    }

    private function machine(User $user, string $identity, ParsedReport $parsed): Machine
    {
        $machine = $user->machines()->withTrashed()->where('identity_key', $identity)->first()
            ?? (new Machine)->forceFill(['user_id' => $user->id, 'identity_key' => $identity]);

        $machine->forceFill([
            'serial' => $parsed->usableSerial() ?? $machine->serial,
            'hardware_uuid' => $parsed->hardwareUuid ?? $machine->hardware_uuid,
            'name' => $parsed->name ?? $machine->name,
            'os' => $parsed->os ?? $machine->os,
            'device_type' => $parsed->deviceType ?? $machine->device_type,
            'model' => $parsed->model ?? $machine->model,
            'deleted_at' => null,
        ])->save();

        return $machine;
    }

    private function report(Machine $machine, string $markdown, ParsedReport $parsed): Report
    {
        $checksum = hash('sha256', $markdown);

        $existing = $machine->reports()->withTrashed()->where('checksum', $checksum)->first();

        if ($existing instanceof Report) {
            return $existing->trashed() ? tap($existing)->restore() : $existing;
        }

        return $machine->reports()->create([
            'markdown' => $markdown,
            'checksum' => $checksum,
            'format_version' => $parsed->formatVersion,
            'flags' => $parsed->flags,
        ]);
    }
}
