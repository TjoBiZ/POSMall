<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
use Model;
use October\Rain\Database\Traits\Encryptable;
use KodZero\POSMall\Classes\Payments\PaymentGateway;

class PaymentGatewaySettings extends Model
{
    use Encryptable {
        getEncryptableValue as protected decryptEncryptableValue;
    }

    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'kodzero_posmall_settings';

    public $settingsFields = '$/kodzero/posmall/models/settings/fields_payment_gateways.yaml';

    protected $encryptable = [];

    /**
     * @var PaymentGateway
     */
    protected $gateway;

    /**
     * @var Collection<PaymentProvider>
     */
    protected $providers;

    public function __construct(array $attributes = [])
    {
        $this->gateway   = app(PaymentGateway::class);
        $this->providers = collect($this->gateway->getProviders());
        $this->providers->each(function ($provider) {
            $this->encryptable = array_merge($this->encryptable, $provider->encryptedSettings());
        });

        parent::__construct($attributes);
    }

    /**
     * Extend the setting form with input fields for each
     * registered plugin.
     */
    public function getFieldConfig()
    {
        if ($this->fieldConfig !== null) {
            return $this->fieldConfig;
        }

        $config                 = parent::getFieldConfig();
        $config->tabs['fields'] = [];

        $this->providers->each(function ($provider) use ($config) {
            $settings = $this->setDefaultTab($provider->settings(), $provider->name());

            $config->tabs['fields'] = array_merge($config->tabs['fields'], $settings);
        });

        return $config;
    }

    public function getEncryptableValue($key)
    {
        try {
            return $this->decryptEncryptableValue($key);
        } catch (DecryptException $e) {
            Log::warning('POSMall payment gateway setting could not be decrypted; returning raw legacy value.', [
                'key' => (string)$key,
            ]);

            return $this->attributes[$key] ?? null;
        }
    }

    public static function secret(string $key): string
    {
        return self::normalizeSecret(static::get($key));
    }

    public static function normalizeSecret($value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        try {
            return trim((string)decrypt($value));
        } catch (DecryptException $e) {
            return $value;
        }
    }

    protected function setDefaultTab(array $settings, $tab)
    {
        return array_map(function ($i) use ($tab) {
            if (! isset($i['tab'])) {
                $i['tab'] = $tab;
            }

            return $i;
        }, $settings);
    }
}
