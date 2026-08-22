<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment Gateway Manager session — an admin-configured row in
 * `payment_gateways`. Named *Config, not PaymentGateway, so it never
 * collides with App\Contracts\PaymentGateway (the driver interface every
 * consumer type-hints) -- a row here describes ONE gateway's config; a
 * PaymentGateway instance is the thing that actually talks to it.
 */
class PaymentGatewayConfig extends Model
{
    use HasFactory;

    protected $table = 'payment_gateways';

    protected $fillable = [
        'name',
        'driver',
        'credentials',
        'mode',
        'is_active',
        'priority',
    ];

    protected $casts = [
        // Laravel's Crypt-backed cast -- decrypts on read, encrypts on
        // write. Never render $credentials (or this cast's decrypted
        // values) directly in a view/Livewire property; use
        // maskedCredentialSummary() below instead, same discipline
        // RazorpayPaymentDriver::maskedPublicIdentifier() already applies.
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * A safe-to-display summary for the admin list -- every credential
     * value masked to its last 4 characters (short values masked
     * entirely), same convention as RazorpayPaymentDriver::maskedPublicIdentifier()
     * and PaymentAccount::getMaskedAccountNumberAttribute(). The raw
     * decrypted array itself never leaves this method.
     */
    public function maskedCredentialSummary(): string
    {
        $creds = $this->credentials ?? [];

        if (empty($creds)) {
            return '— no credentials saved —';
        }

        return collect($creds)
            ->map(function ($value, $key) {
                $value = (string) $value;
                $last4 = substr($value, -4);
                $masked = strlen($value) > 4
                    ? str_repeat('•', min(strlen($value) - 4, 8)).$last4
                    : str_repeat('•', max(strlen($value), 4));

                return "{$key}: {$masked}";
            })
            ->implode('  ·  ');
    }
}
