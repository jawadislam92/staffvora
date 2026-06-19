<?php

return [
    // Shown on the billing page so customers know how to pay while billing is
    // manual (bank transfer). Override per deployment via env.
    'support_email' => env('BILLING_SUPPORT_EMAIL', 'billing@example.com'),

    'bank_transfer_instructions' => env(
        'BILLING_BANK_INSTRUCTIONS',
        "To upgrade or renew, make a bank transfer for your plan amount and email us the receipt with your workspace name. We'll activate your plan within one business day."
    ),
];
