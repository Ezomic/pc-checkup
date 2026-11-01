<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| The template has no API of its own. This one route exists so the tokens
| minted in Settings authenticate against something real, which is what makes
| the feature verifiable end to end rather than a UI over an unused table. An
| app builds its actual API out from here.
*/

Route::middleware('auth:sanctum')->get('user', function (Request $request) {
    return $request->user()?->only(['id', 'name', 'email']);
})->name('api.user');
