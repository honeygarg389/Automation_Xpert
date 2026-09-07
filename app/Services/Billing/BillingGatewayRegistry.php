<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Models\PaymentGatewayConfig;

class BillingGatewayRegistry
{
    /** @var array<string, BillingGatewayInterface> */
    private array $gateways = [];

    public function __construct()
    {
        $this->registerFromDatabase();
        $this->registerFromConfig();
    }

    private function registerFromDatabase(): void
    {
        try {
            $configs = PaymentGatewayConfig::where('enabled', true)->get();
        } catch (\Throwable) {
            return;
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';

        foreach ($configs as $row) {
            if (isset($this->gateways[$row->gateway])) {
                continue;
            }
            $creds = $row->getActiveCredentials();
            if ($row->gateway === 'stripe' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['stripe'] = new StripeGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            if ($row->gateway === 'paypal' && ! empty($creds['client_id'] ?? $creds['secret_key'] ?? '')) {
                $clientId = $creds['client_id'] ?? $creds['publishable_key'] ?? '';
                $clientSecret = $creds['client_secret'] ?? $creds['secret_key'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['paypal'] = new PayPalGateway(
                        $clientId,
                        $clientSecret,
                        $row->test_mode,
                        $successUrl,
                        $cancelUrl,
                        $creds['webhook_secret'] ?? $creds['webhook_id'] ?? ''
                    );
                }
            }
            // Razorpay: publishable_key → key_id, secret_key → key_secret.
            if ($row->gateway === 'razorpay' && ! empty($creds['secret_key'] ?? '')) {
                $keyId = $creds['publishable_key'] ?? $creds['key_id'] ?? '';
                $keySecret = $creds['secret_key'] ?? $creds['key_secret'] ?? '';
                if ($keyId !== '' && $keySecret !== '') {
                    $this->gateways['razorpay'] = new RazorpayGateway(
                        $keyId,
                        $keySecret,
                        $creds['webhook_secret'] ?? ''
                    );
                }
            }
            // Cashfree: publishable_key → x-client-id, secret_key → x-client-secret.
            if ($row->gateway === 'cashfree' && ! empty($creds['secret_key'] ?? '')) {
                $clientId = $creds['publishable_key'] ?? $creds['client_id'] ?? '';
                $clientSecret = $creds['secret_key'] ?? $creds['client_secret'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['cashfree'] = new CashfreeGateway(
                        $clientId,
                        $clientSecret,
                        (bool) $row->test_mode,
                        $successUrl
                    );
                }
            }
        }
    }

    private function registerFromConfig(): void
    {
        $config = config('billing.gateways', []);

        if (! isset($this->gateways['stripe']) && ! empty($config['stripe']['enabled']) && ($config['stripe']['secret_key'] ?? '')) {
            $this->gateways['stripe'] = new StripeGateway(
                $config['stripe']['secret_key'] ?? '',
                $config['stripe']['webhook_secret'] ?? '',
                $config['stripe']['success_url'] ?? (rtrim(config('app.url'), '/').'/app/billing?checkout=success'),
                $config['stripe']['cancel_url'] ?? (rtrim(config('app.url'), '/').'/app/pricing?checkout=canceled')
            );
        }

        if (! isset($this->gateways['paypal']) && ! empty($config['paypal']['enabled']) && ($config['paypal']['client_id'] ?? '')) {
            $this->gateways['paypal'] = new PayPalGateway(
                $config['paypal']['client_id'] ?? '',
                $config['paypal']['client_secret'] ?? '',
                (bool) ($config['paypal']['sandbox'] ?? true),
                $config['paypal']['success_url'] ?? '',
                $config['paypal']['cancel_url'] ?? '',
                $config['paypal']['webhook_id'] ?? ''
            );
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';

        if (! isset($this->gateways['razorpay']) && ! empty($config['razorpay']['enabled']) && ($config['razorpay']['key_id'] ?? '')) {
            $this->gateways['razorpay'] = new RazorpayGateway(
                $config['razorpay']['key_id'] ?? '',
                $config['razorpay']['key_secret'] ?? '',
                $config['razorpay']['webhook_secret'] ?? ''
            );
        }

        if (! isset($this->gateways['cashfree']) && ! empty($config['cashfree']['enabled']) && ($config['cashfree']['client_id'] ?? '')) {
            $this->gateways['cashfree'] = new CashfreeGateway(
                $config['cashfree']['client_id'] ?? '',
                $config['cashfree']['client_secret'] ?? '',
                (bool) ($config['cashfree']['sandbox'] ?? true),
                $config['cashfree']['return_url'] ?? $successUrl
            );
        }

    }

    /**
     * @return array<string, BillingGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Only gateways that are configured (credentials present).
     *
     * @return array<string, BillingGatewayInterface>
     */
    public function configured(): array
    {
        return array_filter($this->gateways, fn (BillingGatewayInterface $g) => $g->isConfigured());
    }

    public function get(string $key): ?BillingGatewayInterface
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * @return array<array{key: string, name: string, configured: bool}>
     */
    public function listForFrontend(): array
    {
        $out = [];
        $labels = [
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'razorpay' => 'Razorpay',
            'cashfree' => 'Cashfree',
        ];
        foreach ($labels as $key => $label) {
            $g = $this->gateways[$key] ?? null;
            $out[] = [
                'key' => $key,
                'name' => $g ? $g->name() : $label,
                'configured' => $g ? $g->isConfigured() : false,
            ];
        }

        return $out;
    }
}
