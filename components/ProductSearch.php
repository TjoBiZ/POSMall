<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Models\Product;

class ProductSearch extends POSMallComponent
{
    private const MAX_SEARCH_CANDIDATES = 2000;
    private const SEARCH_RESULTS_CANDIDATES = 300;

    public $query = '';

    public $suggestions;

    public $results;

    public $isResultsMode = false;

    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.productSearch.details.name',
            'description' => 'kodzero.posmall::lang.components.productSearch.details.description',
        ];
    }

    public function defineProperties()
    {
        return [
            'mode' => [
                'title'   => 'kodzero.posmall::lang.components.productSearch.properties.mode.name',
                'type'    => 'dropdown',
                'default' => 'suggest',
            ],
            'perPage' => [
                'title'             => 'kodzero.posmall::lang.components.productSearch.properties.perPage.name',
                'type'              => 'string',
                'default'           => 24,
                'validationPattern' => '^[0-9]+$',
            ],
        ];
    }

    public function getModeOptions(): array
    {
        return [
            'suggest' => 'kodzero.posmall::lang.components.productSearch.properties.mode.suggest',
            'results' => 'kodzero.posmall::lang.components.productSearch.properties.mode.results',
        ];
    }

    public function onRun()
    {
        $this->setVar('query', $this->cleanQuery((string)input('q')));
        $this->setVar('isResultsMode', $this->property('mode') === 'results');

        if ($this->isResultsMode) {
            $this->setVar('results', $this->searchProducts($this->query, (int)$this->property('perPage'), true));
        } else {
            $this->setVar('suggestions', collect());
        }
    }

    public function onSuggest(): array
    {
        $this->setVar('query', $this->cleanQuery((string)post('q')));
        $this->setVar('suggestions', $this->searchProducts($this->query, 3));

        return [
            '[data-posmall-search-suggestions]' => $this->renderPartial($this->alias . '::suggestions'),
        ];
    }

    public function productUrl(Product $product): string
    {
        return $this->controller->pageUrl('posmall-product', ['slug' => $product->slug]);
    }

    public function searchUrl(?string $query = null): string
    {
        $query = $this->cleanQuery($query ?? $this->query);
        $url = $this->controller->pageUrl('posmall-search') ?: '/posmall/search';

        return $query === '' ? $url : $url . '?' . http_build_query(['q' => $query]);
    }

    protected function searchProducts(string $query, int $limit, bool $paginate = false)
    {
        $query = $this->cleanQuery($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $limit = max(1, $limit);
        $candidateLimit = $paginate
            ? $this->paginatedCandidateLimit($limit)
            : $this->candidateLimit($limit, false);
        $builder = $this->searchQuery($query, $candidateLimit);

        if ($paginate) {
            return $builder->simplePaginate($limit)->appends(['q' => $query]);
        }

        return $builder->limit($limit)->get();
    }

    protected function searchQuery(string $query, int $candidateLimit = self::MAX_SEARCH_CANDIDATES)
    {
        $like = '%' . $this->escapeLike($query) . '%';
        $slugLike = '%' . $this->escapeLike(Str::slug($query)) . '%';
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $ids = $this->matchingProductIdsQuery($query, $like, $slugLike, $operator, $candidateLimit);

        $includes = ['image_sets.images', 'prices.currency'];

        if (Auth::user()) {
            $includes[] = 'customer_group_prices';
        }

        return Product::published()
            ->with($includes)
            ->withoutServiceCarriers()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id');
    }

    protected function matchingProductIdsQuery(string $query, string $like, string $slugLike, string $operator, int $candidateLimit)
    {
        $queries = [
            $this->productFieldMatchQuery('name', $like, $operator),
            $this->productFieldMatchQuery('slug', $slugLike, $operator),
            $this->productFieldMatchQuery('user_defined_id', $like, $operator),
            $this->productFieldMatchQuery('mpn', $like, $operator),
            $this->productFieldMatchQuery('gtin', $like, $operator),
            $this->variantFieldMatchQuery('name', $like, $operator),
            $this->variantFieldMatchQuery('mpn', $like, $operator),
            $this->variantFieldMatchQuery('gtin', $like, $operator),
            $this->propertyValueMatchQuery('value', $like, $operator),
            $this->propertyValueMatchQuery('index_value', $slugLike, $operator),
        ];

        if (ctype_digit($query)) {
            $id = (int)$query;
            $queries[] = DB::table('kodzero_posmall_products')->select('id')->where('id', $id);
            $queries[] = DB::table('kodzero_posmall_product_variants')->select('product_id as id')->where('id', $id);
        }

        $union = array_shift($queries);

        foreach ($queries as $matchQuery) {
            $union->unionAll($matchQuery);
        }

        return DB::query()
            ->fromSub($union, 'search_matches')
            ->select('id')
            ->whereNotNull('id')
            ->limit($candidateLimit);
    }

    protected function productFieldMatchQuery(string $field, string $like, string $operator)
    {
        return DB::table('kodzero_posmall_products')
            ->select('id')
            ->where('published', true)
            ->whereNull('deleted_at')
            ->where($field, $operator, $like);
    }

    protected function variantFieldMatchQuery(string $field, string $like, string $operator)
    {
        return DB::table('kodzero_posmall_product_variants')
            ->select('product_id as id')
            ->whereNull('deleted_at')
            ->where($field, $operator, $like);
    }

    protected function propertyValueMatchQuery(string $field, string $like, string $operator)
    {
        return DB::table('kodzero_posmall_property_values')
            ->select('product_id as id')
            ->whereNull('variant_id')
            ->where($field, $operator, $like);
    }

    protected function cleanQuery(string $query): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $query));
    }

    protected function candidateLimit(int $limit, bool $paginate): int
    {
        $multiplier = $paginate ? 50 : 10;

        return min(self::MAX_SEARCH_CANDIDATES, max(50, $limit * $multiplier));
    }

    protected function paginatedCandidateLimit(int $limit): int
    {
        return min(self::MAX_SEARCH_CANDIDATES, max(self::SEARCH_RESULTS_CANDIDATES, $limit * 2));
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
