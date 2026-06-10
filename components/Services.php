<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Flash;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Service;
use Validator;

class Services extends POSMallComponent
{
    public const CARRIER_SKU_PREFIX = 'POSMALL-SERVICE-CARRIER-';

    public $services;

    public $service;

    public $cartPage;

    public $servicePage;

    public $currency;

    public function componentDetails()
    {
        return [
            'name' => 'kodzero.posmall::lang.common.services',
            'description' => 'Standalone POSMall services listing and add-to-cart flow.',
        ];
    }

    public function defineProperties()
    {
        return [
            'service' => [
                'title' => 'kodzero.posmall::lang.common.service',
                'default' => null,
                'type' => 'string',
            ],
            'cartPage' => [
                'title' => 'kodzero.posmall::lang.common.cart',
                'default' => GeneralSettings::get('cart_page', 'posmall-cart'),
                'type' => 'string',
            ],
            'servicePage' => [
                'title' => 'kodzero.posmall::lang.common.services',
                'default' => 'posmall-service',
                'type' => 'string',
            ],
            'perPage' => [
                'title' => 'kodzero.posmall::lang.components.products.properties.perPage.title',
                'default' => 12,
                'type' => 'string',
            ],
        ];
    }

    public function onRun()
    {
        $this->setVar('cartPage', $this->property('cartPage'));
        $this->setVar('servicePage', $this->property('servicePage'));
        $this->setVar('currency', Currency::activeCurrency());

        $code = trim((string)$this->property('service'));
        if ($code === '') {
            $this->setVar('services', $this->queryServices()->paginate($this->perPage()));

            return;
        }

        try {
            $service = $this->queryServices()->where('code', $code)->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return $this->controller->run('404');
        }

        $this->setVar('service', $service);
        $this->page->title = $service->meta_title ?: $service->name;
        $this->page->meta_description = $this->serviceMetaDescription($service);
        $this->page->meta_keywords = (string)$service->meta_keywords;
    }

    public function onAddServiceToCart()
    {
        $serviceId = (int)post('service_id');
        $optionIds = collect(post('service_options', []))
            ->filter()
            ->map(fn ($id) => (int)$id)
            ->unique()
            ->values();

        $validation = Validator::make([
            'service_id' => $serviceId,
            'service_options' => $optionIds->all(),
        ], [
            'service_id' => 'required|integer|exists:kodzero_posmall_services,id',
            'service_options' => 'required|array|min:1',
            'service_options.*' => 'integer|exists:kodzero_posmall_service_options,id',
        ], [
            'service_options.required' => trans('kodzero.posmall::frontend.services.select_required'),
            'service_options.min' => trans('kodzero.posmall::frontend.services.select_required'),
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $service = Service::with('options')->findOrFail($serviceId);
        $validOptionIds = $service->options->pluck('id');

        if ($optionIds->diff($validOptionIds)->isNotEmpty()) {
            throw new ValidationException([
                'service_options' => trans('kodzero.posmall::frontend.services.invalid_option'),
            ]);
        }

        $product = Product::where('user_defined_id', $this->carrierSku($service))->first();
        if (! $product) {
            throw new ValidationException([
                'service_id' => trans('kodzero.posmall::frontend.services.not_configured'),
            ]);
        }

        $cart = Cart::byUser(Auth::user());

        try {
            $cart->addProduct($product, 1, null, Collection::make(), $optionIds->all());
        } catch (OutOfStockException $e) {
            throw new ValidationException(['service_id' => trans('kodzero.posmall::lang.common.stock_limit_reached')]);
        }

        $cart->load('products');
        Flash::success(trans('kodzero.posmall::frontend.cart.added'));

        return [
            'service' => $service->only(['id', 'name', 'code']),
            'new_items_count' => optional($cart->products)->count() ?? 0,
            'new_items_quantity' => optional($cart->products)->sum('quantity') ?? 0,
            'added' => true,
        ];
    }

    public static function carrierSku(Service $service): string
    {
        return self::CARRIER_SKU_PREFIX . strtoupper(str_replace('-', '_', (string)$service->code));
    }

    protected function queryServices()
    {
        return Service::with(['images', 'options.prices.currency'])
            ->storefrontAvailable()
            ->orderBy('name')
            ->orderBy('id');
    }

    protected function perPage(): int
    {
        return max(1, min(48, (int)$this->property('perPage', 12)));
    }

    protected function serviceMetaDescription(Service $service): string
    {
        $description = $service->meta_description ?: $service->description;
        $text = html_entity_decode(strip_tags((string)$description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
