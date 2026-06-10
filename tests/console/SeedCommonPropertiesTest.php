<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use KodZero\POSMall\Classes\Properties\ExternalAttributeNormalizer;
use KodZero\POSMall\Classes\Properties\ProductPropertyBlueprints;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;
use KodZero\POSMall\Models\PropertyValue;
use KodZero\POSMall\Tests\PluginTestCase;

class SeedCommonPropertiesTest extends PluginTestCase
{
    public function test_blueprint_slugs_are_unique_and_include_required_profiles(): void
    {
        $groupSlugs = ProductPropertyBlueprints::groupSlugs();
        $propertySlugs = ProductPropertyBlueprints::propertySlugs();

        $this->assertSame($groupSlugs, array_values(array_unique($groupSlugs)));
        $this->assertSame($propertySlugs, array_values(array_unique($propertySlugs)));

        $this->assertContains('common-general-attributes', $groupSlugs);
        $this->assertContains('common-apparel-basics', $groupSlugs);
        $this->assertContains('common-electronics-specs', $groupSlugs);
        $this->assertContains('common-digital-delivery', $groupSlugs);
        $this->assertContains('common-digital-restrictions', $groupSlugs);
        $this->assertContains('common-service-details', $groupSlugs);
        $this->assertContains('common-service-fulfillment', $groupSlugs);

        $this->assertContains('common-color', $propertySlugs);
        $this->assertContains('common-bluetooth-version', $propertySlugs);
        $this->assertContains('common-subscription-plan', $propertySlugs);
        $this->assertContains('common-billing-period', $propertySlugs);
        $this->assertContains('common-seats', $propertySlugs);
        $this->assertContains('common-file-format', $propertySlugs);
        $this->assertContains('common-service-delivery-mode', $propertySlugs);
    }

    public function test_seed_common_properties_is_idempotent_and_assigns_dev_categories(): void
    {
        $category = Category::firstOrCreate(
            ['slug' => 'dev-common-properties-test'],
            [
                'name' => 'Dev Common Properties Test',
                'code' => 'dev-common-properties-test',
                'inherit_property_groups' => false,
                'inherit_review_categories' => false,
            ]
        );

        $this->assertSame(0, Artisan::call('posmall:seed-common-properties'));
        $this->assertSame(0, Artisan::call('posmall:seed-common-properties'));

        $groupSlugs = [
            'common-general-attributes',
            'common-dimensions',
            'common-apparel-basics',
            'common-sportswear-performance',
            'common-yoga-fitness-extension',
            'common-electronics-specs',
            'common-wireless-bluetooth-extension',
            'common-digital-delivery',
            'common-digital-restrictions',
            'common-service-details',
            'common-service-fulfillment',
        ];

        foreach ($groupSlugs as $slug) {
            $this->assertSame(1, PropertyGroup::where('slug', $slug)->count(), $slug);
        }

        foreach (['common-color', 'common-material', 'common-gender-audience', 'common-width'] as $slug) {
            $this->assertSame(1, Property::where('slug', $slug)->count(), $slug);
        }

        $category->refresh();
        $assignedGroupSlugs = $category->property_groups()->pluck('slug')->all();

        $this->assertContains('common-general-attributes', $assignedGroupSlugs);
        $this->assertContains('common-dimensions', $assignedGroupSlugs);
        $this->assertContains('common-electronics-specs', $assignedGroupSlugs);
        $this->assertContains('common-digital-delivery', $assignedGroupSlugs);
        $this->assertContains('common-digital-restrictions', $assignedGroupSlugs);
        $this->assertNotContains('common-wireless-bluetooth-extension', $assignedGroupSlugs);
        $this->assertNotContains('common-service-details', $assignedGroupSlugs);
    }

    public function test_seed_common_properties_does_not_overwrite_incompatible_existing_property(): void
    {
        $property = new Property();
        $property->fill([
            'name' => 'Existing Bluetooth Version',
            'slug' => 'common-bluetooth-version',
            'type' => 'integer',
        ]);
        $property->save();

        $this->assertSame(0, Artisan::call('posmall:seed-common-properties', [
            '--skip-category-assignment' => true,
        ]));

        $property->refresh();
        $group = PropertyGroup::where('slug', 'common-wireless-bluetooth-extension')->firstOrFail();

        $this->assertSame('Existing Bluetooth Version', $property->name);
        $this->assertSame('integer', $property->type);
        $this->assertStringContainsString('already exists with type "integer"; expected "text"', Artisan::output());
        $this->assertFalse($group->properties()->where('kodzero_posmall_properties.id', $property->id)->exists());
    }

    public function test_digital_and_service_property_groups_can_be_created_and_read(): void
    {
        $this->assertSame(0, Artisan::call('posmall:seed-common-properties', [
            '--skip-category-assignment' => true,
        ]));

        $digital = PropertyGroup::where('slug', 'common-digital-delivery')->firstOrFail();
        $service = PropertyGroup::where('slug', 'common-service-fulfillment')->firstOrFail();

        $this->assertContains('common-subscription-plan', $digital->properties()->pluck('slug')->all());
        $this->assertContains('common-billing-period', $digital->properties()->pluck('slug')->all());
        $this->assertContains('common-seats', $digital->properties()->pluck('slug')->all());
        $this->assertContains('common-file-format', $digital->properties()->pluck('slug')->all());
        $this->assertContains('common-booking-method', $service->properties()->pluck('slug')->all());
    }

    public function test_external_attribute_normalizer_maps_aliases_and_values(): void
    {
        $normalizer = new ExternalAttributeNormalizer();

        $this->assertSame('color', $normalizer->normalizeName('Colour'));
        $this->assertSame('gender-audience', $normalizer->normalizeName('Audience'));
        $this->assertSame('gender-audience', $normalizer->normalizeName('Gender'));
        $this->assertSame('bluetooth-version', $normalizer->normalizeName('BT', [
            'known_property_slugs' => ProductPropertyBlueprints::propertySlugs(),
        ]));
        $this->assertSame('connectivity', $normalizer->normalizeName('Bluetooth', [
            'group_slug' => 'common-electronics-specs',
        ]));

        $this->assertSame(true, $normalizer->normalizeValue('yes'));
        $this->assertSame(false, $normalizer->normalizeValue('0'));
        $this->assertSame(42, $normalizer->normalizeValue('42'));
        $this->assertSame(5.5, $normalizer->normalizeValue('5.5'));
        $this->assertSame(['hex' => '#224466', 'name' => ''], $normalizer->normalizeValue('#224466', 'color', 'common-color'));

        $result = $normalizer->matchAttributes([
            'colour' => 'Blue',
            'Mystery attribute' => 'Unknown',
        ], ProductPropertyBlueprints::propertySlugs());

        $this->assertSame('common-color', $result['matches'][0]['property_slug']);
        $this->assertSame('mystery-attribute', $result['suggestions'][0]['normalized_slug']);
    }

    public function test_product_property_values_can_be_created_and_read(): void
    {
        $category = Category::firstOrCreate(
            ['slug' => 'dev-common-properties-values-test'],
            [
                'name' => 'Dev Common Properties Values Test',
                'code' => 'dev-common-properties-values-test',
                'inherit_property_groups' => false,
                'inherit_review_categories' => false,
            ]
        );

        $this->assertSame(0, Artisan::call('posmall:seed-common-properties'));

        $product = new Product();
        $product->fill([
            'name' => 'Common Properties Value Test Product',
            'slug' => 'common-properties-value-test-product',
            'description_short' => 'Property value test product.',
            'description' => 'Property value test product.',
            'inventory_management_method' => 'single',
            'stock' => 5,
            'published' => true,
        ]);
        $product->save();
        $product->categories()->attach($category->id);

        $color = Property::where('slug', 'common-color')->firstOrFail();
        $material = Property::where('slug', 'common-material')->firstOrFail();
        $audience = Property::where('slug', 'common-gender-audience')->firstOrFail();

        $this->savePropertyValue($product, $color, ['hex' => '#224466', 'name' => 'Ocean']);
        $this->savePropertyValue($product, $material, 'Aluminum');
        $this->savePropertyValue($product, $audience, 'Unisex');

        $product = Product::with('categories.property_groups.properties', 'property_values.property.property_groups')
            ->findOrFail($product->id);

        $this->assertSame('Aluminum', $product->getPropertyValueBySlug('common-material')->value);
        $this->assertSame('Unisex', $product->getPropertyValueBySlug('common-gender-audience')->value);
        $this->assertSame('Ocean', $product->getPropertyValueBySlug('common-color')->value['name']);
        $this->assertTrue($product->grouped_properties->count() > 0);
    }

    private function savePropertyValue(Product $product, Property $property, $value): void
    {
        $propertyValue = new PropertyValue();
        $propertyValue->product_id = $product->id;
        $propertyValue->property_id = $property->id;
        $propertyValue->setRelation('property', $property);
        $storedValue = is_array($value) ? json_encode($value) : $value;
        $propertyValue->value = $value;
        $propertyValue->setAttributeTranslated('value', $storedValue, 'en');
        $propertyValue->index_value = str_slug(is_array($value) ? ($value['name'] ?? $value['hex'] ?? '') : (string)$value);
        $propertyValue->save();
    }
}
