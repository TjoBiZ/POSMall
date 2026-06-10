<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Classes\Properties\ProductPropertyBlueprints;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;

class SeedCommonProperties extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = '
        posmall:seed-common-properties
        {--skip-category-assignment : Do not assign groups to local development categories}
        {--force : Allow execution outside local/dev/testing environments}
    ';

    /**
     * The console command description.
     * @var string|null
     */
    protected $description = 'Create additive common POSMall property groups and properties.';

    public function handle(): int
    {
        if (!$this->isSafeEnvironment() && !$this->option('force')) {
            $this->error('This command is intended for local/dev/testing environments. Use --force only after review.');

            return 1;
        }

        $createdGroups = 0;
        $createdProperties = 0;
        $createdLinks = 0;
        $createdAssignments = 0;

        $groups = [];

        foreach (ProductPropertyBlueprints::groups() as $groupData) {
            [$group, $groupCreated] = $this->findOrCreateGroup($groupData);
            $groups[$groupData['slug']] = $group;
            $createdGroups += $groupCreated ? 1 : 0;

            foreach ($groupData['properties'] as $index => $propertyData) {
                [$property, $propertyCreated, $compatible] = $this->findOrCreateProperty($propertyData);
                $createdProperties += $propertyCreated ? 1 : 0;

                if (!$compatible) {
                    continue;
                }

                if ($this->attachProperty($group, $property, $propertyData, $index)) {
                    $createdLinks++;
                }
            }
        }

        if (!$this->option('skip-category-assignment')) {
            $createdAssignments = $this->assignDevCategories($groups);
        }

        $this->info(sprintf(
            'Common property setup complete. Groups created: %d. Properties created: %d. Group links created: %d. Category assignments created: %d.',
            $createdGroups,
            $createdProperties,
            $createdLinks,
            $createdAssignments
        ));

        return 0;
    }

    private function isSafeEnvironment(): bool
    {
        return app()->environment(['local', 'dev', 'development', 'testing']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: PropertyGroup, 1: bool}
     */
    private function findOrCreateGroup(array $data): array
    {
        $group = PropertyGroup::where('slug', $data['slug'])->first();

        if ($group) {
            return [$group, false];
        }

        $group = new PropertyGroup();
        $group->fill([
            'name' => $data['name'],
            'display_name' => $data['display_name'],
            'slug' => $data['slug'],
            'description' => $data['description'],
        ]);
        $group->save();

        return [$group, true];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: Property, 1: bool, 2: bool}
     */
    private function findOrCreateProperty(array $data): array
    {
        $property = Property::where('slug', $data['slug'])->first();

        if ($property) {
            if ($property->type !== $data['type']) {
                $this->warn(sprintf(
                    'Property "%s" already exists with type "%s"; expected "%s". Existing property was not changed.',
                    $data['slug'],
                    $property->type,
                    $data['type']
                ));

                return [$property, false, false];
            }

            return [$property, false, true];
        }

        $property = new Property();
        $property->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'type' => $data['type'],
            'unit' => $data['unit'],
            'options' => $this->optionRows($data['options']),
        ]);
        $property->save();

        return [$property, true, true];
    }

    private function attachProperty(PropertyGroup $group, Property $property, array $data, int $index): bool
    {
        if ($group->properties()->where('kodzero_posmall_properties.id', $property->id)->exists()) {
            return false;
        }

        $group->properties()->attach($property->id, [
            'use_for_variants' => $data['useForVariants'],
            'filter_type' => $data['filterType'],
            'sort_order' => ($index + 1) * 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * @param array<string, PropertyGroup> $groups
     */
    private function assignDevCategories(array $groups): int
    {
        $assignments = 0;
        $groupSlugs = ProductPropertyBlueprints::devCategoryAssignmentGroupSlugs();

        $devCategories = Category::where('slug', 'like', 'dev-%')
            ->where('inherit_property_groups', false)
            ->orderBy('id')
            ->get();

        foreach ($devCategories as $category) {
            foreach ($groupSlugs as $index => $slug) {
                if (!isset($groups[$slug])) {
                    continue;
                }

                $group = $groups[$slug];

                if ($category->property_groups()->where('kodzero_posmall_property_groups.id', $group->id)->exists()) {
                    continue;
                }

                $category->property_groups()->attach($group->id, [
                    'relation_sort_order' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $assignments++;
            }
        }

        return $assignments;
    }

    private function optionRows(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(fn (string $value): array => ['value' => $value], $values);
    }
}
