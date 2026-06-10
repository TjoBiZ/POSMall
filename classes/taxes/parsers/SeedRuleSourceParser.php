<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;

abstract class SeedRuleSourceParser implements UsaTaxSourceParser
{
    public function parse(array $source, string $stateCode): array
    {
        $groups = UsaTaxSourceRegistry::taxGroups();
        $stateCode = strtoupper($stateCode);

        return collect(UsaTaxSourceRegistry::seedRules())
            ->filter(fn ($rule) => $rule[0] === $stateCode && $rule[3] === $source['code'])
            ->map(function ($rule) use ($source, $groups) {
                [$state, $groupCode, $rate, $sourceCode, $name] = $rule;
                [$groupName, $groupDescription] = $groups[$groupCode] ?? [$groupCode, null];
                $rate = $this->rateFromSource($source, (float)$rate);
                $payload = implode('|', [$state, $groupCode, $rate, $source['url'] ?? '', $sourceCode]);

                return [
                    'state_code' => $state,
                    'source_url' => $source['url'] ?? null,
                    'source_type' => $source['type'] ?? 'MANUAL',
                    'source_name' => $source['name'] ?? $source['code'],
                    'parser_name' => class_basename(static::class),
                    'raw_name' => $name,
                    'parsed_name' => $name,
                    'tax_group_code' => $groupCode,
                    'tax_group_name' => $groupName,
                    'tax_group_description' => $groupDescription,
                    'rate_percent' => $rate,
                    'description' => $this->description($state, $groupCode) . ' Item examples: ' . UsaTaxSourceRegistry::taxGroupExamples($groupCode),
                    'info' => sprintf(
                        'Coverage: statewide/base %s tax record. ZIP ranges are intentionally empty; checkout matches this row by state code and uses local ZIP ranges only when a verified regional row exists.',
                        $state
                    ),
                    'source_hash' => hash('sha256', $payload),
                ];
            })
            ->values()
            ->all();
    }

    protected function rateFromSource(array $source, float $fallback): float
    {
        if (empty($source['allow_remote_rate_fetch']) || app()->runningUnitTests() || empty($source['rate_regex']) || empty($source['url'])) {
            return $fallback;
        }

        $contents = $this->fetchSource((string)$source['url']);
        if (!$contents || !preg_match((string)$source['rate_regex'], $contents, $matches) || !isset($matches[1])) {
            $contents = $contents ? strip_tags($contents) : null;
        }

        if (!$contents || !preg_match((string)$source['rate_regex'], $contents, $matches) || !isset($matches[1])) {
            return $fallback;
        }

        return (float)str_replace(',', '.', $matches[1]);
    }

    protected function fetchSource(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'user_agent' => 'POSMall USA Tax Helper',
                'ignore_errors' => true,
            ],
        ]);

        $previous = set_error_handler(static fn () => true);

        try {
            $contents = file_get_contents($url, false, $context);
        } finally {
            if ($previous) {
                set_error_handler($previous);
            } else {
                restore_error_handler();
            }
        }

        return is_string($contents) ? $contents : null;
    }

    protected function description(string $state, string $groupCode): string
    {
        if ($state === 'OR') {
            return 'Oregon has no general sales tax. This zero-rate record is useful for fallback and state restriction rules.';
        }

        if ($state === 'CA' && in_array($groupCode, [
            'DIGITAL_AUTOMATED_SERVICE',
            'HUMAN_PROFESSIONAL_SERVICE',
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
            'DATA_PROCESSING_SERVICE',
            'INFORMATION_SERVICE',
        ], true)) {
            return 'California CDTFA guidance says sales tax does not apply to charges for services unless the services are part of a sale of tangible personal property. Treat this as a pure-service zero-rate fallback and review mixed tangible/service invoices manually.';
        }

        if ($groupCode === 'GIFT_CARD_STORED_VALUE') {
            return 'Gift card tax is deferred until redemption when redeemed for taxable goods or services.';
        }

        return 'Parsed into POSMall normalized USA tax format from an official-source adapter. Review taxability and local-rate rules before production use.';
    }
}
