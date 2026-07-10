<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

/**
 * A **field builder**: the mutable, fluent object an author chains to declare a
 * {@see FieldInterface} (`Str::make('title')->required()->maxLength(200)`). Its
 * autocomplete surface is authoring methods only; {@see build()} freezes the
 * accumulated declaration into the readonly {@see FieldInterface} value object
 * the engine walks.
 *
 * The resource **builds** any builder returned from `fields()` into its
 * {@see FieldInterface} before use ({@see \haddowg\JsonApi\Resource\AbstractResource::allFields()}),
 * so an author may return either a builder or an already-built field.
 */
interface FieldBuilderInterface
{
    /**
     * Freezes the accumulated authoring state into the readonly value object the
     * engine consumes. Pure and idempotent: it mutates nothing and returns an
     * equal field each call.
     */
    public function build(): FieldInterface;
}
