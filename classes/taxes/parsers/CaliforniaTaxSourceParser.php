<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;

class CaliforniaTaxSourceParser extends SeedRuleSourceParser
{
    public function parse(array $source, string $stateCode): array
    {
        if (($source['code'] ?? null) !== 'CA_CDTFA_ARCGIS_JSON' || app()->runningUnitTests()) {
            return parent::parse($source, $stateCode);
        }

        return collect(parent::parse($source, $stateCode))
            ->merge($this->jurisdictionRecords($source))
            ->values()
            ->all();
    }

    protected function jurisdictionRecords(array $source): array
    {
        [$groupName, $groupDescription] = UsaTaxSourceRegistry::taxGroups()['PHYSICAL_TPP'];

        return collect($this->features($source))
            ->map(function (array $feature) use ($source, $groupName, $groupDescription) {
                $attributes = $feature['attributes'] ?? [];
                $rate = round(((float)($attributes['RATE'] ?? 0)) * 100, 4);

                if ($rate <= 0) {
                    return null;
                }

                $county = $this->cleanName($attributes['County_name'] ?? null);
                $city = $this->cleanName($attributes['City_Name_Proper'] ?? ($attributes['City_name'] ?? null));
                $jurisdiction = $this->cleanName($attributes['JURIS_NAME'] ?? null) ?: $city ?: $county;
                $area = trim(implode(' / ', array_filter([
                    $county ? $county . ' County' : null,
                    $city ?: $jurisdiction,
                ])));

                return [
                    'state_code' => 'CA',
                    'source_url' => $source['url'] ?? null,
                    'source_type' => $source['type'] ?? 'JSON',
                    'source_name' => $source['name'] ?? 'CDTFA ArcGIS JSON',
                    'parser_name' => class_basename(static::class),
                    'raw_name' => 'California Sales Tax - ' . ($area ?: $jurisdiction),
                    'parsed_name' => 'California Sales Tax - ' . ($area ?: $jurisdiction),
                    'tax_group_code' => 'PHYSICAL_TPP',
                    'tax_group_name' => $groupName,
                    'tax_group_description' => $groupDescription,
                    'jurisdiction_type' => 'city_or_county_polygon',
                    'jurisdiction_name' => $area ?: $jurisdiction,
                    'jurisdiction_code' => 'CA-' . substr(hash('sha1', implode('|', [$county, $city, $jurisdiction, $rate])), 0, 12),
                    'state_rate_percent' => 7.25,
                    'local_rate_percent' => max(0, round($rate - 7.25, 4)),
                    'rate_percent' => $rate,
                    'description' => 'California CDTFA sales/use tax jurisdiction rate. CDTFA states ZIP or mailing address alone may be insufficient; this row is a jurisdiction/rate source, not a 5-digit ZIP boundary.',
                    'info' => 'Coverage: official CDTFA ArcGIS polygon jurisdiction rate. Checkout needs address/geospatial lookup for exact California local tax; no verified 5-digit ZIP range is stored.',
                    'source_hash' => hash('sha256', implode('|', ['CA', 'PHYSICAL_TPP', $county, $city, $jurisdiction, $rate, $source['url'] ?? ''])),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    protected function features(array $source): array
    {
        $baseUrl = preg_replace('/\/query\?.*$/', '/query', (string)($source['url'] ?? ''));
        $features = [];

        for ($offset = 0; $offset < 5000; $offset += 2000) {
            $url = $baseUrl . '?' . http_build_query([
                'where' => '1=1',
                'outFields' => 'JURIS_NAME,County_name,City_name,City_Name_Proper,RATE,DateStamp',
                'returnGeometry' => 'false',
                'f' => 'json',
                'orderByFields' => 'OBJECTID',
                'resultOffset' => $offset,
                'resultRecordCount' => 2000,
            ]);
            $payload = json_decode((string)$this->fetchSource($url), true);
            $page = is_array($payload['features'] ?? null) ? $payload['features'] : [];

            if (!$page) {
                break;
            }

            $features = array_merge($features, $page);

            if (empty($payload['exceededTransferLimit'])) {
                break;
            }
        }

        return $features;
    }

    protected function cleanName($value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', ucwords(strtolower((string)$value))));

        return $value !== '' ? $value : null;
    }
}
