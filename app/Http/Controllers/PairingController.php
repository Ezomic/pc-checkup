<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Scans\ApprovePairing;
use App\Actions\Scans\RejectPairing;
use App\Enums\PairingStatus;
use App\Http\Requests\Scans\ApprovePairingRequest;
use App\Models\Client;
use App\Models\Machine;
use App\Models\ScanPairing;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page the QR code opens on the owner's phone. A pairing is anyone's to decide while it
 * waits; once decided, only the owner who decided it can see it at all.
 */
final class PairingController extends Controller
{
    public function show(Request $request, string $pairing): Response
    {
        $user = $this->user($request);
        $scan = $this->find($pairing, $user);
        $status = $scan->status();

        $response = Inertia::render('pairings/Show', [
            'pairing' => [
                'id' => $pairing,
                'checkCode' => $scan->check_code,
                'status' => $status->value,
                'expiresAt' => $scan->expires_at->toIso8601String(),
                'client' => $scan->client?->only(['id', 'name']),
                'machine' => $scan->machine === null ? null : $this->machineOption($scan->machine),
            ],
            'clients' => $status === PairingStatus::Pending ? $this->clientOptions($user) : [],
        ])->toResponse($request);

        // The id is in the URL, so it must not travel on as a referrer or sit in a shared cache.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function approve(ApprovePairingRequest $request, string $pairing, ApprovePairing $action): RedirectResponse
    {
        $user = $this->user($request);
        $scan = $this->find($pairing, $user);
        $client = $user->clients()->findOrFail($request->integer('client_id'));
        $machine = $request->filled('machine_id') ? $user->machines()->findOrFail($request->integer('machine_id')) : null;

        $this->toast($action->handle($scan, $user, $client, $machine), 'Approved. The scan uploads in a few seconds.');

        return to_route('pairings.show', ['pairing' => $pairing]);
    }

    public function reject(Request $request, string $pairing, RejectPairing $action): RedirectResponse
    {
        $user = $this->user($request);

        $this->toast($action->handle($this->find($pairing, $user), $user), 'Rejected. Nothing is uploaded.');

        return to_route('pairings.show', ['pairing' => $pairing]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function find(string $pairing, User $user): ScanPairing
    {
        $scan = ScanPairing::wellFormed($pairing, ScanPairing::ID_LENGTH)
            ? ScanPairing::query()->forPairing($pairing)->with(['client', 'machine'])->first()
            : null;

        abort_if($scan === null || ($scan->user_id !== null && $scan->user_id !== $user->id), 404);

        return $scan;
    }

    private function toast(bool $done, string $message): void
    {
        Inertia::flash('toast', $done
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'error', 'message' => 'This request was already decided or has expired.']);
    }

    /**
     * @return list<array{id: int, name: string, machines: list<array{id: int, label: string}>}>
     */
    private function clientOptions(User $user): array
    {
        $clients = $user->clients()
            ->with(['machines' => fn ($query) => $query->where('user_id', $user->id)->orderBy('id')])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return array_values($clients->map(fn (Client $client): array => [
            'id' => $client->id,
            'name' => $client->name,
            'machines' => $this->machineOptions($client),
        ])->all());
    }

    /**
     * Sorted by what the owner reads, so a machine without a name does not jump to the top.
     *
     * @return list<array{id: int, label: string}>
     */
    private function machineOptions(Client $client): array
    {
        $options = array_map(fn (Machine $machine): array => $this->machineOption($machine), $client->machines->all());
        usort($options, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * @return array{id: int, label: string}
     */
    private function machineOption(Machine $machine): array
    {
        return ['id' => $machine->id, 'label' => $machine->name ?? $machine->model ?? $machine->serial ?? 'Machine '.$machine->id];
    }
}
