<?php

declare(strict_types=1);

namespace App\Actions\Notes;

use App\Models\Note;

final class DeleteNote
{
    public function handle(Note $note): void
    {
        $note->delete();
    }
}
