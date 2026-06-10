<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use KodZero\POSMall\Classes\Taxes\UsaAddressZipSuggester;
use KodZero\POSMall\Classes\Taxes\UspsAddressZipProvider;
use KodZero\POSMall\Models\GeneralSettings;

class UsaAddressZipSuggesterTest extends UsaTaxTestCase
{
    protected int $countryId;

    protected int $washingtonId;

    public function setUp(): void
    {
        parent::setUp();

        $this->createLocationTables();
        $this->disableUspsLookup();
    }

    public function tearDown(): void
    {
        $this->disableUspsLookup();

        parent::tearDown();
    }

    public function test_returns_no_suggestions_when_zip_coverage_is_not_loaded(): void
    {
        $this->tax('Washington physical', 'WA', 'PHYSICAL_TPP', 10.1);

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '',
        ]);

        $this->assertSame([], $result['suggestions']);
    }

    public function test_suggests_zip_candidates_from_loaded_state_coverage(): void
    {
        $tax = $this->tax('Seattle local tax', 'WA', 'PHYSICAL_TPP', 10.55);
        $tax->zip_code_hints = '98101, 98102, 98104';
        $tax->zip_code_ranges = '98101-98109';
        $tax->save();

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '9810',
        ]);

        $this->assertSame('98101', $result['suggestions'][0]['zip']);
        $this->assertSame('98102', $result['suggestions'][1]['zip']);
        $this->assertSame('98104', $result['suggestions'][2]['zip']);
    }

    public function test_ignores_non_us_country(): void
    {
        $canadaId = DB::table('rainlab_location_countries')->insertGetId([
            'name' => 'Canada',
            'code' => 'ca',
            'is_enabled' => true,
        ]);

        $tax = $this->tax('Washington physical', 'WA', 'PHYSICAL_TPP', 10.1);
        $tax->zip_code_hints = '98104';
        $tax->zip_code_ranges = '98104';
        $tax->save();

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $canadaId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '981',
        ]);

        $this->assertSame([], $result['suggestions']);
    }

    public function test_returns_empty_when_no_matching_state_coverage_exists(): void
    {
        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_code' => 'OR',
            'lines' => '100 Main Street',
            'city' => 'Portland',
            'zip' => '972',
        ]);

        $this->assertSame([], $result['suggestions']);
    }

    public function test_caps_suggestions_to_limit(): void
    {
        $tax = $this->tax('Washington physical', 'WA', 'PHYSICAL_TPP', 10.1);
        $tax->zip_code_ranges = '98101-98120';
        $tax->save();

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '981',
        ]);

        $this->assertCount(8, $result['suggestions']);
    }

    public function test_local_zip_coverage_can_suggest_state_without_preselected_country_or_state(): void
    {
        $tax = $this->tax('Seattle local tax', 'WA', 'PHYSICAL_TPP', 10.55);
        $tax->zip_code_hints = '98104';
        $tax->zip_code_ranges = '98101-98109';
        $tax->save();

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => '',
            'state_id' => '',
            'lines' => '100 Main Street',
            'city' => '',
            'zip' => '98104',
        ]);

        $this->assertSame('98104', $result['suggestions'][0]['zip']);
        $this->assertSame('US', $result['suggestions'][0]['country_code']);
        $this->assertSame($this->countryId, $result['suggestions'][0]['country_id']);
        $this->assertSame('WA', $result['suggestions'][0]['state_code']);
        $this->assertSame($this->washingtonId, $result['suggestions'][0]['state_id']);
    }

    public function test_exact_local_zip_coverage_can_change_from_preselected_state(): void
    {
        $alaskaId = DB::table('rainlab_location_states')->insertGetId([
            'country_id' => $this->countryId,
            'name' => 'Alaska',
            'code' => 'AK',
            'is_enabled' => true,
        ]);

        $washington = $this->tax('Seattle local tax', 'WA', 'PHYSICAL_TPP', 10.55);
        $washington->zip_code_hints = '98104';
        $washington->zip_code_ranges = '98104';
        $washington->save();

        $alaska = $this->tax('Anchorage local tax', 'AK', 'PHYSICAL_TPP', 0);
        $alaska->zip_code_hints = '99501';
        $alaska->zip_code_ranges = '99501';
        $alaska->save();

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '',
            'city' => '',
            'zip' => '99501',
        ]);

        $this->assertSame('99501', $result['suggestions'][0]['zip']);
        $this->assertSame('AK', $result['suggestions'][0]['state_code']);
        $this->assertSame($alaskaId, $result['suggestions'][0]['state_id']);
        $this->assertArrayNotHasKey('city', $result['suggestions'][0]);
    }

    public function test_usps_city_state_lookup_fills_city_and_state_for_exact_zip(): void
    {
        $this->enableUspsLookup('usps-city-state-id', 'usps-city-state-secret');

        Http::fake([
            'https://apis.usps.com/oauth2/v3/token' => Http::response([
                'access_token' => 'token-city-state',
                'expires_in' => 3600,
            ]),
            'https://apis.usps.com/addresses/v3/city-state*' => Http::response([
                'city' => 'SEATTLE',
                'state' => 'WA',
                'ZIPCode' => '98104',
            ]),
        ]);

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => '',
            'lines' => '',
            'city' => '',
            'zip' => '98104',
        ]);

        $this->assertSame('98104', $result['suggestions'][0]['zip']);
        $this->assertSame('SEATTLE', $result['suggestions'][0]['city']);
        $this->assertSame('WA', $result['suggestions'][0]['state_code']);
        $this->assertSame($this->washingtonId, $result['suggestions'][0]['state_id']);
        $this->assertSame('usps_city_state', $result['suggestions'][0]['source']);
    }

    public function test_usps_lookup_returns_primary_zip_plus_four_when_configured(): void
    {
        $this->enableUspsLookup('usps-primary-id', 'usps-primary-secret');

        Http::fake([
            'https://apis.usps.com/oauth2/v3/token' => Http::response([
                'access_token' => 'token-primary',
                'expires_in' => 3600,
            ]),
            'https://apis.usps.com/addresses/v3/address*' => Http::response([
                'address' => [
                    'streetAddress' => '100 MAIN ST',
                    'city' => 'SEATTLE',
                    'state' => 'WA',
                    'ZIPCode' => '98104',
                    'ZIPPlus4' => '1234',
                ],
            ]),
        ]);

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '',
        ]);

        $this->assertSame('98104-1234', $result['suggestions'][0]['zip']);
        $this->assertSame('usps', $result['suggestions'][0]['source']);
    }

    public function test_usps_address_lookup_reuses_successful_response_cache(): void
    {
        $this->enableUspsLookup('usps-cache-id', 'usps-cache-secret');

        $addressCalls = 0;

        Http::fake(function ($request) use (&$addressCalls) {
            if (str_contains($request->url(), '/oauth2/v3/token')) {
                return Http::response([
                    'access_token' => 'token-cache',
                    'expires_in' => 3600,
                ]);
            }

            if (str_contains($request->url(), '/addresses/v3/address')) {
                $addressCalls++;

                return Http::response([
                    'address' => [
                        'streetAddress' => '100 MAIN ST',
                        'city' => 'SEATTLE',
                        'state' => 'WA',
                        'ZIPCode' => '98104',
                        'ZIPPlus4' => '1234',
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $input = [
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '',
        ];

        $first = app(UsaAddressZipSuggester::class)->suggest($input);
        $second = app(UsaAddressZipSuggester::class)->suggest($input);

        $this->assertSame('98104-1234', $first['suggestions'][0]['zip']);
        $this->assertSame($first, $second);
        $this->assertSame(1, $addressCalls);
    }

    public function test_usps_failure_falls_back_to_loaded_local_zip_coverage(): void
    {
        $this->enableUspsLookup('usps-fallback-id', 'usps-fallback-secret');

        $tax = $this->tax('Seattle local tax', 'WA', 'PHYSICAL_TPP', 10.55);
        $tax->zip_code_hints = '98101, 98102, 98104';
        $tax->zip_code_ranges = '98101-98109';
        $tax->save();

        Http::fake([
            'https://apis.usps.com/oauth2/v3/token' => Http::response([
                'access_token' => 'token-fallback',
                'expires_in' => 3600,
            ]),
            'https://apis.usps.com/addresses/v3/address*' => Http::response(['error' => 'temporary'], 500),
        ]);

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '100 Main Street',
            'city' => 'Seattle',
            'zip' => '981',
        ]);

        $this->assertSame('98101', $result['suggestions'][0]['zip']);
        $this->assertSame('98102', $result['suggestions'][1]['zip']);
    }

    public function test_usps_is_not_called_for_incomplete_address_signal(): void
    {
        $this->enableUspsLookup('usps-gated-id', 'usps-gated-secret');

        $tax = $this->tax('Seattle local tax', 'WA', 'PHYSICAL_TPP', 10.55);
        $tax->zip_code_hints = '98101';
        $tax->zip_code_ranges = '98101';
        $tax->save();

        Http::fake(function () {
            throw new \RuntimeException('USPS should not be called for incomplete address input.');
        });

        $result = app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => $this->countryId,
            'state_id' => $this->washingtonId,
            'lines' => '10',
            'city' => 'Se',
            'zip' => '981',
        ]);

        $this->assertSame('98101', $result['suggestions'][0]['zip']);
    }

    protected function createLocationTables(): void
    {
        Schema::dropIfExists('rainlab_location_states');
        Schema::dropIfExists('rainlab_location_countries');

        Schema::create('rainlab_location_countries', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_pinned')->default(false);
        });

        Schema::create('rainlab_location_states', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('country_id')->nullable();
            $table->string('name');
            $table->string('code', 10);
            $table->boolean('is_enabled')->default(true);
        });

        $this->countryId = DB::table('rainlab_location_countries')->insertGetId([
            'name' => 'United States',
            'code' => 'us',
            'is_enabled' => true,
            'is_pinned' => true,
        ]);

        $this->washingtonId = DB::table('rainlab_location_states')->insertGetId([
            'country_id' => $this->countryId,
            'name' => 'Washington',
            'code' => 'WA',
            'is_enabled' => true,
        ]);
    }

    private function enableUspsLookup(string $clientId, string $clientSecret): void
    {
        GeneralSettings::set([
            'usps_addresses_enabled' => true,
            'usps_addresses_environment' => UspsAddressZipProvider::ENVIRONMENT_PRODUCTION,
            'usps_addresses_client_id' => $clientId,
            'usps_addresses_client_secret' => $clientSecret,
        ]);

        app(UspsAddressZipProvider::class)->clearTokenCache();
    }

    private function disableUspsLookup(): void
    {
        GeneralSettings::set([
            'usps_addresses_enabled' => false,
            'usps_addresses_client_id' => '',
            'usps_addresses_client_secret' => '',
        ]);

        app(UspsAddressZipProvider::class)->clearTokenCache();
    }
}
