<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use KodZero\POSMall\Classes\Taxes\UsaTaxResolver;
use KodZero\POSMall\Classes\Traits\FilteredTaxes;
use Illuminate\Support\Facades\DB;
use October\Rain\Support\Collection;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxRegionRow;

class UsaTaxResolverTest extends UsaTaxTestCase
{
    public function test_product_tax_wins_over_subcategory_category_and_default(): void
    {
        [$product, $parent, $child] = $this->productInChildCategory();

        $defaultTax = $this->tax('Default Tax', 'OR', 'PHYSICAL_TPP', 0);
        $defaultTax->is_default = true;
        $defaultTax->save();

        $categoryTax = $this->tax('Category Tax', 'WA', 'PHYSICAL_TPP', 6.5);
        $subcategoryTax = $this->tax('Subcategory Tax', 'CA', 'PHYSICAL_TPP', 7.25);
        $productTax = $this->tax('Product Tax', 'TX', 'PHYSICAL_TPP', 6.25);

        $parent->taxes()->attach($categoryTax->id);
        $child->taxes()->attach($subcategoryTax->id);
        $product->taxes()->attach($productTax->id);

        $this->assertSame($productTax->id, app(UsaTaxResolver::class)->resolveForProduct($product->fresh())->id);
    }

    public function test_subcategory_tax_wins_over_category_and_default(): void
    {
        [$product, $parent, $child] = $this->productInChildCategory();

        $categoryTax = $this->tax('Category Tax', 'WA', 'PHYSICAL_TPP', 6.5);
        $subcategoryTax = $this->tax('Subcategory Tax', 'CA', 'PHYSICAL_TPP', 7.25);

        $parent->taxes()->attach($categoryTax->id);
        $child->taxes()->attach($subcategoryTax->id);

        $this->assertSame($subcategoryTax->id, app(UsaTaxResolver::class)->resolveForProduct($product->fresh())->id);
    }

    public function test_category_tax_falls_back_to_default_tax(): void
    {
        [$product] = $this->productInChildCategory();

        $defaultTax = $this->tax('Default Tax', 'OR', 'PHYSICAL_TPP', 0);
        $defaultTax->is_default = true;
        $defaultTax->save();

        $this->assertSame($defaultTax->id, app(UsaTaxResolver::class)->resolveForProduct($product->fresh())->id);
    }

    public function test_default_tax_ignores_inactive_rows(): void
    {
        $inactiveDefault = $this->tax('Inactive Default Tax', 'OR', 'PHYSICAL_TPP', 0);
        $inactiveDefault->is_default = true;
        $inactiveDefault->is_active = false;
        $inactiveDefault->save();

        $activeDefault = $this->tax('Active Default Tax', 'WA', 'PHYSICAL_TPP', 6.5);
        $activeDefault->is_default = true;
        $activeDefault->is_active = true;
        $activeDefault->save();

        GeneralSettings::set('usa_default_tax_id', $inactiveDefault->id);

        $this->assertSame($activeDefault->id, app(UsaTaxResolver::class)->defaultTax()->id);
    }

    public function test_global_state_restriction_uses_active_tax_states(): void
    {
        [$product] = $this->productInChildCategory();
        $this->tax('California Tax', 'CA', 'PHYSICAL_TPP', 7.25);
        GeneralSettings::set('usa_sell_only_to_tax_states', true);

        $resolver = app(UsaTaxResolver::class);

        $this->assertTrue($resolver->canSellToState($product, 'CA'));
        $this->assertFalse($resolver->canSellToState($product, 'TX'));
    }

    public function test_state_restriction_matches_any_state_from_tax_state_codes_array(): void
    {
        [$product] = $this->productInChildCategory();
        $tax = $this->tax('No Sales Tax States', 'OR', 'PHYSICAL_TPP', 0);
        $tax->state_codes = ['AK', 'DE', 'MT', 'OR'];
        $tax->save();

        $this->assertSame(['AK', 'DE', 'MT', 'OR'], $tax->fresh()->stateCodesList());

        $product->taxes()->attach($tax->id);
        $product->sell_only_to_tax_states = true;

        $resolver = app(UsaTaxResolver::class);

        $this->assertTrue($resolver->canSellToState($product, 'DE'));
        $this->assertTrue($resolver->canSellToState($product, 'AK'));
        $this->assertFalse($resolver->canSellToState($product, 'TX'));
    }

    public function test_state_codes_accept_only_supported_usa_codes_and_allowed_separators(): void
    {
        $tax = $this->tax('Supported USA States', 'OR', 'PHYSICAL_TPP', 0);
        $tax->state_codes = 'ak,De; mT. OR';
        $tax->save();

        $this->assertSame(['AK', 'DE', 'MT', 'OR'], $tax->fresh()->stateCodesList());

        $this->expectException(ValidationException::class);

        Tax::create([
            'name' => 'Invalid State',
            'percentage' => 0,
            'rate_percent' => 0,
            'state_code' => 'LB',
            'state_codes' => 'AK, LBM',
            'tax_group_code' => 'PHYSICAL_TPP',
            'is_enabled' => true,
            'is_active' => true,
        ]);
    }

    public function test_state_code_field_accepts_state_code_lists_and_uses_first_as_primary(): void
    {
        foreach (['WA, CA', 'WA,CA', 'WA; CA', 'WA.CA', 'WA, Ca.'] as $value) {
            $tax = Tax::create([
                'name' => 'State Code List ' . $value,
                'percentage' => 0,
                'rate_percent' => 0,
                'state_code' => $value,
                'tax_group_code' => 'PHYSICAL_TPP',
                'is_enabled' => true,
                'is_active' => true,
            ]);

            $this->assertSame('WA', $tax->fresh()->state_code);
            $this->assertSame(['WA', 'CA'], $tax->fresh()->stateCodesList());
            $this->assertSame('WA, CA', $tax->fresh()->state_codes_display);
        }
    }

    public function test_tax_group_display_combines_code_and_name_for_backend_list(): void
    {
        $tax = $this->tax('Digital Tax', 'WA', 'DIGITAL_FILE_ONLY', 6.5);
        $tax->tax_group_name = 'Digital product delivered electronically only';
        $tax->save();

        $this->assertSame(
            'DIGITAL_FILE_ONLY / Digital product delivered electronically only',
            $tax->fresh()->tax_group_display
        );
    }

    public function test_zip_range_matcher_accepts_ranges_and_single_zip_values(): void
    {
        $resolver = app(UsaTaxResolver::class);

        $this->assertTrue($resolver->zipMatchesRanges('98104', '98101-98109, 98112'));
        $this->assertTrue($resolver->zipMatchesRanges('98112-1234', '98101-98109, 98112'));
        $this->assertFalse($resolver->zipMatchesRanges('98110', '98101-98109, 98112'));
    }

    public function test_zip_resolver_prefers_grouped_local_combined_rate_over_state_base_rate(): void
    {
        $base = $this->tax('Washington Sales Tax - State Base', 'WA', 'PHYSICAL_TPP', 6.5);
        $local = $this->tax('Washington Sales Tax - 10.55% Tax Region Group', 'WA', 'PHYSICAL_TPP', 10.55);
        $local->jurisdiction_type = 'geographic_tax_rate_group';
        $local->jurisdiction_name = 'WA 10.55% tax region group';
        $local->jurisdiction_code = 'WA-1055-test';
        $local->state_rate_percent = 6.5;
        $local->local_rate_percent = 4.05;
        $local->zip_code_ranges = '98101-98109, 98112';
        $local->save();

        $resolver = app(UsaTaxResolver::class);

        $this->assertSame($local->id, $resolver->resolveByZip('WA', '98104', 'PHYSICAL_TPP')->id);
        $this->assertSame($base->id, $resolver->resolveByZip('WA', '98660', 'PHYSICAL_TPP')->id);
    }

    public function test_zip_plus_four_prefers_matching_region_row_and_falls_back_to_zip5(): void
    {
        $zip5Tax = $this->tax('Washington ZIP5 Tax Region', 'WA', 'PHYSICAL_TPP', 10.10);
        $zip4Tax = $this->tax('Washington ZIP4 Tax Region', 'WA', 'PHYSICAL_TPP', 10.55);

        UsaTaxRegionRow::create([
            'tax_id' => $zip5Tax->id,
            'state_code' => 'WA',
            'zip_code' => '98104',
            'zip_from' => '98104',
            'zip_to' => '98104',
            'total_rate_percent' => 10.10,
            'tax_group_code' => 'PHYSICAL_TPP',
        ]);

        UsaTaxRegionRow::create([
            'tax_id' => $zip4Tax->id,
            'state_code' => 'WA',
            'zip_code' => '98104',
            'zip_from' => '98104',
            'zip_to' => '98104',
            'zip4_from' => '1234',
            'zip4_to' => '1234',
            'total_rate_percent' => 10.55,
            'tax_group_code' => 'PHYSICAL_TPP',
        ]);

        $resolver = app(UsaTaxResolver::class);

        $this->assertSame($zip4Tax->id, $resolver->resolveByZip('WA', '98104-1234', 'PHYSICAL_TPP')->id);
        $this->assertSame($zip4Tax->id, $resolver->resolveByZip('WA', '981041234', 'PHYSICAL_TPP')->id);
        $this->assertSame($zip5Tax->id, $resolver->resolveByZip('WA', '98104-9999', 'PHYSICAL_TPP')->id);
    }

    public function test_zip_region_rows_skip_disabled_or_inactive_high_rate_candidates(): void
    {
        $disabledHigh = $this->tax('Disabled High ZIP Tax Region', 'WA', 'PHYSICAL_TPP', 10.99);
        $disabledHigh->is_enabled = false;
        $disabledHigh->save();

        $inactiveMiddle = $this->tax('Inactive Middle ZIP Tax Region', 'WA', 'PHYSICAL_TPP', 10.80);
        $inactiveMiddle->is_active = false;
        $inactiveMiddle->save();

        $activeLower = $this->tax('Active Lower ZIP Tax Region', 'WA', 'PHYSICAL_TPP', 10.10);

        foreach ([
            [$disabledHigh, 10.99],
            [$inactiveMiddle, 10.80],
            [$activeLower, 10.10],
        ] as [$tax, $rate]) {
            UsaTaxRegionRow::create([
                'tax_id' => $tax->id,
                'state_code' => 'WA',
                'zip_code' => '98104',
                'zip_from' => '98104',
                'zip_to' => '98104',
                'total_rate_percent' => $rate,
                'tax_group_code' => 'PHYSICAL_TPP',
            ]);
        }

        $resolved = app(UsaTaxResolver::class)->resolveByZip('WA', '98104', 'PHYSICAL_TPP');

        $this->assertNotNull($resolved);
        $this->assertSame($activeLower->id, $resolved->id);
    }

    public function test_zip5_region_rows_cover_zip_plus_four_for_all_50_states(): void
    {
        $states = [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
            'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
            'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
            'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
            'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
        ];

        $resolver = app(UsaTaxResolver::class);

        foreach ($states as $index => $stateCode) {
            $zipCode = sprintf('%05d', 10000 + $index);
            $tax = $this->tax($stateCode . ' ZIP5 Tax Region', $stateCode, 'PHYSICAL_TPP', 5 + ($index / 100));

            UsaTaxRegionRow::create([
                'tax_id' => $tax->id,
                'state_code' => $stateCode,
                'zip_code' => $zipCode,
                'zip_from' => $zipCode,
                'zip_to' => $zipCode,
                'total_rate_percent' => 5 + ($index / 100),
                'tax_group_code' => 'PHYSICAL_TPP',
            ]);

            $resolved = $resolver->resolveByZip($stateCode, $zipCode . '-4321', 'PHYSICAL_TPP');

            $this->assertNotNull($resolved, $stateCode . ' ZIP+4 should fall back to the ZIP5 tax region.');
            $this->assertSame($tax->id, $resolved->id, $stateCode . ' should resolve to its ZIP5 tax region.');
        }
    }

    public function test_checkout_tax_filter_uses_zip_group_as_combined_rate_and_avoids_double_counting_state_base(): void
    {
        $base = $this->tax('Washington Sales Tax - State Base', 'WA', 'PHYSICAL_TPP', 6.5);
        $local = $this->tax('Washington Sales Tax - 10.55% Tax Region Group', 'WA', 'PHYSICAL_TPP', 10.55);
        $local->jurisdiction_code = 'WA-1055-test';
        $local->zip_code_ranges = '98101-98109, 98112';
        $local->save();

        $filter = new class {
            use FilteredTaxes;

            public function filter(Collection $taxes): Collection
            {
                $this->stateCode = 'WA';
                $this->zipCode = '98104';

                return $this->filterUsaDestinationTaxes($taxes);
            }
        };

        $filtered = $filter->filter(new Collection([$base, $local]));

        $this->assertCount(1, $filtered);
        $this->assertSame($local->id, $filtered->first()->id);
        $this->assertSame(10.55, (float)$filtered->sum('percentage'));
    }

    public function test_checkout_tax_filter_keeps_one_zip_tax_per_main_group_when_ranges_overlap(): void
    {
        $lower = $this->tax('Washington Sales Tax - 10.30% Tax Region Group', 'WA', 'PHYSICAL_TPP', 10.30);
        $lower->tax_main_group = 'physical';
        $lower->jurisdiction_code = 'WA-1030-test';
        $lower->zip_code_ranges = '98101-98109';
        $lower->save();

        $higher = $this->tax('Washington Sales Tax - 10.55% Tax Region Group', 'WA', 'PHYSICAL_TPP', 10.55);
        $higher->tax_main_group = 'physical';
        $higher->jurisdiction_code = 'WA-1055-test';
        $higher->zip_code_ranges = '98104';
        $higher->save();

        $filter = new class {
            use FilteredTaxes;

            public function filter(Collection $taxes): Collection
            {
                $this->stateCode = 'WA';
                $this->zipCode = '98104';

                return $this->filterUsaDestinationTaxes($taxes);
            }
        };

        $filtered = $filter->filter(new Collection([$lower, $higher]));

        $this->assertCount(1, $filtered);
        $this->assertSame($higher->id, $filtered->first()->id);
    }

    protected function productInChildCategory(): array
    {
        $parentId = $this->createCategory('USA Parent');
        $parent = Category::find($parentId);
        $childId = $this->createCategory('USA Child', $parentId);
        $child = Category::find($childId);
        $productId = $this->createProduct();
        $product = Product::find($productId);
        $product->taxes()->detach();
        DB::table('kodzero_posmall_category_product')->where('product_id', $product->id)->delete();
        DB::table('kodzero_posmall_category_product')->insert([
            'product_id' => $product->id,
            'category_id' => $child->id,
        ]);

        return [$product, $parent, $child];
    }

}
