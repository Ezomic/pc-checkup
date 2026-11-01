<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Page size
    |--------------------------------------------------------------------------
    |
    | The default number of records per page for paginated index screens. Kept
    | in config rather than inline in each controller so an app can tune it in
    | one place, and so a list never ships accidentally unbounded.
    |
    */

    'per_page' => (int) env('PAGINATION_PER_PAGE', 15),

];
