<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale in-progress threshold (days)
    |--------------------------------------------------------------------------
    |
    | An in-progress attempt with no activity for this many days needs
    | attention (alongside blocked attempts).
    |
    */

    'stale_days' => (int) env('TEACHER_DASHBOARD_STALE_DAYS', 7),

];
