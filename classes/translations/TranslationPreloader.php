<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Translations;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RainLab\Translate\Models\Attribute as TranslateAttribute;

class TranslationPreloader
{
    public static function preload($models): void
    {
        if (! class_exists(TranslateAttribute::class)) {
            return;
        }

        $models = self::modelCollection($models)
            ->filter(fn (Model $model) => isset($model->morphMany['translations']) && $model->exists);

        if ($models->isEmpty()) {
            return;
        }

        $models->groupBy(fn (Model $model) => $model->getMorphClass())
            ->each(function (Collection $group, string $type) {
                $ids = $group->pluck($group->first()->getKeyName())
                    ->map(fn ($id) => (string)$id)
                    ->unique()
                    ->values();

                if ($ids->isEmpty()) {
                    return;
                }

                $translations = TranslateAttribute::query()
                    ->where('model_type', $type)
                    ->whereIn('model_id', $ids->all())
                    ->get()
                    ->groupBy(fn (TranslateAttribute $attribute) => (string)$attribute->model_id);

                $group->each(function (Model $model) use ($translations) {
                    $model->setRelation(
                        'translations',
                        new EloquentCollection($translations->get((string)$model->getKey(), collect())->all())
                    );
                });
            });
    }

    public static function preloadNested($models, array $paths): void
    {
        self::preload($models);

        foreach ($paths as $path) {
            self::preload(self::relatedModels($models, explode('.', $path)));
        }
    }

    private static function relatedModels($models, array $segments): Collection
    {
        $current = self::modelCollection($models);

        foreach ($segments as $segment) {
            $current = $current
                ->filter(fn (Model $model) => $model->relationLoaded($segment))
                ->flatMap(fn (Model $model) => self::modelCollection($model->getRelation($segment)));
        }

        return $current;
    }

    private static function modelCollection($models): Collection
    {
        if ($models instanceof Model) {
            return collect([$models]);
        }

        if ($models instanceof Collection || $models instanceof EloquentCollection) {
            return collect($models->all())->filter(fn ($model) => $model instanceof Model)->values();
        }

        if (is_array($models)) {
            return collect($models)->filter(fn ($model) => $model instanceof Model)->values();
        }

        return collect([]);
    }
}
