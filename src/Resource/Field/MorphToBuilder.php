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
     * @param \Closure(mixed, JsonApiRequestInterface): (array{0: string, 1: string|int}|null) $identifier
     *
     * @return static
     */
    public function identifiedBy(\Closure $identifier): static
    {
        $this->identifiedBy = $identifier;

        return $this;
    }

    public function build(): MorphTo
    {
        if ($this->identifiedBy !== null && $this->identifierMetaResolver !== null) {
            throw new \LogicException(\sprintf(
                'MorphTo relation "%s" cannot combine identifiedBy() with identifierMeta(): its linkage renders without the target the identifier meta resolver receives.',
                $this->name,
            ));
        }

        return new MorphTo($this->fieldState(), $this->relationState(), $this->identifiedBy);
    }
}
