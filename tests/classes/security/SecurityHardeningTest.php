<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Security;

use Illuminate\Http\Request;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\RateLimiter;
use KodZero\POSMall\Classes\Taxes\UsaAddressZipSuggester;
use KodZero\POSMall\Classes\Traits\AddressZipSuggestions;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\CategoryFilter\RangeFilter;
use KodZero\POSMall\Classes\Downloads\VirtualProductFileDownload;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;
use KodZero\POSMall\Classes\Payments\DefaultPaymentGateway;
use KodZero\POSMall\Classes\Payments\PaymentGateway;
use KodZero\POSMall\Classes\Payments\PaymentProvider;
use KodZero\POSMall\Classes\Payments\PaymentResult;
use KodZero\POSMall\Classes\Payments\Stripe;
use KodZero\POSMall\Classes\Payments\StripeHostedCheckout;
use KodZero\POSMall\Classes\Utils\DefaultMoney;
use KodZero\POSMall\Classes\Utils\Money;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\CustomFieldOption;
use KodZero\POSMall\Models\CustomFieldValue;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentGatewaySettings;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyValue;
use Event;
use ReflectionClass;
use RuntimeException;
use October\Rain\Events\Dispatcher;
use stdClass;
use Session;

class SecurityHardeningTest extends \TestCase
{
    public function test_payment_gateway_uses_high_entropy_return_token(): void
    {
        $gateway = new DefaultPaymentGateway();

        $this->setProtected($gateway, 'provider', new class extends PaymentProvider {
            public function name(): string
            {
                return 'Test';
            }

            public function identifier(): string
            {
                return 'test';
            }

            public function settings(): array
            {
                return [];
            }

            public function validate(): bool
            {
                return true;
            }

            public function process(PaymentResult $result): PaymentResult
            {
                return $result;
            }
        });

        $gateway->process((new ReflectionClass(Order::class))->newInstanceWithoutConstructor());

        $token = Session::get('posmall.payment.id');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_stripe_token_validation_rejects_ascii_gap_characters(): void
    {
        $this->expectException(ValidationException::class);

        (new Stripe(null, ['token' => 'tok_' . str_repeat('a', 23) . '_']))->validate();
    }

    public function test_payment_gateway_settings_read_legacy_plaintext_and_encrypted_secrets(): void
    {
        $gateway = new DefaultPaymentGateway();
        $gateway->registerProvider(new Stripe());
        app()->instance(PaymentGateway::class, $gateway);

        $secret = 'posmall_test_secret_' . bin2hex(random_bytes(8));
        $encrypted = encrypt($secret);

        $settings = new PaymentGatewaySettings();
        $settings->setRawAttributes(['stripe_api_key' => $secret], true);
        $this->assertSame($secret, $settings->stripe_api_key);

        $settings = new PaymentGatewaySettings();
        $settings->setRawAttributes(['stripe_api_key' => $encrypted], true);
        $this->assertSame($secret, $settings->stripe_api_key);

        $this->assertSame($secret, PaymentGatewaySettings::normalizeSecret($secret));
        $this->assertSame($secret, PaymentGatewaySettings::normalizeSecret($encrypted));
        $this->assertSame('', PaymentGatewaySettings::normalizeSecret(['not' => 'scalar']));
    }

    public function test_public_storefront_cache_uses_configured_default_cache_store(): void
    {
        $originalDefault = config('cache.default');
        $this->setStaticProtected(PublicStorefrontCache::class, 'storeName', null);

        try {
            config(['cache.default' => 'array']);

            $repository = $this->callProtected(new PublicStorefrontCache(), 'cacheStore');

            $this->assertInstanceOf(ArrayStore::class, $repository->getStore());
        } finally {
            config(['cache.default' => $originalDefault]);
            $this->setStaticProtected(PublicStorefrontCache::class, 'storeName', null);
        }
    }

    public function test_public_storefront_cache_key_can_be_extended_by_listener(): void
    {
        $this->withIsolatedEvents(function (): void {
            $cache = new PublicStorefrontCache();
            $request = Request::create('https://example.test/posmall/catalog?sort=name', 'GET');

            $baseKey = $this->callProtected($cache, 'cacheKey', [$request]);

            Event::listen(PublicStorefrontCache::EVENT_EXTEND_KEY_PARTS, function (array &$parts, Request $request): void {
                $parts[] = 'pro-test-price-list';
            });

            $extendedKey = $this->callProtected($cache, 'cacheKey', [$request]);

            $this->assertIsString($baseKey);
            $this->assertIsString($extendedKey);
            $this->assertNotSame($baseKey, $extendedKey);
        });
    }

    public function test_public_storefront_cache_key_extension_failure_bypasses_cache(): void
    {
        $this->withIsolatedEvents(function (): void {
            Event::listen(PublicStorefrontCache::EVENT_EXTEND_KEY_PARTS, function (): void {
                throw new RuntimeException('Broken extension cache listener');
            });

            $key = $this->callProtected(
                new PublicStorefrontCache(),
                'cacheKey',
                [Request::create('https://example.test/posmall/catalog', 'GET')]
            );

            $this->assertNull($key);
        });
    }

    public function test_public_storefront_cache_eligibility_can_be_extended_by_listener(): void
    {
        $this->withIsolatedEvents(function (): void {
            $cache = new PublicStorefrontCache();
            $request = Request::create('https://example.test/posmall/catalog', 'GET');

            $this->assertTrue($this->callProtected($cache, 'isEligibleRequest', [$request]));

            Event::listen(PublicStorefrontCache::EVENT_EXTEND_ELIGIBILITY, function (bool &$eligible, Request $request): void {
                $eligible = false;
            });

            $this->assertFalse($this->callProtected($cache, 'isEligibleRequest', [$request]));
        });
    }

    public function test_public_storefront_cache_eligibility_listener_cannot_enable_personal_requests(): void
    {
        $this->withIsolatedEvents(function (): void {
            Event::listen(PublicStorefrontCache::EVENT_EXTEND_ELIGIBILITY, function (bool &$eligible, Request $request): void {
                $eligible = true;
            });

            $request = Request::create('https://example.test/posmall/catalog', 'GET');
            $request->headers->set('cookie', config('session.cookie', 'october_session') . '=personal-session');

            $eligible = $this->callProtected(new PublicStorefrontCache(), 'isEligibleRequest', [$request]);

            $this->assertFalse($eligible);
        });
    }

    public function test_public_storefront_cache_eligibility_extension_failure_bypasses_cache(): void
    {
        $this->withIsolatedEvents(function (): void {
            Event::listen(PublicStorefrontCache::EVENT_EXTEND_ELIGIBILITY, function (): void {
                throw new RuntimeException('Broken extension eligibility listener');
            });

            $eligible = $this->callProtected(
                new PublicStorefrontCache(),
                'isEligibleRequest',
                [Request::create('https://example.test/posmall/catalog', 'GET')]
            );

            $this->assertFalse($eligible);
        });
    }

    public function test_virtual_download_filename_removes_path_characters(): void
    {
        $download = new VirtualProductFileDownload();
        $filename = $this->callProtected($download, 'downloadFilename', ['../../evil/name', 'pdf']);

        $this->assertStringNotContainsString('/', $filename);
        $this->assertStringNotContainsString('\\', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }

    public function test_stripe_hosted_checkout_uses_order_cents_once(): void
    {
        $provider = new StripeHostedCheckout($this->fakeOrder(16199));
        $lineItems = $this->callProtected($provider, 'buildLineItems');

        $this->assertSame(16199, $lineItems[0]['price_data']['unit_amount']);
    }

    public function test_stripe_hosted_checkout_rejects_mismatched_session_amount(): void
    {
        $provider = new StripeHostedCheckout($this->fakeOrder(16199));
        $session = $this->fakeStripeSession(['amount_total' => 1619900]);

        $this->assertFalse($provider->sessionMatchesOrder($session));
    }

    public function test_range_filter_accepts_decimal_comma_with_thousand_separator(): void
    {
        $filter = new RangeFilter('price', ['1.200,50', '1.400,75']);

        $this->assertTrue($filter->isValid());
        $this->assertSame(1200.50, $filter->values()['min']);
        $this->assertSame(1400.75, $filter->values()['max']);
    }

    public function test_money_format_supports_known_formats_without_twig_execution(): void
    {
        $money = new DefaultMoney();
        app()->instance(Money::class, $money);

        $currency = $this->fakeCurrency('{{ currency.symbol }} {{ price|number_format(2, ".", ",") }}');
        $this->assertSame('$ 1,234.56', $money->format(123456, null, $currency));

        $currency = $this->fakeCurrency('{{ price | number_format(currency.decimals, " ", ",") }} ({{  currency.symbol  }})');
        $this->assertSame('1,234 56 ($)', $money->format(123456, null, $currency));

        $currency = $this->fakeCurrency('<script>alert(1)</script>{{ product.delete() }}{{ currency.symbol }}');
        $result = $money->format(123456, null, $currency);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('delete', $result);
    }

    public function test_color_swatch_escapes_title_and_rejects_invalid_hex(): void
    {
        $property = new Property();
        $property->type = 'color';

        $value = new PropertyValue();
        $value->setRelation('property', $property);
        $value->setRawAttributes([
            'value' => json_encode([
                'hex' => '#fff; background-image: url(xss)',
                'name' => 'Red" onmouseover="alert(1)',
            ]),
        ], true);

        $html = $value->display_value;

        $this->assertStringContainsString('background: transparent', $html);
        $this->assertStringContainsString('title="Red&quot; onmouseover=&quot;alert(1)"', $html);
        $this->assertStringNotContainsString('background-image', $html);
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
    }

    public function test_custom_field_display_value_is_safe_for_raw_cart_templates(): void
    {
        app()->instance(Money::class, new DefaultMoney());

        $field = new CustomField();
        $field->type = 'dropdown';

        $option = new CustomFieldOption();
        $option->name = '<img src=x onerror=alert(1)>';

        $value = new CustomFieldValue();
        $value->setRelation('custom_field', $field);
        $value->setRelation('custom_field_option', $option);

        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $value->display_value);

        $colorField = new CustomField();
        $colorField->type = 'color';
        $colorField->setRelation('custom_field_options', collect([$option]));

        $colorOption = new CustomFieldOption();
        $colorOption->option_value = '#fff; background-image: url(xss)';
        $this->assertSame('transparent', $colorOption->safe_color_value);

        $colorValue = new CustomFieldValue();
        $colorValue->setRelation('custom_field', $colorField);
        $colorValue->setRelation('custom_field_option', $colorOption);

        $html = $colorValue->display_value;

        $this->assertStringContainsString('background: transparent', $html);
        $this->assertStringNotContainsString('background-image', $html);
    }

    public function test_address_fill_does_not_mass_assign_customer_id(): void
    {
        $address = new Address();

        $address->fill([
            'name' => 'Test Address',
            'customer_id' => 123,
            'lines' => '1 Test Street',
            'zip' => '98101',
            'country_id' => 1,
            'city' => 'Seattle',
            'state_id' => 1,
        ]);

        $this->assertNull($address->customer_id);
        $address->customer_id = 456;
        $this->assertSame(456, $address->customer_id);
    }

    public function test_customer_fill_does_not_mass_assign_user_id(): void
    {
        $customer = new Customer();

        $customer->fill([
            'firstname' => 'POSMall',
            'lastname' => 'Customer',
            'is_guest' => false,
            'user_id' => 123,
        ]);

        $this->assertNull($customer->user_id);
        $customer->user_id = 456;
        $this->assertSame(456, $customer->user_id);
    }

    public function test_address_zip_suggestion_endpoint_throttles_without_visible_error(): void
    {
        $request = Request::create('/posmall/address-suggest', 'POST', ['zip' => '98104'], [], [], [
            'REMOTE_ADDR' => '203.0.113.42',
        ]);
        app()->instance('request', $request);

        $rateLimitKey = 'kodzero_posmall.address_zip_suggestions:' . sha1('203.0.113.42');
        RateLimiter::clear($rateLimitKey);

        $suggester = new class {
            public int $calls = 0;

            public function suggest(array $input): array
            {
                $this->calls++;

                return ['suggestions' => [['zip' => '98104']]];
            }
        };
        app()->instance(UsaAddressZipSuggester::class, $suggester);

        $component = new class {
            use AddressZipSuggestions;
        };

        for ($i = 0; $i < 60; $i++) {
            $this->assertSame([['zip' => '98104']], $component->onSuggestAddressZip()['suggestions']);
        }

        $this->assertSame(['suggestions' => []], $component->onSuggestAddressZip());
        $this->assertSame(60, $suggester->calls);

        RateLimiter::clear($rateLimitKey);
    }

    public function test_payment_response_messages_are_redacted_before_logging(): void
    {
        $result = new PaymentResult($this->fakePaymentProvider(), $this->fakeOrder(16199));

        $payload = $this->callProtected($result, 'sanitizePaymentPayload', [[
            'token' => 'tok_123',
            'nested' => [
                'card' => ['last4' => '4242'],
                'safe' => 'keep',
            ],
        ]]);

        $this->assertSame('[redacted]', $payload['token']);
        $this->assertSame('[redacted]', $payload['nested']['card']);
        $this->assertSame('keep', $payload['nested']['safe']);

        $response = new class {
            public function getMessage(): string
            {
                return 'gateway failed token=tok_123 source=src_456 card=4242424242424242';
            }

            public function getCode(): string
            {
                return 'card_declined';
            }
        };

        $safe = $this->callProtected($result, 'safePaymentResponse', [$response]);

        $this->assertSame('card_declined', $safe['code']);
        $this->assertStringContainsString('token=[redacted]', $safe['message']);
        $this->assertStringContainsString('source=[redacted]', $safe['message']);
        $this->assertStringContainsString('card=[redacted]', $safe['message']);
        $this->assertStringNotContainsString('tok_123', $safe['message']);
        $this->assertStringNotContainsString('4242424242424242', $safe['message']);
    }

    private function setProtected(object $object, string $property, $value): void
    {
        $reflection = new ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function setStaticProtected(string $class, string $property, $value): void
    {
        $reflection = new ReflectionClass($class);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private function callProtected(object $object, string $method, array $arguments = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $arguments);
    }

    private function withIsolatedEvents(callable $callback)
    {
        $original = Event::getFacadeRoot();
        $dispatcher = new Dispatcher(app());

        Event::swap($dispatcher);
        app()->instance('events', $dispatcher);

        try {
            return $callback();
        } finally {
            Event::swap($original);
            app()->instance('events', $original);
        }
    }

    private function fakeOrder(int $amount): Order
    {
        $order = (new ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $order->setRawAttributes([
            'id' => 42,
            'order_number' => '000042',
            'payment_hash' => 'hash-42',
            'total_post_taxes' => $amount,
            'currency' => json_encode(['code' => 'USD', 'decimals' => 2]),
        ], true);
        $order->setRelation('payment_method', (object)['payment_provider' => 'stripe-hosted-checkout']);

        return $order;
    }

    private function fakePaymentProvider(): PaymentProvider
    {
        return new class extends PaymentProvider {
            public function name(): string
            {
                return 'Test';
            }

            public function identifier(): string
            {
                return 'test';
            }

            public function settings(): array
            {
                return [];
            }

            public function validate(): bool
            {
                return true;
            }

            public function process(PaymentResult $result): PaymentResult
            {
                return $result;
            }
        };
    }

    private function fakeCurrency(string $format): Currency
    {
        $currency = new Currency();
        $currency->setRawAttributes([
            'code' => 'USD',
            'symbol' => '$',
            'decimals' => 2,
            'format' => $format,
        ], true);

        return $currency;
    }

    private function fakeStripeSession(array $overrides = []): stdClass
    {
        $session = (object)array_merge([
            'id' => 'cs_test_42',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_42',
            'amount_total' => 16199,
            'currency' => 'usd',
            'client_reference_id' => '42',
            'metadata' => (object)[
                'order_id' => '42',
                'payment_hash' => 'hash-42',
            ],
        ], $overrides);

        if (is_array($session->metadata)) {
            $session->metadata = (object)$session->metadata;
        }

        return $session;
    }
}
