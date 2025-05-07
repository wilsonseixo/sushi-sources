<?php

namespace SushiSources\Contracts;

interface SourceInterface
{
    /**
     * Read all items from the source.
     *
     * @return array
     */
    public function read(): array;

    /**
     * Write all items to the source.
     *
     * @param array|null $items
     * @return void
     */
    public function write(?array $items): void;

    /**
     * Persist data to the source.
     *
     * @return void
     */
    public function persist(): void;

    /**
     * Implementation of the source lock for concurrency control.
     *
     * @param callable $callable
     */
    public function lock(callable $callable);

    /**
     * Get or set the context for the source.
     *
     * @param string|null $key
     * @param array|mixed|null $value
     */
    public function context(?string $key = null, mixed $value = null);

    /**
     * Merge the given values into the context.
     *
     * @param array|null $values
     * @return array
     */
    public function mergeContext(?array $values = null): array;
}
