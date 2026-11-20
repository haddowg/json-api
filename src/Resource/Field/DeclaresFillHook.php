<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * The `fillUsing()` hook of a builder whose field runs it on a write. The `Id` is written
 * from `data.id` through its own policy, so its builder leaves it out.
 *
 * @template-covariant TValue
 */
trait DeclaresFillHook
{
    /**
     * Writes the request value onto the model in place of the default write, called with
     * the model, the value, the resource object's `data` member and the member's name.
     * The callback may type the model as the class it expects and the value as `TValue`,
     * the type the field's validation guarantees, such as `string` for a {@see Str}.
     *
     * @template TModel
     *
     * @param \Closure(TModel, TValue, array<string, mixed>, string): mixed $callback
     * @return static
     */
    public function fillUsing(\Closure $callback): static
    {
        $this->fillUsing = $callback;

        return $this;
    }
}
