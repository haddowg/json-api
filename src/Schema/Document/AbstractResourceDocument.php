<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Document;

use haddowg\JsonApi\Exception\InclusionDepthExceeded;
use haddowg\JsonApi\Exception\InclusionNotAllowed;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;
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
        $requestedPaths = $transformation->request->getIncludePaths();

        if ($primary instanceof IncludeControlsInterface) {
            $allowed = $primary->getAllowedIncludePaths();
            if ($allowed !== null) {
                // A requested path is permitted when it is a listed path or an
                // ancestor of one — listing a deep path (`posts.author`) implies its
                // intermediates (`posts`) are traversable, so the author need not
                // enumerate every prefix. A sibling or unlisted nested path
                // (`posts.comments`) is not implied and is rejected.
                $offending = \array_values(\array_filter(
                    $requestedPaths,
                    static function (string $path) use ($allowed): bool {
                        foreach ($allowed as $allowedPath) {
                            if ($allowedPath === $path || \str_starts_with($allowedPath, $path . '.')) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));
                if ($offending !== []) {
                    throw new InclusionNotAllowed($offending);
                }
            }
        }

        $override = $primary instanceof IncludeControlsInterface ? $primary->maxIncludeDepth() : null;
        $effective = $override ?? $transformation->maxIncludeDepth;
        if ($effective !== null && $effective <= 0) {
            $effective = null;
        }

        if ($effective !== null) {
            $tooDeep = \array_values(\array_filter(
                $requestedPaths,
                static fn(string $path): bool => \substr_count($path, '.') + 1 > $effective,
            ));
            if ($tooDeep !== []) {
                throw new InclusionDepthExceeded($tooDeep, $effective);
            }
        }

        return $effective;
    }
}
