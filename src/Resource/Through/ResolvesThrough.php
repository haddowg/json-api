<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Through;

use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The `->through()` state shared by the built filters and sorts: the declared path in
 * `$through`, its resolution in `$resolvedThrough`, and the member the leaf reads named
 * by {@see throughLeaf()}. A copy is rebuilt from the object's own promoted properties,
 * so every wither keeps the path.
 *
 * @internal
 *
 * @property-read ?string       $through
 * @property-read ?RelationPath $resolvedThrough
 */
trait ResolvesThrough
{
    public function throughRelations(): ?string
    {
        return $this->through;
    }

    public function resolvedThrough(): ?RelationPath
    {
        return $this->resolvedThrough;
    }

    public function withResolvedThrough(RelationPath $path): static
    {
        [$kind, $property] = $this->throughLeaf();
        $member = $this->{$property};
        \assert(\is_string($member));

        return $this->rebuilt(['resolvedThrough' => self::checkedLeaf($path, $kind, $this->key(), $member)]);
    }

    /**
     * A copy reaching through `$path`, unresolved.
     *
     * @internal
     */
    public function withThroughPath(string $path): static
    {
        return $this->rebuilt(['through' => ThroughPaths::checked($path), 'resolvedThrough' => null]);
    }

    public function atLeaf(): static
    {
        $path = $this->resolvedThrough ?? throw new \LogicException(\sprintf(
            '"%s" reaches through "%s", which has not been resolved; resolve it with a ThroughPathResolver before applying it.',
            $this->key(),
            $this->through ?? '',
        ));
        [$kind, $property] = $this->throughLeaf();
        $relation = $path->leafRelation();

        return $this->rebuilt([
            'through' => null,
            'resolvedThrough' => null,
            $property => $kind === LeafKind::Relation && $relation !== null ? ($relation->column() ?? $relation->name()) : $path->leafColumn(),
        ]);
    }

    /**
     * What the leaf is and the property naming it: `column`, or `relationship` for an
     * existence filter.
     *
     * @return array{LeafKind, string}
     */
    abstract protected function throughLeaf(): array;

    /**
     * A copy with `$changes` over this object's properties.
     *
     * @param array<string, mixed> $changes
     */
    protected function rebuilt(array $changes): static
    {
        /** @var array<string, mixed> $arguments */
        $arguments = [...\get_object_vars($this), ...$changes];

        // @phpstan-ignore argument.type, new.static
        return new static(...$arguments);
    }

    /**
     * `$path` with the field `$member` names on the reached type as its leaf.
     *
     * @throws \LogicException when the reached type has no such field, or one this kind of leaf cannot read
     */
    private static function checkedLeaf(RelationPath $path, LeafKind $kind, string $key, string $member): RelationPath
    {
        $refuse = static fn(string $why): \LogicException => new \LogicException(\sprintf(
            '"%s" reaches through "%s" to "%s" on type "%s", %s',
            $key,
            $path->path,
            $member,
            $path->leafType(),
            $why,
        ));

        if ($kind === LeafKind::Sort && $path->reachesMany()) {
            throw new \LogicException(\sprintf(
                'Sort "%s" reaches through "%s", which crosses a to-many relation; a sort reaches through to-one relations only, since a to-many holds no single value to order by.',
                $key,
                $path->path,
            ));
        }

        $field = $path->field($member) ?? throw $refuse('which declares no such field.');

        if ($kind === LeafKind::Relation) {
            if (!$field instanceof RelationInterface) {
                throw $refuse('which is not a relation; an existence filter tests a relation of the type the path reaches.');
            }

            return $path->withLeaf($field);
        }

        if ($kind === LeafKind::Id && !$field instanceof Id) {
            throw $refuse('which is not its id; an id filter compares the id of the type the path reaches.');
        }
        if ($field instanceof RelationInterface) {
            throw $refuse('which is a relation; reach through it with the path and compare its id with WhereIdIn instead.');
        }
        if ($field->relatedVia() !== null || $field->column() === null) {
            throw $refuse('which stores no value of its own to compare.');
        }

        return $path->withLeaf($field);
    }
}
