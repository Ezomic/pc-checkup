<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\PriceItems\CreatePriceItem;
use App\Actions\PriceItems\DeletePriceItem;
use App\Actions\PriceItems\UpdatePriceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceItems\StorePriceItemRequest;
use App\Http\Requests\PriceItems\UpdatePriceItemRequest;
use App\Models\PriceItem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PriceItemController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('settings/PriceList', [
            'items' => $user->priceItems()->get(['id', 'name', 'price_cents', 'unit']),
        ]);
    }

    public function store(StorePriceItemRequest $request, CreatePriceItem $action): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $action->handle($user, $request->priceItem());

        return back();
    }

    public function update(UpdatePriceItemRequest $request, PriceItem $priceItem, UpdatePriceItem $action): RedirectResponse
    {
        $this->authorize('update', $priceItem);

        $action->handle($priceItem, $request->priceItem());

        return back();
    }

    public function destroy(PriceItem $priceItem, DeletePriceItem $action): RedirectResponse
    {
        $this->authorize('delete', $priceItem);

        $action->handle($priceItem);

        return back();
    }
}
