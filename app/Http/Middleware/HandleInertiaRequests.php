<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Portal\IdPortalClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $workflowEnabled = Config::boolean('workflow.enabled');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'workflow' => [
                'enabled' => $workflowEnabled,
            ],
            'portalApps' => fn (): array => $this->portalApps($request, $workflowEnabled),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The apps the current user may switch to, for the portal switcher. Only
     * resolved in workflow mode with an authenticated user.
     *
     * @return list<array{slug: string, name: string, initials: string, accent: string|null, launch_url: string, current: bool}>
     */
    private function portalApps(Request $request, bool $workflowEnabled): array
    {
        $user = $request->user();

        if (! $workflowEnabled || ! $user instanceof User) {
            return [];
        }

        return app(IdPortalClient::class)->appsFor($user);
    }
}
