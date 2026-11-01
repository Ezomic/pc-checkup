<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Notes\CreateNote;
use App\Actions\Notes\DeleteNote;
use App\Actions\Notes\UpdateNote;
use App\Http\Requests\Notes\StoreNoteRequest;
use App\Http\Requests\Notes\UpdateNoteRequest;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

final class NoteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('notes/Index', [
            'notes' => $user->notes()
                ->latest()
                ->paginate(Config::integer('pagination.per_page'))
                ->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('notes/Create');
    }

    public function store(StoreNoteRequest $request, CreateNote $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $action->handle($user, $request->validated());

        return to_route('notes.index');
    }

    public function edit(Note $note): Response
    {
        $this->authorize('update', $note);

        return Inertia::render('notes/Edit', ['note' => $note]);
    }

    public function update(UpdateNoteRequest $request, Note $note, UpdateNote $action): RedirectResponse
    {
        $this->authorize('update', $note);

        $action->handle($note, $request->validated());

        return to_route('notes.index');
    }

    public function destroy(Note $note, DeleteNote $action): RedirectResponse
    {
        $this->authorize('delete', $note);

        $action->handle($note);

        return to_route('notes.index');
    }
}
