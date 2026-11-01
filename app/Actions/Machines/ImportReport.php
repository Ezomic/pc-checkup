<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ParsedReport;
use App\Services\Reports\ReportParser;
use Illuminate\Database\UniqueConstraintViolationException;
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

        try {
            return DB::transaction(fn (): Report => $this->store($user, $markdown, $parsed, $identity));
        } catch (UniqueConstraintViolationException) {
            // A double submit raced us to the same machine or report, so the retry finds it.
            return DB::transaction(fn (): Report => $this->store($user, $markdown, $parsed, $identity));
        }
    }

    // A byte order mark, CRLF and trailing whitespace are what an editor, a Windows shell
    // or the paste box adds to the same text, so none may make an identical report look new.
    private function normalise(string $markdown): string
    {
        $markdown = preg_replace('/^\x{FEFF}/u', '', $markdown) ?? $markdown;

        return trim(str_replace(["\r\n", "\r"], "\n", $markdown))."\n";
    }

    private function store(User $user, string $markdown, ParsedReport $parsed, string $identity): Report
    {
        $machine = $user->machines()->withTrashed()->where('identity_key', $identity)->first();
        $checksum = hash('sha256', $markdown);
        $existing = $machine?->reports()->withTrashed()->where('checksum', $checksum)->first();

        // Re-importing a report we already hold must not roll the machine back to what an
        // older report said about it.
        if ($machine !== null && ! $machine->trashed() && $existing !== null && ! $existing->trashed()) {
            return $existing;
        }

        $machine = $this->saveMachine($machine ?? (new Machine)->forceFill(['user_id' => $user->id, 'identity_key' => $identity]), $parsed);

        if ($existing !== null) {
            return tap($existing)->restore();
        }

        return $machine->reports()->create([
            'markdown' => $markdown,
            'checksum' => $checksum,
            'format_version' => $parsed->formatVersion,
            'flags' => $parsed->flags,
        ]);
    }

    private function saveMachine(Machine $machine, ParsedReport $parsed): Machine
    {
        $machine->forceFill([
            'serial' => $parsed->usableSerial() ?? $machine->serial,
            'hardware_uuid' => $parsed->usableUuid() ?? $machine->hardware_uuid,
            'name' => $parsed->name ?? $machine->name,
            'os' => $parsed->os ?? $machine->os,
            'device_type' => $parsed->deviceType ?? $machine->device_type,
            'model' => $parsed->model ?? $machine->model,
            'deleted_at' => null,
        ])->save();

        return $machine;
    }
}
