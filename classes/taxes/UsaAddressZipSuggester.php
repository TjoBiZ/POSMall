<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Illuminate\Support\Facades\Schema;
use KodZero\POSMall\Models\Tax;
use RainLab\Location\Models\Country;
use RainLab\Location\Models\State;

class UsaAddressZipSuggester
{
    private const DEFAULT_LIMIT = 8;

    public function suggest(array $input, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::DEFAULT_LIMIT));
        $prefix = $this->normalizeZipPrefix($input['zip'] ?? null);
        $exactZip = strlen($prefix) === 5;
        $stateCode = $this->stateCode($input);
        $countryId = (int)($input['country_id'] ?? 0);

        if (!$this->hasZipCoverageColumns()) {
            return $this->emptyResponse();
        }

        if ($countryId > 0 && !$this->isUnitedStates($countryId)) {
            return $this->emptyResponse();
        }

        if ($prefix === '' && !$this->hasAddressSignal($input)) {
            return $this->emptyResponse();
        }

        if ($exactZip) {
            $uspsCityStateSuggestions = app(UspsAddressZipProvider::class)->suggestCityStateByZip($prefix);

            if ($uspsCityStateSuggestions) {
                return [
                    'suggestions' => $this->withLocationIds($uspsCityStateSuggestions),
                ];
            }
        }

        $uspsSuggestions = $stateCode
            ? app(UspsAddressZipProvider::class)->suggest($input, $stateCode, $limit)
            : [];

        if ($uspsSuggestions) {
            return [
                'suggestions' => $this->withLocationIds($uspsSuggestions),
            ];
        }

        $suggestions = [];

        Tax::query()
            ->select(['id', 'state_code', 'state_codes', 'zip_code_hints', 'zip_code_ranges'])
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->whereNotNull('zip_code_hints')
                    ->orWhereNotNull('zip_code_ranges');
            })
            ->when($stateCode && !$exactZip, fn ($query) => $query->stateCodes([$stateCode]))
            ->orderBy('id')
            ->chunk(100, function ($taxes) use (&$suggestions, $prefix, $limit) {
                foreach ($taxes as $tax) {
                    $stateCode = strtoupper((string)$tax->state_code);
                    $locationIds = $this->locationIdsForState($stateCode);

                    foreach ($this->zipCandidates($tax->zip_code_hints, $tax->zip_code_ranges, $prefix, $limit - count($suggestions)) as $zip) {
                        $suggestions[$zip] = [
                            'zip' => $zip,
                            'label' => $zip,
                            'country_code' => 'US',
                            'country_id' => $locationIds['country_id'],
                            'state_code' => $stateCode,
                            'state_id' => $locationIds['state_id'],
                            'source' => 'local_zip_coverage',
                        ];

                        if (count($suggestions) >= $limit) {
                            return false;
                        }
                    }
                }

                return true;
            });

        return [
            'suggestions' => array_values($suggestions),
        ];
    }

    private function emptyResponse(): array
    {
        return [
            'suggestions' => [],
        ];
    }

    private function stateCode(array $input): ?string
    {
        $stateCode = strtoupper(trim((string)($input['state_code'] ?? '')));

        if (preg_match('/^[A-Z]{2}$/', $stateCode)) {
            return $stateCode;
        }

        $stateId = (int)($input['state_id'] ?? 0);

        if ($stateId < 1) {
            return null;
        }

        if (!Schema::hasTable((new State())->getTable())) {
            return null;
        }

        $state = State::query()->find($stateId);
        $stateCode = strtoupper(trim((string)optional($state)->code));

        return preg_match('/^[A-Z]{2}$/', $stateCode) ? $stateCode : null;
    }

    private function isUnitedStates($countryId): bool
    {
        $countryId = (int)$countryId;

        if ($countryId < 1) {
            return false;
        }

        if (!Schema::hasTable((new Country())->getTable())) {
            return false;
        }

        $country = Country::query()->find($countryId);

        if (!$country) {
            return false;
        }

        $code = strtolower(trim((string)$country->code));
        $name = strtolower(trim((string)$country->name));

        return in_array($code, ['us', 'usa', 'united-states', 'united_states'], true)
            || $name === 'united states'
            || $name === 'united states of america';
    }

    private function withLocationIds(array $suggestions): array
    {
        return array_map(function (array $suggestion) {
            $stateCode = strtoupper((string)($suggestion['state_code'] ?? ''));
            $locationIds = $this->locationIdsForState($stateCode);

            $suggestion['country_code'] = $suggestion['country_code'] ?? 'US';
            $suggestion['country_id'] = $suggestion['country_id'] ?? $locationIds['country_id'];
            $suggestion['state_id'] = $suggestion['state_id'] ?? $locationIds['state_id'];

            return $suggestion;
        }, $suggestions);
    }

    private function locationIdsForState(string $stateCode): array
    {
        $stateCode = strtoupper(trim($stateCode));
        $countryId = null;
        $stateId = null;

        if (Schema::hasTable((new Country())->getTable())) {
            $country = Country::query()
                ->where(function ($query) {
                    $query->whereIn('code', ['US', 'us', 'USA', 'usa'])
                        ->orWhere('name', 'United States')
                        ->orWhere('name', 'United States of America');
                })
                ->first();

            $countryId = optional($country)->id;
        }

        if ($countryId && $stateCode !== '' && Schema::hasTable((new State())->getTable())) {
            $stateId = State::query()
                ->where('country_id', $countryId)
                ->where('code', $stateCode)
                ->value('id');
        }

        return [
            'country_id' => $countryId ? (int)$countryId : null,
            'state_id' => $stateId ? (int)$stateId : null,
        ];
    }

    private function hasAddressSignal(array $input): bool
    {
        foreach (['lines', 'city', 'zip'] as $field) {
            if (mb_strlen(trim((string)($input[$field] ?? ''))) >= 2) {
                return true;
            }
        }

        return false;
    }

    private function normalizeZipPrefix($value): string
    {
        return substr(preg_replace('/\D+/', '', (string)$value), 0, 5);
    }

    private function hasZipCoverageColumns(): bool
    {
        $table = (new Tax())->getTable();

        return Schema::hasTable($table)
            && Schema::hasColumn($table, 'zip_code_hints')
            && Schema::hasColumn($table, 'zip_code_ranges');
    }

    private function zipCandidates($hints, $ranges, string $prefix, int $remaining): array
    {
        if ($remaining < 1) {
            return [];
        }

        $zips = [];

        foreach ($this->zipValues((string)$hints) as $zip) {
            if ($this->matchesPrefix($zip, $prefix)) {
                $zips[$zip] = $zip;
            }

            if (count($zips) >= $remaining) {
                return array_values($zips);
            }
        }

        foreach (preg_split('/\s*,\s*/', (string)$ranges) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $part, $matches)) {
                foreach ($this->zipRangeValues($matches[1], $matches[2], $prefix, $remaining - count($zips)) as $zip) {
                    $zips[$zip] = $zip;
                }
            } elseif (preg_match('/^\d{5}$/', $part) && $this->matchesPrefix($part, $prefix)) {
                $zips[$part] = $part;
            }

            if (count($zips) >= $remaining) {
                return array_values($zips);
            }
        }

        return array_values($zips);
    }

    private function zipValues(string $value): array
    {
        preg_match_all('/\b\d{5}\b/', $value, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function zipRangeValues(string $from, string $to, string $prefix, int $remaining): array
    {
        $from = (int)$from;
        $to = (int)$to;
        $start = min($from, $to);
        $end = max($from, $to);

        if ($prefix !== '') {
            $prefixStart = (int)str_pad($prefix, 5, '0');
            $prefixEnd = (int)str_pad($prefix, 5, '9');
            $start = max($start, $prefixStart);
            $end = min($end, $prefixEnd);
        }

        $zips = [];

        for ($zip = $start; $zip <= $end && count($zips) < $remaining; $zip++) {
            $zips[] = str_pad((string)$zip, 5, '0', STR_PAD_LEFT);
        }

        return $zips;
    }

    private function matchesPrefix(string $zip, string $prefix): bool
    {
        return $prefix === '' || strpos($zip, $prefix) === 0;
    }
}
