<?php

return [
    /*
    | ECPOS external ordering API. The key is a secret: it lives in .env and is only ever used from the server.
    */
    'base_url' => rtrim(env('ECPOS_BASE_URL', 'https://xv2.eljin.org/api/external/orders'), '/'),
    'api_key' => env('ECPOS_API_KEY'),
    'timeout' => (int) env('ECPOS_TIMEOUT', 30),

    // When true, posting a TR also sends it to ECPOS. Off by default: turn it on only once ECPOS is ready to receive orders.
    'send_orders' => (bool) env('ECPOS_SEND_ORDERS', false),

    // Item groups (ECPOS "itemgroup", e.g. "BW REJECTS") that should NOT be imported. Comma separated, case-insensitive.
    'skip_groups' => array_values(array_filter(array_map('trim', explode(',', (string) env('ECPOS_SKIP_GROUPS', ''))))),
];
