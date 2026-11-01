<?php

declare(strict_types=1);

namespace App\Actions\Machines;

use App\Models\Machine;
use App\Models\User;
use App\Services\Machines\MachineIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateManualMachine
{
    public function handle(
        User $user,
        ?string $serial,
        ?string $hardwareUuid,
        ?string $name,
        ?string $os,
        ?string $deviceType,
        ?string $model,
    ): Machine {
        $identity = new MachineIdentity($this->clean($serial), $this->clean($hardwareUuid));
        $key = $this->key($identity);

        $values = [
            'serial' => $identity->usableSerial(),
            'hardware_uuid' => $identity->usableUuid(),
            'name' => $this->clean($name),
            'os' => $this->clean($os),
            'device_type' => $this->clean($deviceType),
            'model' => $this->clean($model),
        ];

        try {
            return DB::transaction(fn (): Machine => $this->store($user, $key, $values));
        } catch (UniqueConstraintViolationException) {
            // A double submit raced us to the same machine, so the retry finds it.
            return DB::transaction(fn (): Machine => $this->store($user, $key, $values));
        }
    }

    private function key(MachineIdentity $identity): string
    {
        $errors = [];

        if ($identity->serial !== null && $identity->usableSerial() === null) {
            $errors['serial'] = 'That serial number is a placeholder or unusable, so it cannot identify a machine.';
        }

        if ($identity->hardwareUuid !== null && $identity->usableUuid() === null) {
            $errors['hardware_uuid'] = 'That hardware UUID is a placeholder or unusable, so it cannot identify a machine.';
        }

        if ($errors === []) {
            $key = $identity->key();

            if ($key !== null) {
                return $key;
            }

            $errors['serial'] = 'Enter a serial number or a hardware UUID.';
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function store(User $user, string $key, array $values): Machine
    {
        $machine = $user->machines()->withTrashed()->where('identity_key', $key)->first();

        if ($machine !== null && ! $machine->trashed()) {
            return $machine;
        }

        $machine ??= (new Machine)->forceFill(['user_id' => $user->id, 'identity_key' => $key]);

        // A restored machine keeps what it already had where the form says nothing, as a report import does.
        $machine->forceFill([
            'serial' => $values['serial'] ?? $machine->serial,
            'hardware_uuid' => $values['hardware_uuid'] ?? $machine->hardware_uuid,
            'name' => $values['name'] ?? $machine->name,
            'os' => $values['os'] ?? $machine->os,
            'device_type' => $values['device_type'] ?? $machine->device_type,
            'model' => $values['model'] ?? $machine->model,
            'deleted_at' => null,
        ])->save();

        return $machine;
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);

        return $value === null || $value === '' ? null : $value;
    }
}
