<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\Note;

final class UpdateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Note $note, array $data): Note
    {
        $note->update($data);

        return $note;
    }
}
