<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The mutable **relation builder** for a polymorphic {@see MorphTo} to-one
 * relationship: the related resource may be one of several declared types, passed
 * as the mandatory list to {@see make()}. {@see build()} freezes it into a readonly
 * {@see MorphTo} value object.
 */
final class MorphToBuilder extends AbstractRelationBuilder
{
    use DeclaresPolymorphicTypes;

    /**
     * Eager by default: the morph id/type sit on the owning model, so resolving the
     * linkage identifier is free (no query). {@see AbstractRelationBuilder::$dataOnlyWhenLoaded}.
     */
    protected bool $dataOnlyWhenLoaded = false;

    /**
     * @var (\Closure(mixed, JsonApiRequestInterface): mixed)|null
     */
    private ?\Closure $identifiedBy = null;

    /**
     * @var array{type: string, id: string, typeMap: array<array-key, string>}|null
     */
    private ?array $identifiedByColumns = null;

    /**
     * Reads the target's identifier off the parent instead of the target itself. The
     * closure receives the parent model and the request and returns `[$type, $id]`,
     * where `$type` is one of the declared JSON:API types and `$id` the target's
     * storage key, or `null` when the relation is empty:
     *
     *     MorphTo::make('target', ['advertisers', 'agencies'])
     *         ->identifiedBy(static fn(mixed $campaign): ?array => $campaign instanceof Campaign && $campaign->targetId !== null
     *             ? [$campaign->targetType, $campaign->targetId]
     *             : null);
     *
     * The linkage renders from the pair without loading the target, and the id goes
     * through the target type's id encoder when it has one. The target is read only to
     * render it into `included`: from the host's
     * {@see \haddowg\JsonApi\Serializer\ToOneRelationshipLinkageInterface} when it
     * supplies one, otherwise the relation's usual read. A type the relation does not
     * declare is a `\LogicException`.
     *
     * It changes reads only; `fillUsing()` and the default write are unchanged. It
     * cannot be combined with {@see identifierMeta()}, whose resolver needs the
     * target.
     *
     * Prefer {@see identifiedByColumns()} when the pair lives in two fields of the
     * parent: a data layer can compare those in a query, which a
     * {@see \haddowg\JsonApi\Resource\Filter\WhereMorph} filter needs.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface): (array{0: string, 1: string|int}|null) $identifier
     *
     * @return static
     */
    public function identifiedBy(\Closure $identifier): static
    {
        $this->identifiedBy = $identifier;

        return $this;
    }

    /**
     * Reads the target's identifier from two fields of the parent, and declares them so a
     * data layer can compare them in a query: `$type` holds the target's stored type and
     * `$id` its storage key.
     *
     *     MorphTo::make('subject', ['rfps', 'campaigns'])
     *         ->identifiedByColumns(type: 'subjectType', id: 'subjectId')
     *
     * Each name is a field of the parent as its model sees it: a Doctrine entity's
     * property, an Eloquent model's attribute. The stored type is the target's JSON:API
     * type unless `$typeMap` maps stored values to JSON:API types, as an Eloquent morph map
     * does. A parent whose type or id field is empty has no target.
     *
     * The linkage renders from the two fields exactly as it does from an
     * {@see identifiedBy()} closure, and a
     * {@see \haddowg\JsonApi\Resource\Filter\WhereMorph} filter compares them. It
     * changes reads only; `fillUsing()` and the default write are unchanged. It cannot be
     * combined with {@see identifiedBy()} or {@see identifierMeta()}.
     *
     * @param array<array-key, string> $typeMap stored type => JSON:API type
     *
     * @return static
     */
    public function identifiedByColumns(string $type, string $id, array $typeMap = []): static
    {
        $this->identifiedByColumns = ['type' => $type, 'id' => $id, 'typeMap' => $typeMap];

        return $this;
    }

    public function build(): MorphTo
    {
        $columns = $this->morphColumns();
        $identifiedBy = $columns === null
            ? $this->identifiedBy
            : static fn(mixed $parent): ?array => $columns->pair($parent);

        if ($identifiedBy !== null && $this->identifierMetaResolver !== null) {
            throw new \LogicException(\sprintf(
                'MorphTo relation "%s" cannot combine %s with identifierMeta(): its linkage renders without the target the identifier meta resolver receives.',
                $this->name,
                $columns === null ? 'identifiedBy()' : 'identifiedByColumns()',
            ));
        }

        return new MorphTo($this->fieldState(), $this->relationState(), $identifiedBy, $columns);
    }

    /**
     * The declared identifier fields, checked against what the builder can see.
     */
    private function morphColumns(): ?MorphColumns
    {
        if ($this->identifiedByColumns === null) {
            return null;
        }

        if ($this->identifiedBy !== null) {
            throw new \LogicException(\sprintf(
                'MorphTo relation "%s" declares both identifiedBy() and identifiedByColumns(). Declare one: identifiedByColumns() when the parent keeps the type and id in two fields, identifiedBy() for anything else.',
                $this->name,
            ));
        }

        ['type' => $type, 'id' => $id, 'typeMap' => $typeMap] = $this->identifiedByColumns;
        foreach (['type' => $type, 'id' => $id] as $role => $field) {
            if ($field === '' || \str_contains($field, '.')) {
                throw new \LogicException(\sprintf(
                    'MorphTo relation "%s" declares identifiedByColumns() with %s "%s", which is not a field name. Name a field of the parent itself.',
                    $this->name,
                    $role === 'type' ? 'a type' : 'an id',
                    $field,
                ));
            }
        }

        if ($type === $id) {
            throw new \LogicException(\sprintf(
                'MorphTo relation "%s" declares identifiedByColumns() with the same field twice; the type and the id are two fields of the parent.',
                $this->name,
            ));
        }

        foreach ($typeMap as $stored => $mapped) {
            if ((string) $stored === '' || !\in_array($mapped, $this->relatedTypes, true)) {
                throw new \LogicException(\sprintf(
                    'MorphTo relation "%s" maps the stored type "%s" to "%s", which it does not declare. Declared types: %s.',
                    $this->name,
                    (string) $stored,
                    $mapped,
                    \implode(', ', $this->relatedTypes),
                ));
            }
        }

        return new MorphColumns($type, $id, $this->relatedTypes, $typeMap);
    }
}
