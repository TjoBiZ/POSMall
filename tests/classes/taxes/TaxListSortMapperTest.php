<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use KodZero\POSMall\Classes\Taxes\TaxListSortMapper;
use KodZero\POSMall\Models\Tax;

class TaxListSortMapperTest extends UsaTaxTestCase
{
    public function test_tax_state_filter_matches_primary_and_multi_state_codes(): void
    {
        Tax::create([
            'name' => 'Mapper Filter WA',
            'percentage' => 6.5,
            'state_code' => 'WA',
            'state_codes' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'is_enabled' => true,
        ]);

        Tax::create([
            'name' => 'Mapper Filter CA OR',
            'percentage' => 0,
            'state_code' => 'CA',
            'state_codes' => 'CA, OR',
            'tax_group_code' => 'DIGITAL_FILE_ONLY',
            'is_enabled' => true,
        ]);

        $oregonRows = Tax::query()
            ->stateCodes(['OR'])
            ->pluck('name')
            ->all();

        $this->assertContains('Mapper Filter CA OR', $oregonRows);
        $this->assertNotContains('Mapper Filter WA', $oregonRows);

        $washingtonRows = Tax::query()
            ->stateCodes(['WA'])
            ->pluck('name')
            ->all();

        $this->assertContains('Mapper Filter WA', $washingtonRows);
        $this->assertNotContains('Mapper Filter CA OR', $washingtonRows);
    }

    public function test_display_tax_columns_sort_with_postgresql_safe_expressions(): void
    {
        Tax::create([
            'name' => 'Mapper Sort WA',
            'percentage' => 6.5,
            'state_code' => 'WA',
            'state_codes' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'tax_group_name' => 'Physical tangible personal property',
            'tax_main_group' => Tax::TAX_MAIN_GROUP_PHYSICAL,
            'tax_main_group_name' => Tax::taxMainGroupOptions()[Tax::TAX_MAIN_GROUP_PHYSICAL],
            'jurisdiction_name' => 'Washington',
            'jurisdiction_code' => 'WA',
            'is_enabled' => true,
        ]);

        foreach ([
            'state_codes_display',
            'jurisdiction_display',
            'tax_main_group_display',
            'tax_group_display',
        ] as $sortColumn) {
            $query = Tax::query();

            app(TaxListSortMapper::class)->apply($query, $sortColumn, 'desc');

            $sql = $query->toSql();

            $this->assertStringNotContainsString('"""kodzero_posmall_taxes"""', $sql);
            $this->assertStringContainsString('"kodzero_posmall_taxes"."id" asc', $sql);

            $this->assertStringContainsString('COALESCE', $sql);
        }
    }
}
