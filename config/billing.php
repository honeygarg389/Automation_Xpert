<?php

return [
    'gateways' => [
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'success_url' => env('STRIPE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('STRIPE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', false),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'sandbox' => env('PAYPAL_SANDBOX', true),
            'success_url' => env('PAYPAL_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],
        // Razorpay (India) — native Subscriptions API, webhook-driven renewals.
        'razorpay' => [
            'enabled' => env('BILLING_RAZORPAY_ENABLED', false),
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
        // Cashfree (India) — native Subscriptions API (JS-SDK authorization), webhook-driven.
        'cashfree' => [
            'enabled' => env('BILLING_CASHFREE_ENABLED', false),
            'client_id' => env('CASHFREE_CLIENT_ID'),
            'client_secret' => env('CASHFREE_CLIENT_SECRET'),
            'sandbox' => env('CASHFREE_SANDBOX', true),
            'return_url' => env('CASHFREE_RETURN_URL', env('APP_URL') . '/app/billing?checkout=success'),
        ],
        // Tap (MENA/GCC) — hosted first charge + merchant-initiated saved-card renewals.
        // Paystack (Africa: Nigeria, Ghana, South Africa, Kenya, Rwanda, etc.)
        // Native Subscriptions API — webhook-driven renewals.
        // Xendit (Southeast Asia: Indonesia, Philippines, Vietnam, Thailand, Malaysia)
        // Native Recurring Plans API — webhook-driven renewals.
        // Paymob (Egypt, Jordan, Pakistan, Morocco, Saudi Arabia, UAE)
        // MIT save-card pattern — merchant-initiated recurring via scheduler.
        // MyFatoorah (Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar, Jordan)
        // MIT save-token pattern — merchant-initiated recurring via scheduler.
        // Mollie (Europe: Netherlands, Belgium, Germany, France, and more)
        // Native Customers + Subscriptions API — webhook-driven renewals.
        // Square (US, Canada, UK, Australia, Ireland, France, Spain, Japan)
        // Native Catalog + Subscriptions API (invoice-billed) — webhook-driven renewals.
        // Mercado Pago (Latin America: Brazil, Argentina, Mexico, Chile, Colombia, Peru, Uruguay)
        // Native Preapproval (subscriptions) API — webhook-driven renewals.
    ],
];
