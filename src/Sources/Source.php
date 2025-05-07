<?php

namespace SushiSources\Sources;

use SushiSources\Contracts\SourceInterface;

abstract class Source implements SourceInterface
{
    /**
     * Context that the source was instantiated with.
     * @var array $context
     */

    /**
     * Constructor.
     *
     * @param array $context
     */
    public function __construct(protected array $context = []){}

    /**
     * Get or set the context for the source.
     *
     * @param string|null $key
     * @param mixed|null $value
     * @return array|mixed|null
     */
    public function context(?string $key = null, mixed $value = null): mixed
    {
        return match (func_num_args()) {
            2 => !empty($key)
                ? $this->context[$key] = $value
                : null,
            1 => !empty($key)
                ? ($this->context[$key] ?? null)
                : null,
            default => $this->context,
        };
    }

    /**
     * Merge the given values into the context.
     *
     * @param array|null $values
     * @return array
     */
    public function mergeContext(?array $values = null): array
    {
        return $this->context = array_merge($this->context, $values ?? []);
    }
}
