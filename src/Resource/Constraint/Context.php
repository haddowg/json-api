<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Constraint;

/**
 * Declares whether a {@see Constraint} applies on create (POST) requests,
 * update (PATCH) requests, or both.
 *
 * Constraints default to {@see self::always()}; the per-field `onCreate()` /
 * `onUpdate()` builders and the `requiredOnCreate()` / `requiredOnUpdate()`
 * shortcuts scope them to a single context.
 */
final readonly class Context
{
    public function __construct(
        public bool $onCreate = true,
        public bool $onUpdate = true,
    ) {}

    public static function always(): self
    {
        return new self(true, true);
    }

    public static function onlyCreate(): self
    {
        return new self(true, false);
    }

    public static function onlyUpdate(): self
    {
        return new self(false, true);
    }

    /**
     * Whether this constraint applies in the given context.
     *
     * @param bool $creating true for a create (POST) request, false for an
     *                       update (PATCH) request
     */
    public function appliesTo(bool $creating): bool
    {
        return $creating ? $this->onCreate : $this->onUpdate;
    }
}
