<?php

namespace SushiSources\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ModelSource extends Source
{
    /**
     * @inheritDoc
     */
    public function read(): array
    {
        return $this->getModelPropertyRows();
    }

    /**
     * @inheritDoc
     */
    public function write(array|null $rows = []): void
    {
        $this->setModelPropertyRows($rows);
    }

    /**
     * @inheritDoc
     */
    public function persist(array|null $rows = []): void
    {
        $this->model()?->save();
    }

    /**
     * @inheritDoc
     */
    public function lock(callable $callable): mixed
    {
        // TODO: Implement lock() method.
        return $callable();
    }

    /**
     * Retrieves the source model.
     *
     * @return Model|null
     */
    public function model(): ?Model
    {
        return $this->context('model')
            ?? $this->context('model', $this->fetchModel());
    }

    /**
     * Attempts to fetch the source model from the context.
     *
     * @return Model|null
     */
    public function fetchModel(): ?Model
    {
        $options = $this->context('options');

        // validate model class
        $class = $this->context('class');
        if (!class_exists($class) || !is_subclass_of($class, Model::class))
            return null;

        // when a key is provided, we attempt to find it
        $key = $this->context('key');
        if (!empty($key)) {
            return data_get($options, 'create_inexistent_key') === true
                ? $class::firstOrNew(
                    [app($class)?->getKeyName() => $key, ...($this->context('attributes') ?? [])],
                    $this->context('values') ?? []
                )
                : $class::find($key);
        }

        // when the query is provided, we attempt to fetch it
        $query = $this->context('query');
        if (!empty($query) && is_array($query)) {
            $builderResult = collect($query)
                ->reduce(fn($builder, $args, $method) => $builder->{$method}(...$args), $class::query());

            return match (get_class($builderResult)) {
                Collection::class => $builderResult->first(),
                Builder::class => $builderResult->first(),
                $class => $builderResult,
                default => null
            };
        }

        return null;
    }

    /**
     * Retrieves rows from the target model property.
     *
     * @param Model|null $model
     * @return array
     */
    public function getModelPropertyRows(?Model $model = null): array
    {
        $property = $this->context('property');
        if (!is_string($property) || empty($property))
            return [];

        return (array)data_get($model ?? $this->model(), $property);
    }

    /**
     * Sets the rows to the target model property.
     *
     * @param array|null $rows The data to be set.
     * @return void
     */
    public function setModelPropertyRows(?array $rows = []): void
    {
        [$column, $path] = $this->parseModelProperty($this->context('property'));
        if (empty($column))
            return;

        $model = $this->model();
        if (empty($model))
            return;

        $columnData = $model->{$column};
        $model->{$column} = empty($path) ? $rows : data_set($columnData, $path, $rows);
    }

    /**
     * Parses the target model property into a column name and a dot notation key.
     *
     * @param string $property
     * @return array<string, string> [$column, $path]
     */
    public function parseModelProperty(string $property): array
    {
        $parts = explode('.', $property);

        $column = array_shift($parts);
        $path = implode('.', $parts);

        return [$column, $path];
    }
}
