<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\Note;
use App\Models\User;

final class CreateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data): Note
    {
        return $user->notes()->create($data);
    }
}
