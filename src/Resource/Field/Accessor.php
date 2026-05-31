<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * Framework-agnostic read/write of a named member on a domain value.
 *
 * Supports plain associative arrays / `ArrayAccess`, public object properties,
 * and conventional `getXxx()` / `isXxx()` / `setXxx()` accessor methods. ORM
 * entities with typed private properties and bespoke accessors are handled by a
 * field's `extractUsing()` / `fillUsing()` hooks instead — this helper is the
 * zero-config default, not an ORM bridge.
 *
 * @internal
 */
final class Accessor
{
    public static function get(mixed $model, string $member): mixed
    {
        if (\is_array($model)) {
            return $model[$member] ?? null;
        }

        if ($model instanceof \ArrayAccess) {
            return $model->offsetExists($member) ? $model[$member] : null;
        }

        if (\is_object($model)) {
            foreach (['get' . \ucfirst($member), 'is' . \ucfirst($member), $member] as $getter) {
                if (\method_exists($model, $getter)) {
                    /** @var callable $callable */
                    $callable = [$model, $getter];

                    return $callable();
                }
            }

            if (isset($model->{$member})) {
                return $model->{$member};
            }
        }

        return null;
    }

    /**
     * Writes `$value` into `$model`. Returns the model (mutated in place for
     * objects; a new array for array models).
     */
    public static function set(mixed $model, string $member, mixed $value): mixed
    {
        if (\is_array($model)) {
            $model[$member] = $value;

            return $model;
        }

        if ($model instanceof \ArrayAccess) {
            $model[$member] = $value;

            return $model;
        }

        if (\is_object($model)) {
            $setter = 'set' . \ucfirst($member);
            if (\method_exists($model, $setter)) {
                /** @var callable $callable */
                $callable = [$model, $setter];
                $callable($value);

                return $model;
            }

            $model->{$member} = $value;

            return $model;
        }

        return $model;
    }
}
