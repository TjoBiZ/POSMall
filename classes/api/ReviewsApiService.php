<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use DB;
use Illuminate\Auth\Access\AuthorizationException;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\CategoryReview;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Review;
use KodZero\POSMall\Models\ReviewSettings;
use Validator;

class ReviewsApiService
{
    public function list(string $productSlug, array $input): array
    {
        $product = $this->product($productSlug);
        $perPage = max(1, min(50, (int)($input['per_page'] ?? 10)));
        $page = max(1, (int)($input['page'] ?? 1));
        $paginator = Review::with(['category_reviews.review_category', 'customer'])
            ->where('product_id', $product->id)
            ->whereNotNull('approved_at')
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'product' => [
                'id' => $product->prefixed_id,
                'slug' => (string)$product->slug,
                'rating' => (float)$product->reviews_rating,
            ],
            'reviews' => $paginator->getCollection()->map(fn (Review $review) => $this->review($review))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function create(string $productSlug, array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'nullable|integer|exists:kodzero_posmall_customers,id',
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:500',
            'pros' => 'nullable|array',
            'cons' => 'nullable|array',
            'category_ratings' => 'nullable|array',
        ]);

        if (!empty($input['customer_id']) && $context && !$context->token->allowsCustomerId((int)$input['customer_id'])) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }

        $product = $this->product($productSlug);
        $moderated = (bool)ReviewSettings::get('moderated');

        $review = DB::transaction(function () use ($product, $input, $moderated) {
            $review = new Review();
            $review->rating = (int)$input['rating'];
            $review->title = trim((string)($input['title'] ?? ''));
            $review->description = trim((string)($input['description'] ?? ''));
            $review->pros = $this->lines($input['pros'] ?? []);
            $review->cons = $this->lines($input['cons'] ?? []);
            $review->product_id = $product->id;
            $review->customer_id = !empty($input['customer_id'])
                ? Customer::findOrFail((int)$input['customer_id'])->id
                : null;
            $review->ip_address = request()->ip();

            if (!$moderated) {
                $review->approved_at = now();
            }

            $review->save();
            $this->syncCategoryRatings($review, (array)($input['category_ratings'] ?? []), $moderated ? null : now());

            return $review;
        });

        return [
            'review' => $this->review($review->fresh(['category_reviews.review_category', 'customer'])),
            'moderated' => $moderated,
        ];
    }

    private function product(string $slug): Product
    {
        return Product::published()
            ->withoutServiceCarriers()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function review(Review $review): array
    {
        return [
            'id' => (int)$review->id,
            'rating' => (int)$review->rating,
            'title' => (string)$review->title,
            'description' => (string)$review->description,
            'pros' => collect($review->pros ?: [])->pluck('value')->filter()->values()->all(),
            'cons' => collect($review->cons ?: [])->pluck('value')->filter()->values()->all(),
            'customer_name' => (string)$review->customer_name,
            'approved' => $review->approved_at !== null,
            'category_ratings' => $review->category_reviews->map(fn (CategoryReview $rating) => [
                'review_category_id' => (int)$rating->review_category_id,
                'review_category' => (string)optional($rating->review_category)->name,
                'rating' => (int)$rating->rating,
                'approved' => $rating->approved_at !== null,
            ])->values()->all(),
            'created_at' => optional($review->created_at)->toIso8601String(),
        ];
    }

    private function syncCategoryRatings(Review $review, array $ratings, $approvedAt): void
    {
        foreach ($ratings as $categoryId => $rating) {
            $rating = (int)$rating;
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            CategoryReview::create([
                'review_id' => $review->id,
                'review_category_id' => (int)$categoryId,
                'rating' => $rating,
                'approved_at' => $approvedAt,
            ]);
        }
    }

    private function lines(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string)$value))
            ->filter()
            ->map(fn ($value) => ['value' => $value])
            ->values()
            ->all();
    }

    private function validate(array $input, array $rules): void
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
