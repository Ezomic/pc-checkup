<?php

declare(strict_types=1);

return [

    /*
    | The owner's self-hosted RustDesk server, filled into the Windows installer
    | when it is downloaded. Leave unset and the installer is not offered.
    */

    'host' => env('RUSTDESK_HOST'),

    'relay' => env('RUSTDESK_RELAY'),

    'key' => env('RUSTDESK_KEY'),

    'link_minutes' => 60,

];
