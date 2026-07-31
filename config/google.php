<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed Google Workspace hosted domains
    |--------------------------------------------------------------------------
    |
    | Phase 6 Google sign-in / linkGoogleIdentity() refuses tokens whose `hd`
    | claim is missing or not in this list. Comma-separated in
    | GOOGLE_WORKSPACE_ALLOWED_DOMAINS.
    |
    */

    'allowed_hosted_domains' => array_values(array_filter(array_map(
        static fn (string $domain): string => strtolower(trim($domain)),
        explode(',', (string) env('GOOGLE_WORKSPACE_ALLOWED_DOMAINS', '')),
    ))),

];
