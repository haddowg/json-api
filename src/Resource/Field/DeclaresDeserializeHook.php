<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The `deserializeUsing()` hook of a builder whose field runs it on a write. An `Obj`, a
 * `Map`, a `OneOf`, the `Id`, a relation and an `additionalProperties` meta entry write
 * their values without it, so their builders leave it out.
 *
 * @template-covariant TValue
 */
trait DeclaresDeserializeHook
{
    /**
     * Converts the request value before it is stored, called with the value and the
     * resource object's `data` member. Declared inside `onCreate()` or `onUpdate()`, it
     * runs on that write only, in place of one declared on the field. The callback may type the value as `TValue`, the
     * type the field's validation guarantees. After it `TValue` is `mixed`, so a
     * `fillUsing()` declared later takes the value untyped.
     *
     * @param \Closure(TValue, array<string, mixed>): mixed $callback
     * @phpstan-self-out static<mixed>
     * @return $this
     */
    public function deserializeUsing(\Closure $callback): static
    {
        $context = $this->currentContext();
        match (true) {
            $context->onCreate && !$context->onUpdate => $this->deserializeUsingOnCreate = $callback,
            $context->onUpdate && !$context->onCreate => $this->deserializeUsingOnUpdate = $callback,
            default => $this->deserializeUsing = $callback,
        };

        return $this;
    }
}
