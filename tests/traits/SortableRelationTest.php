<?php

namespace KodZero\POSMall\Tests\Models;

use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;
use KodZero\POSMall\Tests\PluginTestCase;

class SortableRelationTest extends PluginTestCase
{
    protected $category;

    protected $propertyGroup;

    protected $properties;

    public function setUp(): void
    {
        parent::setUp();

        $category       = new Category();
        $category->name = 'Sortable category';
        $category->slug = 'sortable-category';
        $category->save();

        $group       = new PropertyGroup();
        $group->name = 'Sortable group';
        $group->save();

        $this->properties = collect(['One', 'Two', 'Three'])->map(function (string $name) use ($group) {
            $property       = new Property();
            $property->name = $name;
            $property->type = 'integer';
            $property->save();

            $group->properties()->attach($property->id);

            return $property;
        });

        $category->property_groups()->attach($group->id);

        $this->category = $category->fresh();
        $this->propertyGroup = $group->fresh('properties');
    }

    public function test_it_handles_initial_sort_order()
    {
        $propertyGroup = new PropertyGroup();
        $propertyGroup->name = 'Testgroup';
        $propertyGroup->save();
        $this->category->property_groups()->attach($propertyGroup->id);

        $this->markTestIncomplete('Initial order is not yet set properly');

        // $this->assertEquals(4, $this->category->property_groups->first()->pivot->sort_order);
    }

    public function test_it_sets_relation_order()
    {
        $ids = $this->properties->pluck('id')->all();
        $newOrder = array_reverse($ids);

        $this->propertyGroup->setSortableRelationOrder('properties', $newOrder, $ids);

        $order = $this->propertyGroup->fresh('properties')->properties->pluck('pivot.sort_order', 'id');
        $this->assertEquals([
            $ids[0] => 3,
            $ids[1] => 2,
            $ids[2] => 1,
        ], $order->toArray());
    }
}
