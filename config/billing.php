<?php

return [
    // Shown on the billing + help pages so customers know how to reach you while
    // billing is manual (bank transfer). Set BILLING_SUPPORT_EMAIL per deployment.
    // Left unset by default so we never show a fake placeholder address — the UI
    // simply hides the contact line until a real address is configured.
    'support_email' => env('BILLING_SUPPORT_EMAIL'),

    'bank_transfer_instructions' => env(
        'BILLING_BANK_INSTRUCTIONS',
        "To upgrade or renew, make a bank transfer for your plan amount and email us the receipt with your workspace name. We'll activate your plan within one business day."
    ),

    // The online card-payment gateway, once connected (e.g. 'paddle', 'stripe',
    // 'safepay'). Unset = no self-serve card checkout yet (manual/bank transfer).
    // We never store card data regardless — checkout is always gateway-hosted.
    'gateway' => env('BILLING_GATEWAY'),
];
