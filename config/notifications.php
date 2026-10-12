<?php

return [

    /*
    | Who is emailed about orders (comma separated in .env). Empty = nobody is emailed.
    */
    'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOTIFY_ADMIN_EMAILS', ''))))),

    /* Email the admins every time a store posts an order. */
    'order_posted' => (bool) env('NOTIFY_ORDER_POSTED', true),

    /* Email the admins when an order could not be sent to ECPOS (after the automatic retries). */
    'ecpos_failed' => (bool) env('NOTIFY_ECPOS_FAILED', true),

    /* The end-of-day summary: who ordered and who didn't. Each area manager gets one for their stores. */
    'daily_summary' => (bool) env('NOTIFY_DAILY_SUMMARY', true),

    /* ...and the admins on the list above also get one covering every store. */
    'daily_summary_admins' => (bool) env('NOTIFY_SUMMARY_ADMINS', true),

    /* When it is sent (24-hour, in the business time zone). The standard order cutoff is 16:00, so the default is just after it. */
    'summary_time' => env('NOTIFY_SUMMARY_TIME', '17:00'),
];
