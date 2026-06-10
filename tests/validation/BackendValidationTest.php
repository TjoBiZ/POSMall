<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Validation;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Session;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Customer\DefaultSignUpHandler;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Components\Cart as CartComponent;
use KodZero\POSMall\Components\Product as ProductComponent;
use KodZero\POSMall\Components\Products as ProductsComponent;
use KodZero\POSMall\Components\QuickCheckout;
use KodZero\POSMall\Components\ShippingMethodSelector;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CategoryReview;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Review;
use KodZero\POSMall\Models\ShippingMethod;
use Validator;

class BackendValidationTest extends \TestCase
{
    public function test_form_field_inventory_maps_high_risk_fields_to_database_types_and_rules(): void
    {
        $inventory = $this->formFieldInventory();

        $this->assertNotEmpty($inventory);
        $this->assertSame('integer', $inventory['signup.billing_country_id']['db_type']);
        $this->assertStringContainsString('bail|required|integer|exists', $inventory['signup.billing_country_id']['rule']);
        $this->assertSame('encoded_hash_id', $inventory['cart.id']['db_type']);
        $this->assertStringContainsString('integer|min:1', $inventory['cart.quantity']['rule']);
        $this->assertStringContainsString('integer|between:1,5', $inventory['review.rating']['rule']);
        $this->assertStringContainsString('numeric|min:0', $inventory['payment_method.fee_percentage']['rule']);
    }

    public function test_integer_exists_rules_reject_malformed_ids_without_database_queries(): void
    {
        $rules = [
            'signup.billing_country_id' => DefaultSignUpHandler::rules()['billing_country_id'],
            'signup.billing_state_id' => DefaultSignUpHandler::rules()['billing_state_id'] ?? 'bail|required|integer',
            'address.country_id' => $this->modelRules(Address::class)['country_id'],
            'address.state_id' => $this->modelRules(Address::class)['state_id'],
            'shipping.id' => 'bail|required|integer|exists:kodzero_posmall_shipping_methods,id',
            'payment.id' => 'bail|required|integer|exists:kodzero_posmall_payment_methods,id',
            'review.product_id' => $this->modelRules(Review::class)['product_id'],
            'category_review.review_id' => $this->modelRules(CategoryReview::class)['review_id'],
        ];

        foreach ($rules as $field => $rule) {
            $payloads = str_contains((string)$rule, 'nullable')
                ? ['{"id":1}', '1.5', ['id' => 1], 'undefined']
                : ['', '{"id":1}', '1.5', ['id' => 1], 'undefined'];

            foreach ($payloads as $payload) {
                $validator = Validator::make(['id' => $payload], ['id' => $rule]);

                $this->assertTrue($validator->fails(), $field . ' accepted malformed ID payload');
            }
        }
    }

    public function test_ajax_handlers_reject_invalid_payloads_before_model_queries(): void
    {
        $this->replacePost(['id' => '{"id":1}']);
        $this->expectValidationOnly(fn () => (new ShippingMethodSelector())->onChangeMethod());

        $this->replacePost(['id' => '']);
        $this->expectValidationOnly(fn () => (new QuickCheckout())->onChangePaymentMethod());

        $this->replacePost(['id' => 'invalid-hash-id', 'quantity' => '-5']);
        $this->expectValidationOnly(fn () => (new CartComponent())->onUpdateQuantity());

        $this->replacePost(['quantity' => '1.5']);
        $this->expectValidationOnly(fn () => (new ProductComponent())->onAddToCart());

        $this->replacePost(['product' => 'invalid-hash-id', 'quantity' => ['nested' => 'value']]);
        $this->expectValidationOnly(fn () => (new ProductsComponent())->onAddToCart());
    }

    public function test_money_quantity_boolean_text_and_discount_rules_reject_invalid_backend_values(): void
    {
        $cases = [
            'currency money fields' => [
                ['rate' => '{"amount":1}', 'decimals' => 'two', 'is_enabled' => 'not-bool'],
                [
                    'rate' => $this->modelRules(Currency::class)['rate'],
                    'decimals' => $this->modelRules(Currency::class)['decimals'],
                    'is_enabled' => $this->modelRules(Currency::class)['is_enabled'],
                ],
            ],
            'payment money and boolean fields' => [
                ['fee_percentage' => '{"amount":1}', 'is_enabled' => 'not-bool'],
                [
                    'fee_percentage' => $this->modelRules(PaymentMethod::class)['fee_percentage'],
                    'is_enabled' => $this->modelRules(PaymentMethod::class)['is_enabled'],
                ],
            ],
            'shipping quantity and boolean fields' => [
                ['guaranteed_delivery_days' => '-1', 'price_includes_tax' => 'not-bool'],
                [
                    'guaranteed_delivery_days' => $this->modelRules(ShippingMethod::class)['guaranteed_delivery_days'],
                    'price_includes_tax' => $this->modelRules(ShippingMethod::class)['price_includes_tax'],
                ],
            ],
            'discount code and money fields' => [
                ['rate' => '150', 'max_number_of_usages' => '{"limit":1}'],
                [
                    'rate' => $this->modelRules(Discount::class)['rate'],
                    'max_number_of_usages' => $this->modelRules(Discount::class)['max_number_of_usages'],
                ],
            ],
            'review rating fields' => [
                ['rating' => '5.5'],
                ['rating' => $this->modelRules(Review::class)['rating']],
            ],
        ];

        foreach ($cases as $label => [$payload, $rules]) {
            $validator = Validator::make($payload, $rules);

            $this->assertTrue($validator->fails(), $label . ' accepted invalid payload');
        }
    }

    public function test_invalid_hash_ids_decode_to_null_and_do_not_become_query_arrays(): void
    {
        $decoder = new class {
            use HashIds;
        };

        $this->assertNull($decoder->decode(['id' => 1]));
        $this->assertNull($decoder->decode(''));
        $this->assertNull($decoder->decode('invalid-hash-id'));
    }

    public function test_cart_fallback_shipping_country_accepts_only_integer_like_values(): void
    {
        $cart = new Cart();
        Session::forget(Cart::FALLBACK_SHIPPING_COUNTRY_KEY);

        $cart->setFallbackShippingCountryId('{"id":1}');
        $this->assertNull(Session::get(Cart::FALLBACK_SHIPPING_COUNTRY_KEY));

        $cart->setFallbackShippingCountryId(['id' => 1]);
        $this->assertNull(Session::get(Cart::FALLBACK_SHIPPING_COUNTRY_KEY));

        $cart->setFallbackShippingCountryId('123');
        $this->assertSame('123', Session::get(Cart::FALLBACK_SHIPPING_COUNTRY_KEY));
    }

    private function expectValidationOnly(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->addToAssertionCount(1);

            return;
        } catch (QueryException $exception) {
            $this->fail('Invalid payload reached the database layer: ' . $exception->getMessage());
        }

        $this->fail('Invalid payload was accepted instead of rejected.');
    }

    private function replacePost(array $payload): void
    {
        request()->setMethod('POST');
        request()->replace($payload);
        request()->request->replace($payload);
    }

    private function formFieldInventory(): array
    {
        return [
            'signup.email' => [
                'db_column' => 'users.email',
                'db_type' => 'string',
                'rule' => 'required|email|non_existing_user',
            ],
            'signup.firstname' => [
                'db_column' => 'kodzero_posmall_customers.firstname',
                'db_type' => 'string',
                'rule' => 'required',
            ],
            'signup.billing_country_id' => [
                'db_column' => 'kodzero_posmall_addresses.country_id',
                'db_type' => 'integer',
                'rule' => DefaultSignUpHandler::rules()['billing_country_id'],
            ],
            'signup.billing_state_id' => [
                'db_column' => 'kodzero_posmall_addresses.state_id',
                'db_type' => 'integer nullable',
                'rule' => DefaultSignUpHandler::rules()['billing_state_id'] ?? 'disabled when use_state=false',
            ],
            'address.country_id' => [
                'db_column' => 'kodzero_posmall_addresses.country_id',
                'db_type' => 'integer',
                'rule' => $this->modelRules(Address::class)['country_id'],
            ],
            'cart.id' => [
                'db_column' => 'kodzero_posmall_cart_products.id',
                'db_type' => 'encoded_hash_id',
                'rule' => 'required + HashIds::decode() must return one integer ID',
            ],
            'cart.quantity' => [
                'db_column' => 'kodzero_posmall_cart_products.quantity',
                'db_type' => 'integer',
                'rule' => 'bail|required|integer|min:1',
            ],
            'shipping.id' => [
                'db_column' => 'kodzero_posmall_shipping_methods.id',
                'db_type' => 'integer',
                'rule' => 'bail|required|integer|exists:kodzero_posmall_shipping_methods,id',
            ],
            'payment.id' => [
                'db_column' => 'kodzero_posmall_payment_methods.id',
                'db_type' => 'integer',
                'rule' => 'bail|required|integer|exists:kodzero_posmall_payment_methods,id',
            ],
            'payment_method.fee_percentage' => [
                'db_column' => 'kodzero_posmall_payment_methods.fee_percentage',
                'db_type' => 'decimal/numeric',
                'rule' => $this->modelRules(PaymentMethod::class)['fee_percentage'],
            ],
            'review.rating' => [
                'db_column' => 'kodzero_posmall_reviews.rating',
                'db_type' => 'integer',
                'rule' => $this->modelRules(Review::class)['rating'],
            ],
            'discount.code' => [
                'db_column' => 'kodzero_posmall_discounts.code',
                'db_type' => 'string nullable unique',
                'rule' => $this->modelRules(Discount::class)['code'],
            ],
        ];
    }

    private function modelRules(string $class): array
    {
        return (new \ReflectionClass($class))->getDefaultProperties()['rules'] ?? [];
    }
}
