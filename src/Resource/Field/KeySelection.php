<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The keys of an object attribute a request selected through `fields[TYPE]=<attr>.<key>`,
 * in the order the request listed them, each once. A field declared with
 * `selectableKeys()` renders only these keys, and its `extractUsing()` hook receives
 * them as its fourth argument so it can load no more.
 */
final readonly class KeySelection
{
    /**
     * @var list<string>
     */
    public array $keys;

    /**
     * @param list<string> $keys
     */
    public function __construct(array $keys)
    {
        $this->keys = \array_values(\array_unique($keys));
    }

    public function has(string $key): bool
    {
        return \in_array($key, $this->keys, true);
    }
}
