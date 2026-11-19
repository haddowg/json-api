<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The entry that opens a declared `meta` object to members it does not name, as an
 * {@see Obj}'s `additionalProperties()` opens an object attribute. A `meta` declaration
 * (a type's `documentMeta()`, `requestDocumentMeta()` or `resourceMeta()`, an action's
 * request or response meta, a pivot's `fields()` or an `identifierMeta()` list) holds at
 * most one, beside its declared members. Without it the object is closed.
 *
 * Its `extractUsing()` hook returns the extra members a read renders, merged after the
 * declared ones. A write's undeclared members are validated against {@see of()} when it
 * is given and handed, as one array, to its `fillUsing()` hook; without one they stay
 * readable through the written meta. Authors declare one with {@see make()}.
 */
final readonly class AdditionalProperties extends AbstractFieldValue
{
    /**
     * The name the entry carries. A declared member may not use it.
     */
    public const string NAME = '*';

    public function __construct(FieldState $state, private ?FieldInterface $of = null)
    {
        parent::__construct($state);
    }

    public static function make(): AdditionalPropertiesBuilder
    {
        return new AdditionalPropertiesBuilder(self::NAME);
    }

    /**
     * The field each undeclared member's value is described and validated by, or `null`
     * when any JSON value is accepted.
     */
    public function of(): ?FieldInterface
    {
        return $this->of;
    }

    /**
     * The extra members a read renders: what the `extractUsing()` hook returns for the
     * model, each value cast by {@see of()} when it is given. Empty without a hook, or when
     * the hook returns anything but an object.
     *
     * @return array<string, mixed>
     */
    public function extract(mixed $model, JsonApiRequestInterface $request): array
    {
        $hook = $this->state->extractUsing;
        if ($hook === null) {
            return [];
        }

        $members = $hook($model, $request, self::NAME);
        if (!\is_array($members)) {
            return [];
        }

        $rendered = [];
        foreach ($members as $name => $value) {
            $rendered[(string) $name] = $this->of instanceof AbstractFieldValue
                ? $this->of->serialize([$this->of->column() ?? $this->of->name() => $value], $request, $this->of->name())
                : $value;
        }

        return $rendered;
    }

    /**
     * Whether a `fillUsing()` hook takes the written members.
     */
    public function fills(): bool
    {
        return $this->state->fillUsing !== null;
    }

    /**
     * Hands the written undeclared members to the `fillUsing()` hook, called with the model,
     * the members by name, the written object and the entry's name. Without a hook the model
     * comes back unchanged.
     *
     * @param array<string, mixed> $members
     * @param array<string, mixed> $data
     */
    public function fill(mixed $model, array $members, array $data): mixed
    {
        $hook = $this->state->fillUsing;
        if ($hook === null) {
            return $model;
        }

        return $hook($model, $members, $data, self::NAME) ?? $model;
    }

    /**
     * Each written member's value in its typed form: cast by {@see of()} when it is given,
     * else as sent.
     *
     * @param array<string, mixed> $members
     *
     * @return array<string, mixed>
     */
    public function cast(array $members): array
    {
        if ($this->of === null) {
            return $members;
        }

        $cast = [];
        foreach ($members as $name => $value) {
            $cast[$name] = $value === null ? null : $this->of->castWireValue($value);
        }

        return $cast;
    }
}
