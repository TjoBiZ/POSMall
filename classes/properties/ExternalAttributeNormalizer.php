<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Properties;

class ExternalAttributeNormalizer
{
    /**
     * @var array<string, string>
     */
    private $aliases = [
        'colour' => 'color',
        'color' => 'color',
        'audience' => 'gender-audience',
        'gender' => 'gender-audience',
        'gender-audience' => 'gender-audience',
        'bt' => 'bluetooth',
        'bt-version' => 'bluetooth',
        'bluetooth' => 'bluetooth',
        'bluetooth-version' => 'bluetooth-version',
        'delivery-format' => 'delivery-format',
        'delivery-method' => 'delivery-format',
        'platform' => 'platform-compatibility',
        'os' => 'platform-compatibility',
    ];

    /**
     * @var array<string, string>
     */
    private $colorNames = [
        'black' => '#000000',
        'white' => '#ffffff',
        'red' => '#ff0000',
        'green' => '#008000',
        'blue' => '#0000ff',
        'yellow' => '#ffff00',
        'gray' => '#808080',
        'grey' => '#808080',
        'silver' => '#c0c0c0',
        'gold' => '#ffd700',
    ];

    /**
     * Normalize an external attribute label to a canonical internal slug fragment.
     *
     * @param array<string, mixed> $context
     */
    public function normalizeName(string $name, array $context = []): string
    {
        $slug = $this->slug($name);
        $slug = $this->aliases[$slug] ?? $slug;

        if ($slug === 'bluetooth') {
            return $this->resolveBluetoothSlug($context);
        }

        return $slug;
    }

    /**
     * Normalize a value for deterministic matching or admin review.
     *
     * @param mixed $value
     * @return mixed
     */
    public function normalizeValue($value, ?string $type = null, ?string $propertySlug = null)
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($type === 'color' || $propertySlug === 'color' || $propertySlug === 'common-color') {
            return $this->normalizeColorValue($value);
        }

        $boolean = $this->normalizeBooleanValue($value);
        if ($boolean !== null) {
            return $boolean;
        }

        if ($type === 'integer') {
            return is_numeric($value) ? (int)$value : $value;
        }

        if ($type === 'float') {
            return is_numeric($value) ? (float)$value : $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return strpos($value, '.') === false ? (int)$value : (float)$value;
        }

        return $value;
    }

    /**
     * Match external attributes against known property slugs without writing to the database.
     *
     * @param array<string, mixed> $attributes
     * @param array<int|string, mixed> $knownPropertySlugs
     * @param array<string, mixed> $context
     * @return array{matches: array<int, array<string, mixed>>, suggestions: array<int, array<string, mixed>>}
     */
    public function matchAttributes(array $attributes, array $knownPropertySlugs, array $context = []): array
    {
        $knownPropertySlugs = $this->normalizeKnownSlugs($knownPropertySlugs);
        $matches = [];
        $suggestions = [];

        foreach ($attributes as $sourceName => $value) {
            $normalizedSlug = $this->normalizeName((string)$sourceName, $context + [
                'known_property_slugs' => $knownPropertySlugs,
            ]);
            $matchSlug = $this->findKnownSlug($normalizedSlug, $knownPropertySlugs);
            $normalizedValue = $this->normalizeValue($value, null, $matchSlug ?: $normalizedSlug);

            if ($matchSlug !== null) {
                $matches[] = [
                    'source_name' => (string)$sourceName,
                    'normalized_slug' => $normalizedSlug,
                    'property_slug' => $matchSlug,
                    'source_value' => $value,
                    'normalized_value' => $normalizedValue,
                ];

                continue;
            }

            $suggestions[] = [
                'source_name' => (string)$sourceName,
                'normalized_slug' => $normalizedSlug,
                'source_value' => $value,
                'normalized_value' => $normalizedValue,
                'reason' => 'No matching property slug was found.',
            ];
        }

        return [
            'matches' => $matches,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param mixed $value
     * @return array{hex: string, name: string}|mixed
     */
    public function normalizeColorValue($value)
    {
        if (is_array($value)) {
            return [
                'hex' => isset($value['hex']) ? strtolower((string)$value['hex']) : '',
                'name' => isset($value['name']) ? trim((string)$value['name']) : '',
            ];
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (preg_match('/^#?[0-9a-f]{6}$/i', $trimmed) === 1) {
            $hex = strtolower($trimmed);
            $hex = $hex[0] === '#' ? $hex : '#' . $hex;

            return [
                'hex' => $hex,
                'name' => '',
            ];
        }

        $key = strtolower($trimmed);

        if (isset($this->colorNames[$key])) {
            return [
                'hex' => $this->colorNames[$key],
                'name' => $this->normalizeDisplayValue($trimmed),
            ];
        }

        return [
            'hex' => '',
            'name' => $this->normalizeDisplayValue($trimmed),
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeBooleanValue($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveBluetoothSlug(array $context): string
    {
        $known = $context['known_property_slugs'] ?? [];

        if (in_array('common-bluetooth-version', $known, true)) {
            return 'bluetooth-version';
        }

        if (($context['target'] ?? null) === 'connectivity') {
            return 'connectivity';
        }

        if (($context['group_slug'] ?? null) === 'common-electronics-specs') {
            return 'connectivity';
        }

        return 'bluetooth-version';
    }

    /**
     * @param array<int|string, mixed> $knownPropertySlugs
     * @return array<int, string>
     */
    private function normalizeKnownSlugs(array $knownPropertySlugs): array
    {
        $slugs = [];

        foreach ($knownPropertySlugs as $key => $value) {
            $slugs[] = is_string($key) ? $key : (string)$value;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @param array<int, string> $knownPropertySlugs
     */
    private function findKnownSlug(string $normalizedSlug, array $knownPropertySlugs): ?string
    {
        $candidates = [
            $normalizedSlug,
            'common-' . $normalizedSlug,
        ];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $knownPropertySlugs, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '+'], ' and ', $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';

        return trim($value, '-');
    }

    private function normalizeDisplayValue(string $value): string
    {
        return ucwords(strtolower(trim($value)));
    }
}
