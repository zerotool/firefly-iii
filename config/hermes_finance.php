<?php

return [
    'enabled' => env('HERMES_FINANCE_ENABLED', false),

    /*
     * Store hash('sha256', $token), not the bearer token itself.
     */
    'token_hash' => env('HERMES_FINANCE_TOKEN_HASH', ''),

    'ledger_user_id' => (int)env('HERMES_FINANCE_LEDGER_USER_ID', 1),

    /*
     * Empty list allows all sources for local development. Production should
     * set a narrow CIDR or exact IP allowlist for the Hermes host.
     */
    'allowed_cidrs' => array_filter(array_map('trim', explode(',', (string)env('HERMES_FINANCE_ALLOWED_CIDRS', '')))),

    'preview_ttl_minutes' => (int)env('HERMES_FINANCE_PREVIEW_TTL_MINUTES', 15),
    'evidence_text_limit' => (int)env('HERMES_FINANCE_EVIDENCE_TEXT_LIMIT', 5000),
];
