<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Laravel's /up answers 200 without touching the database, and a failing /up is the only
 * thing that makes app-deploy switch back to the previous release. So a release that cannot
 * reach its database would stay live. An exception here turns /up into a 500. WEB-34.
 */
final class CheckDatabaseOnHealthCheck
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->getPdo();
    }
}
