<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Clients\RecordShareView;
use App\Actions\Machines\BuildBeforeAfter;
use App\Actions\Machines\ResolveReportPair;
use App\Models\Client;
use App\Models\Machine;
use App\Services\Share\SharedMachineView;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SharedClientController extends Controller
{
    private const string TOKEN_PATTERN = '/\A[A-Za-z0-9]{16,64}\z/';

    private const string ID_PATTERN = '/\A[1-9][0-9]{0,14}\z/';

    public function show(Request $request, string $token, RecordShareView $record, SharedMachineView $view): Response
    {
        $client = $this->client($request, $token, $record);

        return Inertia::render('share/Show', [
            'token' => $token,
            'client' => ['name' => $client->name],
            'machines' => $client->machines()
                ->with('newestReport')
                ->orderBy('id')
                ->get()
                ->map(fn (Machine $machine): array => $view->card($machine))
                ->all(),
        ]);
    }

    public function machine(Request $request, string $token, string $machine, RecordShareView $record, SharedMachineView $view): Response
    {
        $client = $this->client($request, $token, $record);

        abort_unless(preg_match(self::ID_PATTERN, $machine) === 1, 404);

        $shared = $client->machines()->with('newestReport')->whereKey((int) $machine)->firstOrFail();

        return Inertia::render('share/Machine', [
            'token' => $token,
            'client' => ['name' => $client->name],
            'machine' => $view->identity($shared),
            'report' => $view->report($shared),
            'canCompare' => $shared->reports()->count() >= 2,
        ]);
    }

    public function beforeAfter(Request $request, string $token, string $machine, RecordShareView $record, SharedMachineView $view, ResolveReportPair $pair, BuildBeforeAfter $build): Response
    {
        $client = $this->client($request, $token, $record);

        abort_unless(preg_match(self::ID_PATTERN, $machine) === 1, 404);

        $shared = $client->machines()->whereKey((int) $machine)->firstOrFail();
        $reports = $pair->latestTwo($shared);

        return Inertia::render('share/BeforeAfter', [
            'token' => $token,
            'client' => ['name' => $client->name],
            'machine' => $view->identity($shared),
            'comparison' => $build->handle($reports[0], $reports[1], true),
        ]);
    }

    private function client(Request $request, string $token, RecordShareView $record): Client
    {
        abort_unless(preg_match(self::TOKEN_PATTERN, $token) === 1, 404);

        $client = Client::query()->where('share_token', $token)->firstOrFail();

        if ($request->isMethod('GET')) {
            $record->handle($client);
        }

        return $client;
    }
}
