<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Workflow mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the app authenticates against Thijssensoftware ID (SSO)
    | instead of local accounts, local registration is disabled, and the portal
    | switcher is shown in the app shell. Leave it off for standalone apps.
    |
    */

    'enabled' => (bool) env('WORKFLOW_MODE', false),

];
