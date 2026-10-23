<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Exception\RelationshipCountNotAllowed;
use haddowg\JsonApi\Serializer\CountableControlsInterface;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Transformer\IncludePathGuard;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * Base for resource documents. Stateless: the per-pass request, primary object
 * and additional meta are carried on the {@see ResourceDocumentTransformation}
 * and passed to {@see getData()} / {@see getRelationshipData()} directly.
 *
 * @internal
 */
abstract class AbstractResourceDocument implements ResourceDocumentInterface
{
    /**
     * Resolves the effective maximum include depth for a render rooted at
     * `$primary`, applying the root-scoped include safeguards up front:
     *
     *  - Capability C (allowed include paths): if the primary serializer is
     *    {@see IncludeControlsInterface} and declares a non-null whitelist, every
     *    requested path must be a listed path or an ancestor of one (a listed deep
     *    path implies its intermediates), else {@see InclusionNotAllowed}.
     *  - Capability B (max include depth): the effective cap is the primary's
     *    per-resource override (if any) else the server default carried on the
     *    transformation, normalised so `<= 0` means unlimited (null); any
     *    requested path deeper than the cap is an {@see InclusionDepthExceeded}.
     *
     * Returns the normalised effective cap so the caller can set it on the root
     * {@see \haddowg\JsonApi\Transformer\ResourceTransformation} for the recursion
     * guard. (Capability A — per-relation includability — is enforced per-level
     * inside the transformer, not here.)
     */
    protected function resolveEffectiveMaxIncludeDepth(
        ResourceDocumentTransformation $transformation,
        SerializerInterface $primary,
    ): ?int {
        $this->validateCountedRelationships($transformation, $primary);

        return IncludePathGuard::rootSafeguards(
            $transformation->request->getIncludePaths(),
            $primary,
            $transformation->maxIncludeDepth,
        );
    }

    /**
     * Validates the request's flat `?withCount` against the primary resource's
     * countable targets, up front (root-scoped, like the include allow-list).
     *
     * The reserved `_self_` token (the primary collection's total) is split out and
     * validated against the resource-level
     * {@see \haddowg\JsonApi\Serializer\CountableSelfInterface::isCountable()}: a
     * `?withCount=_self_` against a serializer that is not
     * {@see CountableSelfInterface} — or whose {@see CountableSelfInterface::isCountable()}
     * is `false` — is rejected. The remaining (relation) names are validated against
     * the resource's declared countable relationships: a name the primary serializer
     * does not declare countable — because it is not
     * {@see \haddowg\JsonApi\Resource\Field\AbstractRelationBuilder::countable()}, or it is
     * to-one — is rejected. Either offence raises {@see RelationshipCountNotAllowed}
     * (400).
     *
     * A serializer that is not {@see CountableControlsInterface} declares no countable
     * relationships, and one that is not {@see CountableSelfInterface} is not
     * `_self_`-countable, so any `?withCount` against a bare serializer is rejected —
     * counting is opt-in. An empty `?withCount` (or none) is a no-op.
     */
    private function validateCountedRelationships(
        ResourceDocumentTransformation $transformation,
        SerializerInterface $primary,
    ): void {
        $requested = $transformation->request->getCountedRelationships();
        if ($requested === []) {
            return;
        }

        $offending = [];

        $selfRequested = \in_array(\haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN, $requested, true);
        if ($selfRequested) {
            // A related-collection render supplies the owning relation's `countable()`
            // as the override, so `_self_` is gated by the *relation* (whose endpoint
            // this is), not the related resource that happens to be the primary
            // serializer. Absent an override, `_self_` is gated by the primary
            // serializer's own resource-level countability.
            $selfCountable = $transformation->countableSelfOverride
                ?? ($primary instanceof \haddowg\JsonApi\Serializer\CountableSelfInterface && $primary->isCountable());
            if ($selfCountable === false) {
                $offending[] = \haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN;
            }
        }

        $relationNames = \array_values(\array_filter(
            $requested,
            static fn(string $name): bool => $name !== \haddowg\JsonApi\Schema\Profile\CountableProfile::SELF_TOKEN,
        ));

        $countable = $primary instanceof CountableControlsInterface
            ? $primary->getCountableRelationships($transformation->object)
            : [];

        $offending = [...$offending, ...\array_values(\array_diff($relationNames, $countable))];
        if ($offending !== []) {
            throw new RelationshipCountNotAllowed($offending);
        }
    }
}
