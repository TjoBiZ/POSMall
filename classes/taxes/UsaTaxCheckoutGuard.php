<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Service;

class UsaTaxCheckoutGuard
{
    public function validate(Cart $cart): void
    {
        $stateCode = $this->stateCode($cart);

        if (!$stateCode) {
            return;
        }

        $errors = $this->blockedItems($cart, $stateCode);

        if (!$errors) {
            return;
        }

        throw new ValidationException([
            'cart' => implode(' ', $errors),
        ]);
    }

    protected function blockedItems(Cart $cart, string $stateCode): array
    {
        $resolver = app(UsaTaxResolver::class);
        $errors = [];

        foreach ($cart->products as $cartProduct) {
            $product = $cartProduct->data;

            if ($product instanceof Product && !$resolver->canSellToState($product, $stateCode)) {
                $errors[] = $this->unsupportedRegionMessage((string)$product->name);
                continue;
            }

            foreach ($this->restrictedCategories($product) as $category) {
                if (!$resolver->canSellToState($category, $stateCode)) {
                    $errors[] = $this->unsupportedRegionMessage((string)$product->name);
                    break;
                }
            }

            foreach ($cartProduct->service_options as $option) {
                $service = $option->service;

                if ($service instanceof Service && !$resolver->canSellToState($service, $stateCode)) {
                    $errors[] = $this->unsupportedRegionMessage((string)$service->name);
                }
            }
        }

        return array_values(array_unique($errors));
    }

    protected function restrictedCategories(?Product $product): array
    {
        if (!$product) {
            return [];
        }

        return $product->categories()
            ->with('taxes')
            ->where('sell_only_to_tax_states', true)
            ->get()
            ->sortByDesc(fn (Category $category) => $category->parent_id ? 1 : 0)
            ->all();
    }

    protected function stateCode(Cart $cart): ?string
    {
        $address = $cart->shipping_address ?: $cart->billing_address;
        $state = optional($address)->state;
        $code = $state->code ?? $state->abbreviation ?? null;

        if (!$code) {
            return null;
        }

        $code = strtoupper(trim((string)$code));

        return preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    protected function unsupportedRegionMessage(string $name): string
    {
        $message = trim((string)GeneralSettings::get(
            'usa_unsupported_region_message',
            'Sorry, we do not sell ":name" in your state. Please contact support if you believe this is a mistake.'
        ));

        if ($message === '') {
            $message = 'Sorry, we do not sell ":name" in your state. Please contact support if you believe this is a mistake.';
        }

        return str_replace(':name', $name, $message);
    }
}
