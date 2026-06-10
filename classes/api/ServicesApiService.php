<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Components\Services as ServicesComponent;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\ServiceOption;
use Validator;

class ServicesApiService
{
    public function __construct(
        private readonly CartResource $carts,
        private readonly CatalogImageOptimizer $images
    ) {
    }

    public function list(array $input, ?CommerceContext $context = null): array
    {
        $perPage = max(1, min(48, (int)($input['per_page'] ?? 12)));
        $page = max(1, (int)($input['page'] ?? 1));
        $paginator = $this->serviceQuery($context)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->getCollection()->map(fn (Service $service) => $this->service($service))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function detail(string $code, ?CommerceContext $context = null): array
    {
        return ['service' => $this->service(
            $this->serviceQuery($context)
                ->where('code', $code)
                ->firstOrFail()
        )];
    }

    public function addToCart(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'service_id' => 'required|integer|exists:kodzero_posmall_services,id',
            'service_option_ids' => 'required|array|min:1',
            'service_option_ids.*' => 'integer|exists:kodzero_posmall_service_options,id',
        ]);

        if ($context && !$context->token->allowsCustomerId((int)$input['customer_id'])) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }

        $service = $this->serviceQuery($context)->findOrFail((int)$input['service_id']);
        $optionIds = collect($input['service_option_ids'])->map(fn ($id) => (int)$id)->unique()->values();
        $validOptionIds = $service->options->pluck('id')->map(fn ($id) => (int)$id);

        if ($optionIds->diff($validOptionIds)->isNotEmpty()) {
            throw new ValidationException(['service_option_ids' => trans('kodzero.posmall::frontend.services.invalid_option')]);
        }

        $product = Product::where('user_defined_id', ServicesComponent::carrierSku($service))->first();
        if (!$product) {
            throw new ValidationException(['service_id' => trans('kodzero.posmall::frontend.services.not_configured')]);
        }

        $cart = Cart::orderBy('created_at', 'DESC')->firstOrCreate(['customer_id' => (int)$input['customer_id']]);

        try {
            $cart->addProduct($product, 1, null, Collection::make(), $optionIds->all());
        } catch (OutOfStockException) {
            throw new ValidationException(['service_id' => trans('kodzero.posmall::lang.common.stock_limit_reached')]);
        }

        $cart->refresh();

        return [
            'service' => $service->only(['id', 'name', 'code']),
            'cart' => $this->carts->cart($cart),
        ];
    }

    private function service(Service $service): array
    {
        return [
            'id' => (int)$service->id,
            'name' => (string)$service->name,
            'code' => (string)$service->code,
            'description' => (string)$service->description,
            'meta' => [
                'title' => (string)$service->meta_title,
                'description' => (string)$service->meta_description,
                'keywords' => (string)$service->meta_keywords,
            ],
            'context' => [
                'vendor' => $service->vendor ? [
                    'id' => (int)$service->vendor->id,
                    'name' => (string)$service->vendor->name,
                    'slug' => (string)$service->vendor->slug,
                ] : null,
                'channel' => $service->channel ? [
                    'id' => (int)$service->channel->id,
                    'name' => (string)$service->channel->name,
                    'slug' => (string)$service->channel->slug,
                    'type' => (string)$service->channel->type,
                ] : null,
            ],
            'images' => $service->images->map(
                fn ($image) => $this->images->imageSources($image, (string)$service->name, CatalogImageOptimizer::PROFILE_SERVICE)
            )->filter()->values()->all(),
            'options' => $service->options->map(fn (ServiceOption $option) => [
                'id' => (int)$option->id,
                'name' => (string)$option->name,
                'description' => (string)$option->description,
                'price' => [
                    'currency' => Currency::activeCurrency()->code,
                    'integer' => (int)$option->price()->integer,
                    'decimal' => (float)$option->price()->decimal,
                    'formatted' => (string)$option->price()->string,
                ],
            ])->values()->all(),
        ];
    }

    private function serviceQuery(?CommerceContext $context)
    {
        $query = Service::with(['images', 'options.prices.currency', 'vendor', 'channel'])
            ->storefrontAvailable();

        if ($context?->vendorExplicit) {
            $query->where(function ($query) use ($context): void {
                $query
                    ->whereNull('vendor_id')
                    ->orWhere('vendor_id', optional($context->vendor)->id);
            });
        }

        if ($context?->channelExplicit) {
            $query->where(function ($query) use ($context): void {
                $query
                    ->whereNull('channel_id')
                    ->orWhere('channel_id', optional($context->channel)->id);
            });
        }

        return $query;
    }

    private function validate(array $input, array $rules): void
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
