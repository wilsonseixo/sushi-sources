<?php

namespace SushiSources\Traits;

use \Exception;
use Illuminate\Database\Eloquent\Model;
use Sushi\Sushi;
use SushiSources\Sources\Source;

trait SushiWithSource
{
    use Sushi;

    /**
     * Default Source class; override per-model or dynamically via setter.
     * @var class-string<\SushiSources\Sources\Source>|null
     */
    protected static ?string $sushiSourceClass = null;

    /**
     * The model class Sushi source.
     * @var Source|null
     */
    protected static ?Source $staticSushiSource = null;

    /**
     * The instance's Sushi source.
     * @var Source|null
     */
    protected ?Source $sushiSource = null;

    /**
     * Cached rows for the current static context.
     * @var array<int|string, array<string, mixed>>|null
     */
    protected static ?array $rowsCache = null;

    // Mutation flag defaults
    protected static bool $DEFAULT_PERSIST = true;
    protected static bool $DEFAULT_LOCK = false;
    protected static bool $DEFAULT_REFRESH_ROWS = false;
    protected static bool $DEFAULT_STRIP_KEYS = false;
    protected static bool $DEFAULT_KEY_ROWS = false;

    // Mutation flags (affect the next mutating action)
    protected ?bool $shouldPersist = null;
    protected ?bool $shouldLock = null;
    protected ?bool $shouldRefreshRows = null;
    protected ?bool $shouldStripKeys = null;
    protected ?bool $shouldKeyRows = null;


    /**
     * Boot the trait.
     */
    public static function bootSushiWithSource(): void
    {
        foreach (static::sushiListenableEvents() as $event)
            static::{$event}(fn ($model) => static::onModelEvent($model, $event));
    }

    public function getRows()
    {
        return ( $this->sushiSource() )
            ? static::sushiRows()
            : parent::getRows(); // fallback to original Sushi behavior
    }

    /**
     * Handle model events.
     *
     * @param self $model
     * @param string $event
     * @return void
     * @throws Exception
     */
    public static function onModelEvent(self $model, string $event): void
    {
        // change behavior when the model uses SoftDeletes
        if( method_exists(static::class, 'bootSoftDeletes') ){
            $event = match ($event) {
                'deleting', 'restoring' => 'updating',
                'deleted', 'restored' => 'updated',
                'forceDeleting' => 'deleting',
                'forceDeleted' => 'deleted',
                default => $event,
            };
        }

        // validate the primary key
        if( in_array($event, ['creating', 'updating', 'deleting']) )
            static::validateSushiKey($model, $event);

        // check the primary key against the table
        if( in_array($event, ['creating', 'updating']) )
            static::checkSushiKey($model, $event);

        // skip changes that should not be persisted
        if( !$model->shouldPersist() )
            return;

        // check if the source can be resolved
        $source = $model->sushiSource();
        if( !$source )
            throw new \Exception("The Sushi source for the model ".$model::class." couldn't be resolved.");

        // lock the source during the action, if needed
        if( $model->shouldLock() )
            $source->lock(fn() => $model->performSushiAction($event));
        else
            $model->performSushiAction($event);
    }

    /**
     * Retrieves the events for the trait to listen to.
     *
     * @return string[]
     */
    public static function sushiListenableEvents(): array
    {
        $defaultEvents = ['creating', 'created', 'updating', 'updated', 'deleting', 'deleted'];
        $softDeletesEvents = ['forceDeleting', 'forceDeleted', 'restoring', 'restored'];

        return method_exists(static::class, 'bootSoftDeletes')
            ? array_merge($defaultEvents, $softDeletesEvents)
            : $defaultEvents;
    }

    /**
     * Perform a Sushi model action.
     *
     * @param string $action
     * @return void
     * @throws Exception
     */
    public function performSushiAction(string $action): void
    {
        if( $this->shouldRefreshRows() )
            static::refreshSushiRows();

        // call action-specific method
        // TODO: create method to return this
        $method = str("sushi_$action")->camel()->toString();
        if( !method_exists(static::class, $method) )
            return;

        try {
            static::$method($this);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public static function sushiCreated(self $model): void
    {
        $rows = collect($model->getCastSushiRows());

        $keyName = $model->getKeyName();
        $keyValue = $model->getKeyValue();

        $index = $rows->search(fn ($row) => data_get($row, $keyName) === $keyValue );
        if( !is_bool($index) )
            throw new Exception("Model with key '$keyValue' already exists");

        $rows->push(static::safeInboundAttributesFromModel($model));

        $model->sushiSource()->write($model->formatRowsForSource($rows));
        if( $model->shouldPersist() )
            $model->sushiSource()->persist();

        static::setRowsCache($rows->values()->toArray());
    }

    /**
     * Attempts to update a row in the property associated to a given Sushi model.
     *
     * @param self $model
     * @return void
     * @throws Exception
     */
    public static function sushiUpdated(self $model): void
    {
        $rows = collect($model->getCastSushiRows());

        $keyName = $model->getKeyName();

        // check if the original key exists
        $originalKeyValue = $model->getRawOriginal($keyName);
        $index = $rows->search(fn ($row) => data_get($row, $keyName) === $originalKeyValue );
        if( is_bool($index) )
            throw new Exception("Model with key '$originalKeyValue' not found");

        // check if the new key exists
        if( data_get($model, $keyName) != $originalKeyValue ){
            $existingModelKey = $rows->search(fn ($row) => data_get($row, $keyName) === $model->{$keyName});
            if( !is_bool($existingModelKey) )
                throw new Exception("Model with key '{$model->{$keyName}}' already exists (new key is duplicate)");
        }

        $rows->put($index, static::safeInboundAttributesFromModel($model));

        $model->sushiSource()->write($model->formatRowsForSource($rows));
        if( $model->shouldPersist() )
            $model->sushiSource()->persist();

        static::setRowsCache($rows->values()->toArray());
    }

    /**
     * Attempts to delete a row from the property associated to a given Sushi model.
     *
     * @param self $model
     * @return void
     * @throws Exception
     */
    public static function sushiDeleted(self $model): void
    {
        $rows = collect($model->getCastSushiRows());

        $keyName    = $model->getKeyName();
        $keyValue   = $model->getKeyValue(false);

        $index = $rows->search(fn ($row) => data_get($row, $keyName) === $keyValue );
        if( is_bool($index) )
            throw new Exception("Model with key '$keyValue' not found");

        $rows->pull($index);

        $model->sushiSource()->write($model->formatRowsForSource($rows));
        if( $model->shouldPersist() )
            $model->sushiSource()->persist();

        static::setRowsCache($rows->values()->toArray());
    }

    /**
     * Fetch rows from the source.
     *
     * @param bool $flushCached
     * @return array
     * @throws \Exception
     */
    public static function sushiRows(bool $flushCached = false): array
    {
        if( $flushCached )
            static::$rowsCache = null;

        if( !is_null(static::$rowsCache) )
            return static::$rowsCache;

        $rows = static::staticSushiSource()?->read();
        if( is_null($rows) )
            return [];

        return static::setRowsCache(static::formatRowsForSushi($rows));
    }

    /**
     * Retrieves a model inbound-cast attributes safely.
     *
     * @param Model $model
     * @return array
     */
    public static function safeInboundAttributesFromModel(Model $model): array
    {
        // attributes as they come from the DB
        $inboundCastAttributes = $model->getAttributes();
        // attributes after casts are applied (and Model validations)
        $outboundCastAttributes = $model->attributesToArray();

        // inbound attributes which keys exist in the outbound attributes
        return array_intersect_key($inboundCastAttributes, array_flip(array_keys($outboundCastAttributes)));
    }

    protected static function setRowsCache(array $rows): array
    {
        return static::$rowsCache = $rows;
    }

    /**
     * Retrieves the Sushi rows for the current model instance.
     *
     * @return array
     */
    public function getCastSushiRows(): array
    {
        $rows   = $this->getRows();
        $casts  = $this->getCasts() ?? [];
        $columnsWithCast = array_keys($casts);

        // cast row values (normalizeRows prepares rows for Sushi, which receives non-cast rows)
        foreach ( $rows as &$row )
            foreach ( $row as $column => $value )
                if( in_array($column, $columnsWithCast) )
                    $row[$column] = $this->castAttribute($column, $value);

        return $rows;
    }

    /**
     * Normalizes the row fields.
     *
     * @param array|null $rows
     * @return array
     */
    public function normalizeRows(?array $rows): array
    {
        $rows = json_decode(json_encode(array_values($rows ?? [])), true);

        $columns = $this->getRowsSchemaColumns($rows);
        $rows = $this->harmonizeRows($rows, $columns);

        return $rows;
    }

    /**
     * Retrieves the column names from rows.
     *
     * @param array|null $rows
     * @param array $columns Initial set of columns
     * @return array
     */
    public function getRowsSchemaColumns(?array $rows = null, array $columns = []) : array
    {
        $rows ??= $this->getRows();
        $keyName = $this->getKeyName();

        // add primary key
        array_unshift($columns, $keyName);

        // add schema keys
        if( !empty($this->schema) && is_array($this->schema) )
            $columns = array_values(array_unique(array_merge($columns, array_keys($this->schema))));

        foreach ($rows as $row)
            foreach (array_keys((array)$row) as $column)
                if (!in_array($column, $columns))
                    $columns[] = $column;

        return $columns;
    }

    /**
     * Harmonize the row fields.
     *
     * @param array $rows
     * @param array $fields The fields to enforce.
     * @param mixed|null $defaults The default values for unsetted fields.
     * @return array
     */
    public function harmonizeRows(array $rows, array $columns, array $defaults = []): array
    {
        $harmonizedRows = [];
        // TODO: might be useful to be able to exclude some fields from harmonization
        $defaults = array_merge(app(static::class)->getAttributes() ?? [], $defaults);

        foreach ($rows as $key => $item) {
            $item = (array)$item;
            foreach ($columns as $name) {
                $value = $item[$name] ?? $defaults[$name] ?? null;

                // convert iterables to string so Sushi can process the value
                $harmonizedRows[$key][$name] = is_iterable($value)
                    ? json_encode($value)
                    : $value;
            }
        }

        // harmonize primary key when missing/malformed
        $harmonizedRows = $this->harmonizeRowsPrimaryKey($harmonizedRows);

        return $this->harmonizeRowsPrimaryKey($harmonizedRows);
    }

    public function harmonizeRowsPrimaryKey(array $rows): array
    {
        $keyName = $this->getKeyName();
        $keyType = $this->getKeyType();

        // TODO: deal with string keys
        if( $keyType == 'int' ){
            $keys = array_column($rows, $keyName);
            $intKeys = array_map(fn($item) => (int)$item, $keys);
            $keysRange = range(1, count($keys));
            $missingKeys = array_diff($keysRange, $intKeys);
            $maxKey = max($keysRange);

            foreach ($rows as $key => $item) {
                if( !empty($item[$keyName]) && is_numeric($item[$keyName]) )
                    continue;

                // assign a new key
                $rows[$key][$keyName] = array_shift($missingKeys) ?? ++$maxKey;
            }
        }

        return $rows;
    }

    /**
     * Refresh the cached rows.
     *
     * @return void
     * @throws \Exception
     */
    public static function refreshSushiRows(): void
    {
        // TODO: perform a partial refresh
        static::sushiRows(true);
        static::bootSushi();
    }

    /**
     * Resolve the source instance to use.
     *
     * @param array|null $context
     * @return Source|null
     * @throws \Exception
     */
    public static function resolveSushiSource(?array $context = null): ?Source
    {
        return static::$staticSushiSource
            ?? static::$staticSushiSource = static::instantiateSushiSource(null, $context);
    }

    /**
     * Instantiate the model source instance.
     *
     * @param string|null $class
     * @param array|null $context
     * @return Source|null
     * @throws \Exception
     */
    public static function instantiateSushiSource(?string $class = null, ?array $context = null): ?Source
    {
        $class ??= static::resolveSourceClass();
        $context ??= static::resolveSourceContext();

        if( !is_subclass_of($class, Source::class) )
            throw new \Exception("The source must be an implementation of ".Source::class.", $class given");

        return new $class($context);
    }

    public static function resolveSourceClass(): ?string
    {
        return (app()->bound(static::class) ? app(static::class) : new static)
            ->getSourceClass();
    }

    public static function resolveSourceContext(): ?array
    {
        return (app()->bound(static::class) ? app(static::class) : new static)
            ->getSourceContext();
    }

    public function getSourceClass(): ?string
    {
        return ( property_exists($this, 'sourceClass') && is_string($this->sourceClass) )
            ? $this->sourceClass
            : null;
    }

    public function getSourceContext(): ?array
    {
        return ( property_exists($this, 'sourceContext') && is_array($this->sourceContext) )
            ? $this->sourceContext
            : null;
    }

    public static function setSushiContext(string|array $keyOrContext, mixed $value = null): mixed
    {
        $key = is_string($keyOrContext) ? $keyOrContext : null;
        $context = is_array($keyOrContext) ? $keyOrContext : null;
        try {
            $source = static::staticSushiSource();

            $result = match (func_num_args()) {
                2 => !empty($key)
                    ? $source->context($key, $value)
                    : null,
                1 => !empty($context)
                    ? $source->mergeContext($context)
                    : null,
                default => $source->context(),
            };

            static::refreshSushiRows();

            return $result;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clear flags mutation flags.
     */
    protected function clearMutationFlags(): void
    {
        $this->shouldPersist = null;
        $this->shouldLock = null;
        $this->shouldRefreshRows = null;
        $this->shouldStripKeys = null;
        $this->shouldKeyRows = null;
    }

    /**
     * Set a specific Source instance to use.
     *
     * @param Source $source
     * @return \SushiModel|SushiWithSource
     */
    public function setSushiSource(Source $source): self
    {
        $this->sushiSource = $source;
        return $this;
    }

    /**
     * Resolve the active Source (instance or container).
     *
     * @return Source|null
     */
    public function sushiSource(): ?Source
    {
        try {
            return $this->sushiSource
                ?? $this->sushiSource = static::staticSushiSource();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve static-only Source (no instance).
     *
     * @param array|null $context
     * @return Source|null
     * @throws \Exception
     */
    public static function staticSushiSource(?array $context = null): ?Source
    {
        return static::$staticSushiSource
            ?? static::resolveSushiSource($context);
    }

    /**
     * Get the primary key value.
     * Attempts to get the original value first.
     *
     * @param bool $useOriginal
     * @return mixed
     */
    public function getKeyValue(bool $useOriginal = true) : mixed
    {
        $keyName = $this->getKeyName();
        return ($useOriginal ? $this->getRawOriginal($keyName) : null)
            ?? $this->getAttribute($keyName);
    }

    /**
     * Validate the changed Sushi models' key, according to the event.
     *
     * @param Model $model
     * @param string $event
     * @return void
     */
    public static function validateSushiKey(Model $model, string $event): void
    {
        $keyName = $model->getKeyName();
        $keyType = $model->getKeyType();
        $keyValue = $model->{$keyName} ?? null; // TODO: check if the method getKey() can be used here

        // if the event is not 'creating', the key must exist
        // when the key type is not 'int', it can't be automatically determined
        if ( is_null($keyValue) && ($event != 'creating' || $keyType != 'int') ) {
            throw new \Illuminate\Database\QueryException(
                $model->getConnectionName(),
                "SushiWithSource::$event",
                [],
                new \Exception("No value defined for primary key '$keyName'")
            );
        }
    }

    /**
     * Check the changed Sushi models' key against the table.
     *
     * @param Model $model
     * @param string $action
     * @return void
     */
    public static function checkSushiKey(Model $model, string $action): void
    {
        $keyName = $model->getKeyName();
        $keyValue = $model->{$keyName} ?? null; // TODO: check if the method getKey() can be used here

        if (
            (!$model->exists || $model->getRawOriginal($keyName) !== $keyValue)
            && static::where($keyName, $keyValue)->exists()
        ) {
            throw new \Illuminate\Database\UniqueConstraintViolationException(
                $model->getConnectionName(),
                "SushiWithSource::$action",
                [],
                new \Exception("Unique constraint failed for primary key '$keyName'")
            );
        }
    }

    /**
     * Formats the given rows for use by Sushi.
     *
     * @param iterable $rows
     * @return array
     */
    public static function formatRowsForSushi(iterable $rows): array
    {
        return (app()->bound(static::class) ? app(static::class) : new static)
            ->normalizeRows((array)$rows);
    }

    /**
     * Formats the given rows to persist to the source.
     *
     * @param iterable $rows
     * @return array
     */
    public function formatRowsForSource(iterable $rows): array
    {
        $rows = collect($rows);
        $indexes = null;
        $keyName = $this->getKeyName();

        if( $this->shouldKeyRows() ){
            $indexes = !empty($keyName) ? $rows->pluck($keyName) : $rows->keys();
        }

        if( $this->shouldStripPrimaryKeys() ) {
            $rows = $rows->map(function ($row) use($keyName) {
                unset($row[$keyName]);
                return $row;
            });
        }

        return !empty($indexes) ? $indexes->combine($rows)->toArray() : $rows->values()->toArray();
    }


    /**
     * Perform a non-persistent save on the Sushi model.
     *
     * @return bool
     */
    public function softSave() : bool
    {
        $result = $this->nonPersistent()->save();
        $this->getDirty();

        return $result;
    }

    /**
     * Retrieves the value of a flag, if set.
     *
     * @param string $name
     * @return bool|null
     */
    public function getOptionalFlag(string $name): ?bool
    {
        return (isset($this->{$name}) && is_bool($this->{$name}))
            ? $this->{$name}
            : null;
    }

    /**
     * Whether the primary keys should be stripped from rows when persisting to source.
     *
     * @return bool
     */
    public function shouldStripPrimaryKeys(): bool
    {
        return $this->getOptionalFlag('stripPrimaryKeys') ?? false;
    }

    /**
     * Sets the next action to be persistent.
     *
     * @return static
     */
    public function persistent(): static
    {
        $this->shouldPersist = true;
        return $this;
    }

    /**
     * Sets the next action to not be persistent.
     *
     * @return static
     */
    public function nonPersistent(): static
    {
        $this->shouldPersist = false;
        return $this;
    }

    /**
     * Whether the next mutating action should be persistent.
     *
     * @return bool
     */
    public function shouldPersist(): bool
    {
        return $this->shouldPersist
            ?? $this->getOptionalFlag('persistSushiChanges')
            ?? static::$DEFAULT_PERSIST;
    }

    /**
     * Sets the next action to be locking.
     *
     * @return static
     */
    public function locking(): static
    {
        $this->shouldLock = true;
        return $this;
    }

    /**
     * Sets the next action to not be locking.
     *
     * @return static
     */
    public function nonLocking(): static
    {
        $this->shouldLock = false;
        return $this;
    }

    /**
     * Whether the next mutating action should be locking.
     *
     * @return bool
     */
    public function shouldLock(): bool
    {
        return $this->shouldLock
            ?? $this->getOptionalFlag('lockDuringSushiPersist')
            ?? static::$DEFAULT_LOCK;
    }

    /**
     * Sets the next action to be row-refreshing.
     *
     * @return static
     */
    public function rowRefreshing(): static
    {
        $this->shouldRefreshRows = true;
        return $this;
    }

    /**
     * Sets the next action to not be row-refreshing.
     *
     * @return static
     */
    public function nonRowRefreshing(): static
    {
        $this->shouldRefreshRows = false;
        return $this;
    }

    /**
     * Whether the next mutating action should be row-refreshing.
     *
     * @return bool
     */
    public function shouldRefreshRows(): bool
    {
        return $this->shouldRefreshRows
            ?? $this->getOptionalFlag('refreshRowsBeforePersist')
            ?? static::$DEFAULT_REFRESH_ROWS;
    }

    /**
     * Sets the next action to be key-stripping.
     *
     * @return static
     */
    public function keyStripping(): static
    {
        $this->shouldStripKeys = true;
        return $this;
    }

    /**
     * Sets the next action to not be key-stripping.
     *
     * @return static
     */
    public function nonKeyStripping(): static
    {
        $this->shouldStripKeys = false;
        return $this;
    }

    /**
     * Whether the next mutating action should be key-stripping, i.e., removing the primary key from rows.
     *
     * @return bool
     */
    public function shouldStripKeys(): bool
    {
        return $this->shouldStripKeys
            ?? $this->getOptionalFlag('stripPrimaryKeysOnPersist')
            ?? static::$DEFAULT_STRIP_KEYS;
    }

    /**
     * Sets the next action to be row-keying.
     *
     * @return static
     */
    public function rowKeying(): static
    {
        $this->shouldKeyRows = true;
        return $this;
    }

    /**
     * Sets the next action to not be row-keying.
     *
     * @return static
     */
    public function nonRowKeying(): static
    {
        $this->shouldKeyRows = false;
        return $this;
    }

    /**
     * Whether the next mutating action should be key-stripping, i.e., re-indexing rows by primary key.
     *
     * @return bool
     */
    public function shouldKeyRows(): bool
    {
        return $this->shouldKeyRows
            ?? $this->getOptionalFlag('keyRowsOnPersist')
            ?? static::$DEFAULT_KEY_ROWS;
    }
}
