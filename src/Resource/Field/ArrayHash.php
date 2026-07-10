<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A JSON object attribute exposed as a PHP associative array (JSON
 * `type: object`) — the built, readonly value object the engine walks. Authors
 * declare one with {@see make()}, which returns a mutable {@see ArrayHashBuilder};
 * the resource **builds** it into this value object before use.
 */
final readonly class ArrayHash extends AbstractFieldValue
{
    public function __construct(
        FieldState $state,
        private bool $sortKeys = false,
        private bool $sortValues = false,
    ) {
        parent::__construct($state);
    }

    public static function make(string $name): ArrayHashBuilder
    {
        return new ArrayHashBuilder($name);
    }

    protected function serializeValue(mixed $raw): mixed
    {
        if (!\is_array($raw)) {
            return $raw;
        }

        if ($this->sortKeys) {
            \ksort($raw);
        }

        if ($this->sortValues) {
            \asort($raw);
        }

        return $raw;
    }
}
