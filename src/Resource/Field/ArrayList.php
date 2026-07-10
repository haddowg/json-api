<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A zero-indexed array attribute (JSON `type: array`) — the built, readonly value
 * object the engine walks. Authors declare one with {@see make()}, which returns
 * a mutable {@see ArrayListBuilder}; the resource **builds** it into this value
 * object before use.
 */
final readonly class ArrayList extends AbstractFieldValue
{
    public function __construct(
        FieldState $state,
        private string $elementType = 'string',
        private bool $sorted = false,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): ArrayListBuilder
    {
        return new ArrayListBuilder($name);
    }

    /**
     * The declared JSON type of each element (default `string`), read by the OpenAPI
     * projection to type the `items` schema.
     */
    public function elementType(): string
    {
        return $this->elementType;
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        $list = \array_values($raw);
        if ($this->sorted) {
            \sort($list);
        }

        return $list;
    }
}
