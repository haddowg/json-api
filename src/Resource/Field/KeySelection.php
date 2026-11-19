<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The keys of an object attribute a request selected through `fields[TYPE]=<attr>.<key>`,
 * in the order the request listed them, each once. A field declared with
 * `selectableKeys()` renders only these keys, and its `extractUsing()` hook receives
 * them as its fourth argument so it can load no more.
 *
 * A key whose value is itself an object declaring `selectableKeys()` can be narrowed in
 * turn, as `<attr>.<key>.<key>`: {@see nested()} returns the selection inside it, and
 * `null` for a key selected whole.
 *
 * The selection of a list whose element declares `selectableKeys()` holds the one key
 * {@see ITEMS}, and {@see items()} returns the selection inside every item, as
 * `<attr>.*.<key>` selects it.
 */
final readonly class KeySelection
{
    /**
     * The key that stands for every item of a list.
     */
    public const string ITEMS = '*';

    /**
     * @var list<string>
     */
    public array $keys;

    /**
     * @var array<string, KeySelection>
     */
    private array $nested;

    /**
     * @param list<string>                $keys
     * @param array<string, KeySelection> $nested the selection inside each key narrowed further, keyed by that key
     */
    public function __construct(array $keys, array $nested = [])
    {
        $this->keys = \array_values(\array_unique($keys));
        $this->nested = \array_intersect_key($nested, \array_flip($this->keys));
    }

    /**
     * Whether the request selected `$key`, whole or narrowed.
     */
    public function has(string $key): bool
    {
        return \in_array($key, $this->keys, true);
    }

    /**
     * The selection inside `$key`, or `null` when the request selected it whole or not at
     * all.
     */
    public function nested(string $key): ?self
    {
        return $this->nested[$key] ?? null;
    }

    /**
     * The selection inside every item of a list, or `null` when the request selected the
     * items whole or this is not a list's selection.
     */
    public function items(): ?self
    {
        return $this->nested(self::ITEMS);
    }

    /**
     * Every selected path as the request spells it after the attribute's name, such as
     * `postcode`, `geo.lat` or `*.sku`, a narrowed key giving one path per key selected inside it.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->keys as $key) {
            $inner = $this->nested[$key] ?? null;
            if ($inner === null) {
                $paths[] = $key;

                continue;
            }
            foreach ($inner->paths() as $path) {
                $paths[] = $key . '.' . $path;
            }
        }

        return $paths;
    }
}
