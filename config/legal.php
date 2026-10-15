<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who publishes Lead2Crm
    |--------------------------------------------------------------------------
    |
    | These fill in the legal pages, the footer and the "Get help" link. Set
    | them in .env before launch: a privacy policy without a named controller
    | and a contact address does not meet GDPR Article 13.
    |
    */

    'entity' => env('LEGAL_ENTITY', 'Lead2Crm'),

    'address' => env('LEGAL_ADDRESS'),

    'support_email' => env('SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),

    // The courts and law that govern the terms, e.g. "England and Wales".
    'governing_law' => env('LEGAL_GOVERNING_LAW'),

    // Bump this whenever the wording of any legal page changes.
    'updated' => '2026-10-15',

];
