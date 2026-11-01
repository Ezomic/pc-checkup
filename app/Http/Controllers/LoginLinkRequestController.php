<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\IssueLoginLink;
use App\Http\Requests\Auth\RequestLoginLinkRequest;
use Illuminate\Http\RedirectResponse;

final class LoginLinkRequestController extends Controller
{
    public function __invoke(RequestLoginLinkRequest $request, IssueLoginLink $action): RedirectResponse
    {
        $email = $request->email();

        // After the response is sent, so a known address (database writes, a mail transport) cannot
        // be told from an unknown one by how long the answer takes.
        defer(fn () => $action->handle($email));

        return to_route('login')->with('status', 'If that address has an account, a link is on its way.');
    }
}
