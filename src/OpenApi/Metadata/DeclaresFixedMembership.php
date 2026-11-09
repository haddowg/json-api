<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether a to-many relation declares
 * `cannotChangeMembership()`, so a replacement that changes its members is refused with
 * `MEMBERSHIP_CHANGE_PROHIBITED`. The error catalogue offers that code, and the document
 * carries the {@see \haddowg\JsonApi\OpenApi\GeneratorContract::FIXED_MEMBERSHIP} tier,
 * only when some relation reports it.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface},
 * so an existing implementation keeps working untouched, the same shape as
 * {@see DeclaresIdentifiedMembers}.
 */
interface DeclaresFixedMembership extends RelationMetadataInterface
{
    /**
     * Whether the relation declares `cannotChangeMembership()`, unconditionally or with a
     * closure. Report {@see \haddowg\JsonApi\Resource\Field\GuardsMembershipInterface::fixesMembership()}
     * for a core relation.
     */
    public function fixesMembership(): bool;
}
