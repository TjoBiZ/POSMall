<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use KodZero\POSMall\Classes\Taxes\UsaTaxImporter;
use KodZero\POSMall\Classes\Taxes\GeographicTaxRateGrouper;
use KodZero\POSMall\Classes\Taxes\UsaTaxResolver;
use KodZero\POSMall\Classes\Taxes\UsaTaxStagingDisplayGrouper;
use KodZero\POSMall\Classes\Taxes\Parsers\SstTaxSourceParser;
use KodZero\POSMall\Classes\Taxes\Parsers\SstTaxabilityMatrixParser;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxHistory;
use KodZero\POSMall\Models\UsaTaxImportStaging;

class UsaTaxImporterTest extends UsaTaxTestCase
{
    public function test_it_stages_parsed_records_from_selected_sources(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['CA', 'OR']);

        $rows = UsaTaxImportStaging::where('batch_id', $batchId)->get();

        $this->assertGreaterThanOrEqual(4, $rows->count());
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CA' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'OR' && (float)$row->rate_percent === 0.0));
    }

    public function test_washington_stages_local_jurisdiction_rates_as_grouped_zip_ranges(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['WA']);

        $rows = UsaTaxImportStaging::where('batch_id', $batchId)->get();
        $seattleGroup = $rows->first(fn ($row) => $row->jurisdiction_name === 'WA 10.55% tax region group');

        $this->assertNotNull($seattleGroup);
        $this->assertSame('WA', $seattleGroup->state_code);
        $this->assertSame('PHYSICAL_TPP', $seattleGroup->tax_group_code);
        $this->assertSame(6.5, (float)$seattleGroup->state_rate_percent);
        $this->assertSame(4.05, (float)$seattleGroup->local_rate_percent);
        $this->assertSame(10.55, (float)$seattleGroup->rate_percent);
        $this->assertStringContainsString('98104', (string)$seattleGroup->zip_code_hints);
        $this->assertStringContainsString('98101-98109', (string)$seattleGroup->zip_code_ranges);
        $this->assertStringContainsString('Seattle', (string)$seattleGroup->description);
        $this->assertStringContainsString('King County', (string)$seattleGroup->description);
        $this->assertStringContainsString('grouped local WA 10.55% tax record', (string)$seattleGroup->info);
        $this->assertStringContainsString('WAB2026Q3MAY27.zip', (string)$seattleGroup->boundary_source_url);
        $baseRow = $rows->first(fn ($row) => $row->state_code === 'WA' && $row->tax_group_code === 'PHYSICAL_TPP' && !$row->jurisdiction_code);

        $this->assertNotNull($baseRow);
        $this->assertNull($baseRow->zip_code_ranges);
        $this->assertStringContainsString('statewide/base WA tax record', (string)$baseRow->info);

        $sharedRateGroup = $rows->first(fn ($row) => $row->jurisdiction_name === 'WA 10.30% tax region group');

        $this->assertNotNull($sharedRateGroup);
        $this->assertStringContainsString('Bellevue', (string)$sharedRateGroup->description);
        $this->assertStringContainsString('Tacoma', (string)$sharedRateGroup->description);
        $this->assertStringContainsString('King County', (string)$sharedRateGroup->description);
        $this->assertStringContainsString('Pierce County', (string)$sharedRateGroup->description);
        $this->assertStringNotContainsString('and 1 more', (string)$sharedRateGroup->description);
        $this->assertStringContainsString('98004-98008', (string)$sharedRateGroup->zip_code_ranges);
        $this->assertStringContainsString('98402-98409', (string)$sharedRateGroup->zip_code_ranges);

        $olympiaGroup = $rows->first(fn ($row) => $row->jurisdiction_name === 'WA 9.80% tax region group');

        $this->assertNotNull($olympiaGroup);
        $this->assertStringContainsString('Olympia', (string)$olympiaGroup->description);
        $this->assertStringContainsString('98501-98502', (string)$olympiaGroup->zip_code_ranges);
        $this->assertStringContainsString('98599', (string)$olympiaGroup->zip_code_hints);

        $repairBase = $rows->first(fn ($row) => $row->state_code === 'WA'
            && $row->tax_group_code === 'RETAIL_REPAIR_INSTALLATION_SERVICE'
            && !$row->jurisdiction_code);
        $repairOlympiaGroup = $rows->first(fn ($row) => $row->jurisdiction_name === 'WA 9.80% tax region group'
            && $row->tax_group_code === 'RETAIL_REPAIR_INSTALLATION_SERVICE');
        $itSeattleGroup = $rows->first(fn ($row) => $row->jurisdiction_name === 'WA 10.55% tax region group'
            && $row->tax_group_code === 'INFORMATION_TECHNOLOGY_SERVICE');

        $this->assertNotNull($repairBase);
        $this->assertStringContainsString('statewide/base WA tax record', (string)$repairBase->info);
        $this->assertNotNull($repairOlympiaGroup);
        $this->assertStringContainsString('Retail repair, installation and cleaning service', (string)$repairOlympiaGroup->tax_group_name);
        $this->assertStringContainsString('Olympia', (string)$repairOlympiaGroup->description);
        $this->assertStringContainsString('98501-98502', (string)$repairOlympiaGroup->zip_code_ranges);
        $this->assertNotNull($itSeattleGroup);
        $this->assertStringContainsString('Information technology service', (string)$itSeattleGroup->tax_group_name);
    }

    public function test_geographic_rate_grouper_groups_any_state_regions_by_same_rate(): void
    {
        $records = [
            $this->syntheticRegionRecord('CA', 7.25, 'Placer County / Roseville', 'CA-001', '95661, 95678'),
            $this->syntheticRegionRecord('CA', 7.25, 'Placer County / Rocklin', 'CA-002', '95677'),
            $this->syntheticRegionRecord('CA', 8.75, 'Los Angeles County / Los Angeles', 'CA-003', '90001-90003'),
            $this->syntheticRegionRecord('NV', 8.375, 'Clark County / Las Vegas', 'NV-001', '89101'),
        ];

        $groups = app(GeographicTaxRateGrouper::class)->group($records);
        $californiaBase = collect($groups)->first(fn ($row) => $row['state_code'] === 'CA' && (float)$row['rate_percent'] === 7.25);
        $californiaHigh = collect($groups)->first(fn ($row) => $row['state_code'] === 'CA' && (float)$row['rate_percent'] === 8.75);
        $nevada = collect($groups)->first(fn ($row) => $row['state_code'] === 'NV');

        $this->assertCount(3, $groups);
        $this->assertSame('CA 7.25% tax region group', $californiaBase['jurisdiction_name']);
        $this->assertStringContainsString('Roseville', $californiaBase['description']);
        $this->assertStringContainsString('Rocklin', $californiaBase['description']);
        $this->assertStringContainsString('95661, 95677-95678', $californiaBase['zip_code_ranges']);
        $this->assertStringContainsString('90001-90003', $californiaHigh['zip_code_ranges']);
        $this->assertSame('NV 8.38% tax region group', $nevada['jurisdiction_name']);
    }

    public function test_geographic_rate_grouper_keeps_statewide_taxability_rows_as_base_rows(): void
    {
        $records = [[
            'state_code' => 'AR',
            'source_url' => 'https://sst.streamlinedsalestax.org/api/forms/14807/rows',
            'source_type' => 'SST_TAXABILITY_MATRIX_API',
            'source_name' => 'SST Taxability Matrix',
            'parser_name' => 'SstTaxabilityMatrixParser',
            'raw_name' => 'AR DIGITAL_AUTOMATED_SERVICE via SST Matrix',
            'parsed_name' => 'AR 0.00% Digital automated service SST Taxability',
            'tax_group_code' => 'DIGITAL_AUTOMATED_SERVICE',
            'tax_group_name' => 'Digital automated service',
            'tax_group_description' => 'Digital service where software performs most of the work.',
            'taxability_mode' => 'sst_exempt',
            'jurisdiction_type' => 'statewide_taxability',
            'jurisdiction_name' => 'AR statewide SST taxability',
            'jurisdiction_code' => null,
            'state_rate_percent' => 0,
            'local_rate_percent' => 0,
            'rate_percent' => 0,
            'description' => 'Synthetic SST exempt service row.',
            'source_hash' => hash('sha256', 'AR|DIGITAL_AUTOMATED_SERVICE|sst_exempt'),
        ]];

        $grouped = app(GeographicTaxRateGrouper::class)->group($records);

        $this->assertCount(1, $grouped);
        $this->assertSame('statewide_taxability', $grouped[0]['jurisdiction_type']);
        $this->assertSame('AR statewide SST taxability', $grouped[0]['jurisdiction_name']);
        $this->assertNull($grouped[0]['jurisdiction_code']);
        $this->assertArrayNotHasKey('zip_code_ranges', $grouped[0]);
    }

    public function test_sst_parser_joins_rate_and_boundary_fixtures_without_denormalized_group_codes(): void
    {
        $rateFile = tempnam(sys_get_temp_dir(), 'posmall-sst-rate-');
        $boundaryFile = tempnam(sys_get_temp_dir(), 'posmall-sst-boundary-');

        file_put_contents($rateFile, implode("\n", [
            '56,45,56,0.04,0.04,0.04,0.04,20060701,99991231',
            '56,00,039,0.01,0.01,0.01,0.01,20060701,99991231',
            '56,69,02290,0.02,0.02,0.02,0.02,20081001,99991231',
        ]));
        file_put_contents($boundaryFile, implode("\n", [
            'A,19010101,99991231,3330,3340,E,W,CODY,Ln,,,,,,Teton Village,83001,,,,,,56039,56,56,039,02290,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,',
            'A,19010101,99991231,3345,3355,O,W,CODY,Ln,,,,,,Teton Village,83002,,,,,,56039,56,56,039,02290,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,,',
        ]));

        try {
            $records = app(SstTaxSourceParser::class)->parse([
                'code' => 'SST_RATES_BOUNDARIES',
                'name' => 'SST Rate and Boundary Files',
                'rate_file' => $rateFile,
                'boundary_file' => $boundaryFile,
                'sst_boundary_max_rows' => 100,
            ], 'WY');
        } finally {
            @unlink($rateFile);
            @unlink($boundaryFile);
        }

        $this->assertCount(1, $records);
        $record = $records[0];

        $this->assertSame('WY', $record['state_code']);
        $this->assertSame('PHYSICAL_TPP', $record['tax_group_code']);
        $this->assertSame(4.0, (float)$record['state_rate_percent']);
        $this->assertSame(3.0, (float)$record['local_rate_percent']);
        $this->assertSame(7.0, (float)$record['rate_percent']);
        $this->assertStringContainsString('83001', $record['zip_code_ranges']);
        $this->assertStringContainsString('83002', $record['zip_code_ranges']);
        $this->assertStringContainsString('S02290', $record['jurisdiction_code']);
    }

    public function test_sst_taxability_matrix_clones_physical_rate_rows_for_taxable_virtual_groups(): void
    {
        Tax::create([
            'name' => 'GA Physical Region',
            'percentage' => 8,
            'rate_percent' => 8,
            'state_code' => 'GA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'tax_group_name' => 'Physical tangible personal property',
            'tax_main_group' => Tax::TAX_MAIN_GROUP_PHYSICAL,
            'jurisdiction_type' => 'sst_rate_boundary',
            'jurisdiction_name' => 'GA 8.00% SST tax region',
            'jurisdiction_code' => 'GA-C001-80000',
            'state_rate_percent' => 4,
            'local_rate_percent' => 4,
            'zip_code_hints' => '30001, 30002',
            'zip_code_ranges' => '30001-30002',
            'boundary_source_url' => 'https://example.test/GAB.zip',
            'source_type' => 'SST_RATE_BOUNDARY',
            'source_name' => 'SST Rate and Boundary Files',
            'parser_name' => 'SstTaxSourceParser',
            'is_enabled' => true,
            'is_active' => true,
        ]);

        $records = app(SstTaxabilityMatrixParser::class)->parse([
            'code' => 'SST_TAXABILITY_MATRIX',
            'name' => 'SST Taxability Matrix',
            'taxability_state_ids' => ['GA' => 13],
            'taxability_forms' => [[
                'versions' => [[
                    'stateId' => 13,
                    'formId' => 101,
                    'version' => 2025.1,
                    'published' => true,
                ]],
            ]],
            'taxability_rows' => [
                101 => array_merge(
                    array_map(fn ($ref) => $this->matrixRow($ref, 'Digital product ' . $ref, in_array($ref, ['31040', '31070', '31100'], true) ? '1' : '0'), [
                        '31040', '31050', '31060', '31070', '31080', '31090', '31100', '31110', '31120',
                    ]),
                    [
                        $this->matrixRow('30050', 'Prewritten computer software delivered electronically', '0'),
                        $this->matrixRow('31000', 'Products transferred electronically other than specified digital products', '1'),
                    ]
                ),
            ],
        ], 'GA');

        $digital = collect($records)->first(fn ($record) => $record['tax_group_code'] === 'DIGITAL_FILE_ONLY');
        $prewritten = collect($records)->first(fn ($record) => $record['tax_group_code'] === 'PREWRITTEN_SOFTWARE_ELECTRONIC');
        $service = collect($records)->first(fn ($record) => $record['tax_group_code'] === 'DIGITAL_AUTOMATED_SERVICE');

        $this->assertNotNull($digital);
        $this->assertSame(8.0, (float)$digital['rate_percent']);
        $this->assertSame('30001-30002', $digital['zip_code_ranges']);
        $this->assertSame('sst_partial_taxable_review', $digital['taxability_mode']);
        $this->assertStringContainsString('/api/forms/101/rows', $digital['source_url']);

        $this->assertNotNull($prewritten);
        $this->assertSame(0.0, (float)$prewritten['rate_percent']);
        $this->assertSame('sst_exempt', $prewritten['taxability_mode']);
        $this->assertNull($prewritten['zip_code_ranges'] ?? null);

        $this->assertNotNull($service);
        $this->assertSame(8.0, (float)$service['rate_percent']);
        $this->assertSame('DIGITAL_AUTOMATED_SERVICE', $service['tax_group_code']);
    }

    public function test_tax_main_group_display_and_scope_filter_live_tax_records(): void
    {
        $physical = $this->tax('Physical Goods', 'WA', 'PHYSICAL_TPP', 6.5);
        $service = $this->tax('Repair Service', 'WA', 'RETAIL_REPAIR_INSTALLATION_SERVICE', 6.5);
        $dataService = $this->tax('Data Processing', 'TX', 'DATA_PROCESSING_SERVICE', 5.0);
        $virtual = $this->tax('Digital File', 'WA', 'DIGITAL_FILE_ONLY', 6.5);
        $general = $this->tax('Gift Card', 'WA', 'GIFT_CARD_STORED_VALUE', 0);

        $this->assertSame('Physical product', $physical->tax_main_group_display);
        $this->assertSame('Service', $service->tax_main_group_display);
        $this->assertSame('Service', $dataService->tax_main_group_display);
        $this->assertSame('Virtual product', $virtual->tax_main_group_display);
        $this->assertSame('General', $general->tax_main_group_display);

        $this->assertSame([$physical->id], Tax::taxMainGroup(['physical'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$service->id, $dataService->id], Tax::taxMainGroup(['service'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$virtual->id], Tax::taxMainGroup(['virtual'])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$general->id], Tax::taxMainGroup(['general'])->orderBy('id')->pluck('id')->all());

        $service->tax_group_code_rows()->create([
            'tax_group_code' => 'INFORMATION_TECHNOLOGY_SERVICE',
            'tax_group_name' => 'Information technology service',
            'tax_group_description' => 'IT services and support.',
        ]);

        $this->assertSame('Service', $service->fresh()->tax_main_group_display);
        $this->assertTrue($service->fresh()->matchesTaxGroupCode('INFORMATION_TECHNOLOGY_SERVICE'));
    }

    public function test_staging_display_grouper_combines_same_rate_rows_by_main_group_without_losing_ids(): void
    {
        $rows = collect([
            $this->staging('WA', 'RETAIL_REPAIR_INSTALLATION_SERVICE', 9.8, 'WA-980', '98501-98502'),
            $this->staging('WA', 'INFORMATION_TECHNOLOGY_SERVICE', 9.8, 'WA-980', '98501-98502'),
            $this->staging('WA', 'DIGITAL_FILE_ONLY', 9.8, 'WA-980', '98501-98502'),
        ]);

        $displayRows = app(UsaTaxStagingDisplayGrouper::class)->group($rows);
        $serviceRow = $displayRows->first(fn ($row) => $row->tax_main_group === Tax::TAX_MAIN_GROUP_SERVICE);
        $virtualRow = $displayRows->first(fn ($row) => $row->tax_main_group === Tax::TAX_MAIN_GROUP_VIRTUAL);

        $this->assertCount(2, $displayRows);
        $this->assertSame(2, $serviceRow->record_count);
        $this->assertSame(
            $rows->take(2)->pluck('id')->implode(','),
            $serviceRow->record_ids_csv
        );
        $this->assertStringContainsString('RETAIL_REPAIR_INSTALLATION_SERVICE', $serviceRow->tax_group_code);
        $this->assertStringContainsString('INFORMATION_TECHNOLOGY_SERVICE', $serviceRow->tax_group_code);
        $this->assertSame(1, $virtualRow->record_count);
    }

    public function test_staging_display_imported_marker_tracks_current_live_tax_rows(): void
    {
        $row = $this->staging('WA', 'PHYSICAL_TPP', 9.8, 'WA-980', '98501-98502');
        $grouper = app(UsaTaxStagingDisplayGrouper::class);

        $before = $grouper->group(collect([$row]), $grouper->liveTaxKeysFor([$row]))->first();

        $this->assertFalse($before->is_imported);
        $this->assertSame((string)$row->id, $before->record_ids_csv);

        app(UsaTaxImporter::class)->importStaging([$row->id]);
        $row = $row->fresh();
        $after = $grouper->group(collect([$row]), $grouper->liveTaxKeysFor([$row]))->first();

        $this->assertTrue($after->is_imported);
        $this->assertSame('', $after->record_ids_csv);

        Tax::where('state_code', 'WA')->where('jurisdiction_code', 'WA-980')->delete();
        $afterDelete = $grouper->group(collect([$row]), $grouper->liveTaxKeysFor([$row]))->first();

        $this->assertFalse($afterDelete->is_imported);
        $this->assertSame((string)$row->id, $afterDelete->record_ids_csv);

        $this->assertSame(1, app(UsaTaxImporter::class)->importStaging([$row->id]));
        $this->assertTrue(Tax::where('state_code', 'WA')->where('jurisdiction_code', 'WA-980')->exists());
    }

    public function test_washington_import_keeps_state_base_and_grouped_local_zip_records(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['WA']);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)
            ->where('tax_group_code', 'PHYSICAL_TPP')
            ->pluck('id')
            ->all();

        app(UsaTaxImporter::class)->importStaging($ids);

        $this->assertDatabaseHas('kodzero_posmall_taxes', [
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_code' => null,
        ]);
        $this->assertDatabaseHas('kodzero_posmall_taxes', [
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_name' => 'WA 10.55% tax region group',
        ]);
        $tax = Tax::where('jurisdiction_name', 'WA 10.55% tax region group')->first();

        $this->assertStringContainsString('98104', (string)$tax->zip_code_hints);
        $this->assertStringContainsString('98101-98109', (string)$tax->zip_code_ranges);
        $this->assertDatabaseHas('kodzero_posmall_usa_tax_region_rows', [
            'tax_id' => $tax->id,
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'zip_from' => '98101',
            'zip_to' => '98109',
        ]);
    }

    public function test_import_merges_same_rate_live_tax_rows_inside_one_main_group(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['WA']);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)
            ->where('state_code', 'WA')
            ->where('jurisdiction_name', 'WA 9.80% tax region group')
            ->whereIn('tax_group_code', [
                'RETAIL_REPAIR_INSTALLATION_SERVICE',
                'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE',
                'LANDSCAPING_MAINTENANCE_SERVICE',
                'INFORMATION_TECHNOLOGY_SERVICE',
                'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE',
                'CUSTOM_SOFTWARE_DEV',
                'CUSTOM_MODIFICATION_SEPARATE',
                'ADVERTISING_SERVICE',
                'TEMPORARY_STAFFING_SERVICE',
                'SECURITY_INVESTIGATION_SERVICE',
                'LIVE_PRESENTATION_SERVICE',
            ])
            ->pluck('id')
            ->all();

        app(UsaTaxImporter::class)->importStaging($ids);

        $serviceTaxes = Tax::where('state_code', 'WA')
            ->where('jurisdiction_name', 'WA 9.80% tax region group')
            ->get()
            ->filter(fn (Tax $tax) => $tax->tax_main_group === Tax::TAX_MAIN_GROUP_SERVICE)
            ->values();

        $this->assertCount(1, $serviceTaxes);
        $tax = $serviceTaxes->first();

        $this->assertSame(11, count($tax->taxGroupCodesList()));
        $this->assertSame('11 service tax groups', $tax->tax_group_display);
        $this->assertTrue($tax->matchesTaxGroupCode('RETAIL_REPAIR_INSTALLATION_SERVICE'));
        $this->assertTrue($tax->matchesTaxGroupCode('LIVE_PRESENTATION_SERVICE'));
        $this->assertSame(
            $tax->id,
            app(UsaTaxResolver::class)->resolveByZip('WA', '98501', 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE')->id
        );
        $this->assertSame(
            $tax->id,
            app(UsaTaxResolver::class)->resolveByZip('WA', '98501', 'RETAIL_REPAIR_INSTALLATION_SERVICE')->id
        );
        $this->assertDatabaseHas('kodzero_posmall_usa_tax_region_rows', [
            'tax_id' => $tax->id,
            'state_code' => 'WA',
            'tax_group_code' => 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE',
            'tax_main_group' => Tax::TAX_MAIN_GROUP_SERVICE,
            'zip_from' => '98501',
            'zip_to' => '98502',
        ]);

        $tax->zip_code_ranges = null;
        $tax->save();

        $this->assertSame(
            $tax->id,
            app(UsaTaxResolver::class)->resolveByZip('WA', '98501', 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE')->id
        );
    }

    protected function syntheticRegionRecord(string $state, float $rate, string $area, string $code, string $zips): array
    {
        return [
            'state_code' => $state,
            'source_url' => 'https://example.test/' . strtolower($state),
            'source_type' => 'CSV',
            'source_name' => $state . ' Example Regional Tax Source',
            'parser_name' => 'ExampleRegionalParser',
            'raw_name' => $area,
            'parsed_name' => $area,
            'tax_group_code' => 'PHYSICAL_TPP',
            'tax_group_name' => 'Physical tangible personal property',
            'tax_group_description' => 'Physical goods shipped or handed to a customer.',
            'jurisdiction_type' => 'county_city',
            'jurisdiction_name' => $area,
            'jurisdiction_code' => $code,
            'rate_percent' => $rate,
            'zip_code_hints' => $zips,
            'description' => 'Synthetic region row.',
            'source_hash' => hash('sha256', $state . $rate . $code),
        ];
    }

    protected function staging(string $state, string $groupCode, float $rate, string $jurisdictionCode, string $zips): UsaTaxImportStaging
    {
        $groups = \KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry::taxGroups();
        [$groupName, $groupDescription] = $groups[$groupCode] ?? [$groupCode, null];

        return UsaTaxImportStaging::create([
            'batch_id' => 'test',
            'status' => UsaTaxImportStaging::STATUS_PARSED,
            'state_code' => $state,
            'source_url' => 'https://example.test/' . strtolower($state),
            'source_type' => 'TEST',
            'source_name' => $state . ' source',
            'parser_name' => 'TestParser',
            'raw_name' => $groupName,
            'parsed_name' => $groupName,
            'tax_group_code' => $groupCode,
            'tax_group_name' => $groupName,
            'tax_group_description' => $groupDescription,
            'jurisdiction_type' => 'geographic_tax_rate_group',
            'jurisdiction_name' => $state . ' ' . number_format($rate, 2) . '% tax region group',
            'jurisdiction_code' => $jurisdictionCode,
            'state_rate_percent' => 6.5,
            'local_rate_percent' => $rate - 6.5,
            'rate_percent' => $rate,
            'zip_code_ranges' => $zips,
            'description' => 'Synthetic staging row.',
            'source_hash' => hash('sha256', $state . $groupCode . $rate . $jurisdictionCode),
        ]);
    }

    protected function matrixRow(string $ref, string $label, string $taxable): array
    {
        return [
            'displayColumns' => [
                ['orderId' => 1, 'value' => $ref],
                ['orderId' => 2, 'value' => $label],
                ['orderId' => 3, 'value' => $taxable],
                ['orderId' => 4, 'value' => 'Fixture statute'],
                ['orderId' => 5, 'value' => 'Fixture comment'],
            ],
        ];
    }

    public function test_selected_states_stage_only_normalized_rows_without_duplicates(): void
    {
        $importer = app(UsaTaxImporter::class);

        $importer->stageStates(['CA', 'WA', 'OR', 'TX']);
        $importer->stageStates(['CA', 'WA', 'OR', 'TX']);

        $rows = UsaTaxImportStaging::where('status', UsaTaxImportStaging::STATUS_PARSED)->get();
        $states = $rows->pluck('state_code')->unique()->sort()->values()->all();
        $keys = $rows->map(fn ($row) => implode('|', [
            $row->state_code,
            $row->tax_group_code,
            $row->jurisdiction_code,
            $row->source_url,
            $row->parser_name,
        ]));

        $this->assertSame(['CA', 'OR', 'TX', 'WA'], $states);
        $this->assertSame($keys->count(), $keys->unique()->count());
        $this->assertSame(0, UsaTaxImportStaging::where('status', UsaTaxImportStaging::STATUS_FAILED)->count());
        $this->assertFalse($rows->contains(fn ($row) => in_array($row->state_code, ['FL', 'NY', 'MN', 'IL'], true)));
    }

    public function test_unsupported_sources_do_not_create_red_failed_staging_rows(): void
    {
        app(UsaTaxImporter::class)->stageStates(['PR']);

        $this->assertSame(0, UsaTaxImportStaging::count());
    }

    public function test_every_registry_state_can_stage_at_least_one_normalized_record(): void
    {
        $states = collect(\KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry::states())->pluck('code')->all();

        app(UsaTaxImporter::class)->stageStates($states);

        $stagedStates = UsaTaxImportStaging::where('status', UsaTaxImportStaging::STATUS_PARSED)
            ->pluck('state_code')
            ->unique()
            ->sort()
            ->values()
            ->all();

        sort($states);

        $this->assertSame($states, $stagedStates);
        $this->assertSame(0, UsaTaxImportStaging::where('status', UsaTaxImportStaging::STATUS_FAILED)->count());
    }

    public function test_it_stages_supported_starter_records_for_the_lower_usa_table(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStarterRecords();

        $rows = UsaTaxImportStaging::where('batch_id', $batchId)->where('status', UsaTaxImportStaging::STATUS_PARSED)->get();

        $this->assertGreaterThanOrEqual(65, $rows->count());
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AL' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AL' && $row->tax_group_code === 'PREWRITTEN_SOFTWARE_ELECTRONIC'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AL' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AL' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AL' && $row->tax_group_code === 'RETAIL_REPAIR_INSTALLATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AK' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AZ' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AZ' && $row->tax_group_code === 'PREWRITTEN_SOFTWARE_ELECTRONIC'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'AZ' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CO' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CO' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CT' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CT' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'CT' && $row->tax_group_code === 'DATA_PROCESSING_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DC' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DC' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DC' && $row->tax_group_code === 'DATA_PROCESSING_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DE' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DE' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'DE' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'FL' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'FL' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'FL' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'FL' && $row->tax_group_code === 'INFORMATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'HI' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'HI' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'HI' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ID' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ID' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ID' && $row->tax_group_code === 'CUSTOM_MODIFICATION_SEPARATE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'IL' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'IL' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'IL' && $row->tax_group_code === 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'LA' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'LA' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'LA' && $row->tax_group_code === 'INFORMATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ME' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ME' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'ME' && $row->tax_group_code === 'DIGITAL_AUDIOVISUAL_AUDIO_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MD' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MD' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MD' && $row->tax_group_code === 'SECURITY_INVESTIGATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MA' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MA' && $row->tax_group_code === 'PREWRITTEN_SOFTWARE_ELECTRONIC'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MA' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MA' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MN' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MS' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MS' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MS' && $row->tax_group_code === 'FOOD_GROCERY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MO' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MO' && $row->tax_group_code === 'PREWRITTEN_SOFTWARE_ELECTRONIC'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MO' && $row->tax_group_code === 'CUSTOM_SOFTWARE_DEV'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MT' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MT' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'MT' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NV' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NH' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NH' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NH' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NM' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NM' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'NM' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'WY' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'PA' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'PA' && $row->tax_group_code === 'DIGITAL_FILE_ONLY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'PA' && $row->tax_group_code === 'INFORMATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'SC' && $row->tax_group_code === 'PHYSICAL_TPP'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'SC' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'SC' && $row->tax_group_code === 'INFORMATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'VA' && $row->tax_group_code === 'FOOD_GROCERY'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'VA' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'VA' && $row->tax_group_code === 'HUMAN_PROFESSIONAL_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'WA' && $row->tax_group_code === 'GIFT_CARD_STORED_VALUE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'WA' && $row->tax_group_code === 'RETAIL_REPAIR_INSTALLATION_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'WA' && $row->tax_group_code === 'INFORMATION_TECHNOLOGY_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'WA' && $row->tax_group_code === 'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'TX' && $row->tax_group_code === 'DATA_PROCESSING_SERVICE'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'TX' && $row->tax_group_code === 'PREWRITTEN_SOFTWARE_ELECTRONIC'));
        $this->assertTrue($rows->contains(fn ($row) => $row->state_code === 'TX' && $row->tax_group_code === 'SAAS_REMOTE_ACCESS'));
        $this->assertTrue($rows->every(fn ($row) => $row->source_name && $row->parser_name && $row->description && $row->tax_group_description));
    }

    public function test_it_deletes_selected_staging_records(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['CA', 'OR']);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)->limit(2)->pluck('id')->all();

        $deleted = app(UsaTaxImporter::class)->deleteStaging($ids);

        $this->assertSame(2, $deleted);
        $this->assertSame(0, UsaTaxImportStaging::whereIn('id', $ids)->count());
        $this->assertGreaterThan(0, UsaTaxImportStaging::where('batch_id', $batchId)->count());
    }

    public function test_it_imports_selected_staging_records_into_main_taxes_table(): void
    {
        $batchId = app(UsaTaxImporter::class)->stageStates(['CA']);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)
            ->where('status', UsaTaxImportStaging::STATUS_PARSED)
            ->pluck('id')
            ->all();

        $count = app(UsaTaxImporter::class)->importStaging($ids);

        $this->assertGreaterThan(0, $count);
        $this->assertDatabaseHas('kodzero_posmall_taxes', [
            'state_code' => 'CA',
            'tax_group_code' => 'PHYSICAL_TPP',
        ]);
        $this->assertSame(0, UsaTaxImportStaging::whereIn('id', $ids)->where('status', '!=', UsaTaxImportStaging::STATUS_IMPORTED)->count());
    }

    public function test_import_preserves_taxability_mode_in_live_tax_and_region_rows(): void
    {
        $row = $this->staging('GA', 'DIGITAL_FILE_ONLY', 8.0, 'GA-800', '30001-30002');
        $row->taxability_mode = 'sst_partial_taxable_review';
        $row->save();

        app(UsaTaxImporter::class)->importStaging([$row->id]);

        $tax = Tax::where('state_code', 'GA')->where('tax_group_code', 'DIGITAL_FILE_ONLY')->first();

        $this->assertSame('sst_partial_taxable_review', $tax->taxability_mode);
        $this->assertDatabaseHas('kodzero_posmall_usa_tax_region_rows', [
            'tax_id' => $tax->id,
            'tax_group_code' => 'DIGITAL_FILE_ONLY',
            'taxability_mode' => 'sst_partial_taxable_review',
            'zip_from' => '30001',
            'zip_to' => '30002',
        ]);
    }

    public function test_it_records_history_when_imported_rate_changes(): void
    {
        $tax = Tax::create([
            'name' => 'Old California Physical Goods',
            'percentage' => 7.00,
            'rate_percent' => 7.00,
            'state_code' => 'CA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'is_enabled' => true,
            'is_active' => true,
        ]);

        $batchId = app(UsaTaxImporter::class)->stageStates(['CA']);
        $ids = UsaTaxImportStaging::where('batch_id', $batchId)
            ->where('state_code', 'CA')
            ->where('tax_group_code', 'PHYSICAL_TPP')
            ->pluck('id')
            ->all();

        app(UsaTaxImporter::class)->importStaging($ids);

        $this->assertSame(7.25, (float)$tax->fresh()->rate_percent);
        $this->assertDatabaseHas('kodzero_posmall_usa_tax_histories', [
            'tax_id' => $tax->id,
            'state_code' => 'CA',
            'tax_group_code' => 'PHYSICAL_TPP',
        ]);
        $this->assertSame(7.0, (float)UsaTaxHistory::where('tax_id', $tax->id)->first()->old_rate_percent);
    }

    public function test_automatic_update_imports_only_opted_in_source_backed_live_taxes(): void
    {
        $enabled = Tax::create([
            'name' => 'WA opted in source tax',
            'percentage' => 9.70,
            'rate_percent' => 9.70,
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_code' => 'WA-AUTO-YES',
            'source_url' => 'https://example.test/wa',
            'source_type' => 'CSV',
            'source_name' => 'WA source',
            'parser_name' => 'TestParser',
            'source_hash' => 'old-enabled',
            'usa_auto_update_enabled' => true,
            'is_enabled' => true,
            'is_active' => true,
        ]);
        $disabled = Tax::create([
            'name' => 'WA not opted in source tax',
            'percentage' => 9.70,
            'rate_percent' => 9.70,
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_code' => 'WA-AUTO-NO',
            'source_url' => 'https://example.test/wa',
            'source_type' => 'CSV',
            'source_name' => 'WA source',
            'parser_name' => 'TestParser',
            'source_hash' => 'old-disabled',
            'usa_auto_update_enabled' => false,
            'is_enabled' => true,
            'is_active' => true,
        ]);

        $enabledRow = $this->staging('WA', 'PHYSICAL_TPP', 9.90, 'WA-AUTO-YES', '98501');
        $disabledRow = $this->staging('WA', 'PHYSICAL_TPP', 9.90, 'WA-AUTO-NO', '98502');

        $count = app(UsaTaxImporter::class)->importStaging([$enabledRow->id, $disabledRow->id], true);

        $this->assertSame(1, $count);
        $this->assertSame(9.90, (float)$enabled->fresh()->rate_percent);
        $this->assertSame(9.70, (float)$disabled->fresh()->rate_percent);
        $this->assertSame(UsaTaxImportStaging::STATUS_IMPORTED, $enabledRow->fresh()->status);
        $this->assertSame(UsaTaxImportStaging::STATUS_SKIPPED, $disabledRow->fresh()->status);
    }

    public function test_automatic_update_skips_positive_to_zero_source_rate_changes(): void
    {
        $tax = Tax::create([
            'name' => 'WA positive source tax',
            'percentage' => 9.70,
            'rate_percent' => 9.70,
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_code' => 'WA-ZERO-GUARD',
            'source_url' => 'https://example.test/wa',
            'source_type' => 'CSV',
            'source_name' => 'WA source',
            'parser_name' => 'TestParser',
            'source_hash' => 'old-zero-guard',
            'usa_auto_update_enabled' => true,
            'is_enabled' => true,
            'is_active' => true,
        ]);
        $row = $this->staging('WA', 'PHYSICAL_TPP', 0.0, 'WA-ZERO-GUARD', '98503');
        $row->state_rate_percent = 0;
        $row->local_rate_percent = 0;
        $row->save();

        $count = app(UsaTaxImporter::class)->importStaging([$row->id], true);

        $this->assertSame(0, $count);
        $this->assertSame(9.70, (float)$tax->fresh()->rate_percent);
        $this->assertSame(UsaTaxImportStaging::STATUS_SKIPPED, $row->fresh()->status);
        $this->assertStringContainsString('positive-to-zero', $row->fresh()->error_message);
    }

    public function test_manual_reviewed_import_can_still_import_zero_rate_rows(): void
    {
        $tax = Tax::create([
            'name' => 'WA manually reviewed source tax',
            'percentage' => 9.70,
            'rate_percent' => 9.70,
            'state_code' => 'WA',
            'tax_group_code' => 'PHYSICAL_TPP',
            'jurisdiction_code' => 'WA-MANUAL-ZERO',
            'source_url' => 'https://example.test/wa',
            'source_type' => 'CSV',
            'source_name' => 'WA source',
            'parser_name' => 'TestParser',
            'source_hash' => 'old-manual-zero',
            'usa_auto_update_enabled' => true,
            'is_enabled' => true,
            'is_active' => true,
        ]);
        $row = $this->staging('WA', 'PHYSICAL_TPP', 0.0, 'WA-MANUAL-ZERO', '98504');
        $row->state_rate_percent = 0;
        $row->local_rate_percent = 0;
        $row->save();

        $count = app(UsaTaxImporter::class)->importStaging([$row->id]);

        $this->assertSame(1, $count);
        $this->assertSame(0.0, (float)$tax->fresh()->rate_percent);
        $this->assertSame(UsaTaxImportStaging::STATUS_IMPORTED, $row->fresh()->status);
    }
}
