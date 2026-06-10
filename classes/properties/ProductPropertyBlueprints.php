<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Properties;

class ProductPropertyBlueprints
{
    /**
     * Declarative product property groups used by local/dev setup and tests.
     *
     * These are definitions only. They do not create database records by
     * themselves and must not become a parallel property system.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'name' => 'General attributes',
                'display_name' => 'General attributes',
                'slug' => 'common-general-attributes',
                'profile' => 'physical',
                'description' => 'Common customer-facing attributes for physical catalogue items.',
                'properties' => [
                    self::property('Color', 'common-color', 'color', null, null, 'set', true),
                    self::property('Material', 'common-material', 'dropdown', null, [
                        'Aluminum',
                        'Steel',
                        'Plastic',
                        'Wood',
                        'Fabric',
                        'Glass',
                        'Cotton',
                        'Polyester',
                        'Not applicable',
                    ], 'set'),
                    self::property('Size', 'common-size', 'dropdown', null, [
                        'XS',
                        'S',
                        'M',
                        'L',
                        'XL',
                        'One size',
                        'Not applicable',
                    ], 'set', true),
                    self::property('Gender/Audience', 'common-gender-audience', 'dropdown', null, [
                        'Unisex',
                        'Men',
                        'Women',
                        'Kids',
                        'Not applicable',
                    ], 'set'),
                    self::property('Pattern', 'common-pattern', 'text'),
                    self::property('Finish', 'common-finish', 'text'),
                ],
            ],
            [
                'name' => 'Dimensions',
                'display_name' => 'Dimensions',
                'slug' => 'common-dimensions',
                'profile' => 'physical',
                'description' => 'Numeric dimensions that can be used for comparison and range filtering.',
                'properties' => [
                    self::property('Width', 'common-width', 'float', 'cm', null, 'range'),
                    self::property('Height', 'common-height', 'float', 'cm', null, 'range'),
                    self::property('Length', 'common-length', 'float', 'cm', null, 'range'),
                    self::property('Weight', 'common-weight', 'float', 'kg', null, 'range'),
                ],
            ],
            [
                'name' => 'Apparel basics',
                'display_name' => 'Apparel basics',
                'slug' => 'common-apparel-basics',
                'profile' => 'apparel',
                'description' => 'Reusable attributes for clothing and wearable products.',
                'properties' => [
                    self::property('Fit', 'common-fit', 'dropdown', null, [
                        'Slim',
                        'Regular',
                        'Relaxed',
                        'Compression',
                        'Not applicable',
                    ], 'set'),
                    self::property('Fabric care', 'common-fabric-care', 'text'),
                    self::property('Season', 'common-season', 'dropdown', null, [
                        'Spring',
                        'Summer',
                        'Fall',
                        'Winter',
                        'All season',
                    ], 'set'),
                ],
            ],
            [
                'name' => 'Sportswear performance',
                'display_name' => 'Sportswear performance',
                'slug' => 'common-sportswear-performance',
                'profile' => 'apparel',
                'description' => 'Performance attributes for activewear and sportswear.',
                'properties' => [
                    self::property('Stretch', 'common-stretch', 'dropdown', null, [
                        'None',
                        'Light',
                        'Medium',
                        'High',
                    ], 'set'),
                    self::property('Compression', 'common-compression', 'dropdown', null, [
                        'None',
                        'Light',
                        'Medium',
                        'High',
                    ], 'set'),
                    self::property('Breathability', 'common-breathability', 'dropdown', null, [
                        'Low',
                        'Medium',
                        'High',
                    ], 'set'),
                    self::property('Moisture wicking', 'common-moisture-wicking', 'switch'),
                    self::property('Activity type', 'common-activity-type', 'dropdown', null, [
                        'Running',
                        'Training',
                        'Yoga',
                        'Cycling',
                        'Outdoor',
                    ], 'set'),
                ],
            ],
            [
                'name' => 'Yoga/Fitness extension',
                'display_name' => 'Yoga/Fitness extension',
                'slug' => 'common-yoga-fitness-extension',
                'profile' => 'apparel',
                'description' => 'Specific extension attributes for yoga and fitness clothing.',
                'properties' => [
                    self::property('High-waist', 'common-high-waist', 'switch'),
                    self::property('Seamless', 'common-seamless', 'switch'),
                    self::property('Squat-proof', 'common-squat-proof', 'switch'),
                    self::property('Support level', 'common-support-level', 'dropdown', null, [
                        'Light',
                        'Medium',
                        'High',
                    ], 'set'),
                ],
            ],
            [
                'name' => 'Electronics specs',
                'display_name' => 'Electronics specs',
                'slug' => 'common-electronics-specs',
                'profile' => 'electronics',
                'description' => 'Common technical attributes for electronics and accessories.',
                'properties' => [
                    self::property('Connectivity', 'common-connectivity', 'dropdown', null, [
                        'Wired',
                        'Wi-Fi',
                        'Bluetooth',
                        'USB',
                        'Not applicable',
                    ], 'set'),
                    self::property('Power source', 'common-power-source', 'dropdown', null, [
                        'AC adapter',
                        'Battery',
                        'USB powered',
                        'PoE',
                        'Not applicable',
                    ], 'set'),
                    self::property('Compatibility', 'common-compatibility', 'text'),
                    self::property('Warranty', 'common-warranty', 'text'),
                ],
            ],
            [
                'name' => 'Wireless/Bluetooth extension',
                'display_name' => 'Wireless/Bluetooth extension',
                'slug' => 'common-wireless-bluetooth-extension',
                'profile' => 'electronics',
                'description' => 'Extension attributes for wireless headphones, speakers and other Bluetooth products.',
                'properties' => [
                    self::property('Bluetooth version', 'common-bluetooth-version', 'text'),
                    self::property('Codec support', 'common-codec-support', 'text'),
                    self::property('Wireless range', 'common-wireless-range', 'float', 'm', null, 'range'),
                    self::property('Battery life', 'common-battery-life', 'float', 'h', null, 'range'),
                    self::property('Charging port', 'common-charging-port', 'dropdown', null, [
                        'USB-C',
                        'Micro-USB',
                        'Lightning',
                        'Proprietary',
                        'Not applicable',
                    ], 'set'),
                ],
            ],
            [
                'name' => 'Digital delivery',
                'display_name' => 'Digital delivery',
                'slug' => 'common-digital-delivery',
                'profile' => 'digital',
                'description' => 'Delivery and licensing attributes for virtual or downloadable products.',
                'properties' => [
                    self::property('Subscription plan', 'common-subscription-plan', 'dropdown', null, [
                        'Starter',
                        'Professional',
                        'Enterprise',
                    ], 'set', true),
                    self::property('Billing period', 'common-billing-period', 'dropdown', null, [
                        'Monthly',
                        'Annual',
                        'One-time',
                    ], 'set', true),
                    self::property('Seats', 'common-seats', 'integer', null, null, 'range'),
                    self::property('Delivery method', 'common-delivery-format', 'dropdown', null, [
                        'Download',
                        'Email',
                        'Account access',
                    ], 'set'),
                    self::property('File format', 'common-file-format', 'dropdown', null, [
                        'PDF',
                        'ZIP',
                        'MP4',
                        'PNG',
                        'Other',
                    ], 'set'),
                    self::property('License type', 'common-license-type', 'dropdown', null, [
                        'Personal',
                        'Commercial',
                        'Subscription',
                    ], 'set'),
                    self::property('Access duration', 'common-access-duration', 'text'),
                    self::property('Download limit', 'common-download-limit', 'integer', null, null, 'range'),
                    self::property('Platform compatibility', 'common-platform-compatibility', 'text'),
                    self::property('Version', 'common-version', 'text'),
                    self::property('Updates included', 'common-updates-included', 'switch'),
                ],
            ],
            [
                'name' => 'Digital restrictions',
                'display_name' => 'Digital restrictions',
                'slug' => 'common-digital-restrictions',
                'profile' => 'digital',
                'description' => 'Customer-visible usage limits for digital products.',
                'properties' => [
                    self::property('Region lock', 'common-region-lock', 'text'),
                    self::property('Device limit', 'common-device-limit', 'integer', null, null, 'range'),
                    self::property('Account required', 'common-account-required', 'switch'),
                    self::property('Subscription required', 'common-subscription-required', 'switch'),
                ],
            ],
            [
                'name' => 'Service details',
                'display_name' => 'Service details',
                'slug' => 'common-service-details',
                'profile' => 'service',
                'description' => 'Customer-visible descriptive attributes for service catalogue entries.',
                'properties' => [
                    self::property('Delivery mode', 'common-service-delivery-mode', 'dropdown', null, [
                        'Remote',
                        'On-site',
                        'Hybrid',
                    ], 'set'),
                    self::property('Service duration', 'common-service-duration', 'text'),
                    self::property('Appointment required', 'common-appointment-required', 'switch'),
                    self::property('Service area', 'common-service-area', 'text'),
                    self::property('Response time', 'common-response-time', 'text'),
                    self::property('Included support', 'common-included-support', 'textarea'),
                    self::property('Prerequisites', 'common-service-prerequisites', 'textarea'),
                ],
            ],
            [
                'name' => 'Service fulfillment',
                'display_name' => 'Service fulfillment',
                'slug' => 'common-service-fulfillment',
                'profile' => 'service',
                'description' => 'Operational service attributes that can still be shown or filtered when appropriate.',
                'properties' => [
                    self::property('Booking method', 'common-booking-method', 'dropdown', null, [
                        'Online',
                        'Phone',
                        'Email',
                        'Manual coordination',
                    ], 'set'),
                    self::property('Minimum lead time', 'common-minimum-lead-time', 'text'),
                    self::property('Working hours', 'common-working-hours', 'text'),
                    self::property('Cancellation window', 'common-cancellation-window', 'text'),
                    self::property('Customer prerequisites', 'common-customer-prerequisites', 'textarea'),
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function devCategoryAssignmentGroupSlugs(): array
    {
        return [
            'common-general-attributes',
            'common-dimensions',
            'common-electronics-specs',
            'common-digital-delivery',
            'common-digital-restrictions',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function groupSlugs(): array
    {
        return array_map(fn (array $group): string => $group['slug'], self::groups());
    }

    /**
     * @return array<int, string>
     */
    public static function propertySlugs(): array
    {
        $slugs = [];

        foreach (self::groups() as $group) {
            foreach ($group['properties'] as $property) {
                $slugs[] = $property['slug'];
            }
        }

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private static function property(
        string $name,
        string $slug,
        string $type,
        ?string $unit = null,
        ?array $options = null,
        ?string $filterType = null,
        bool $useForVariants = false
    ): array {
        return compact('name', 'slug', 'type', 'unit', 'options', 'filterType', 'useForVariants');
    }
}
