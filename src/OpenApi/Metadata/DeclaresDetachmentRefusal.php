<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Relation metadata that says whether the data layer can refuse to detach a current
 * member, because the member's foreign key to the parent cannot be null, answering a
 * replacement with `FULL_REPLACEMENT_PROHIBITED` and a removal or a cleared to-one with
 * `REMOVAL_PROHIBITED`, on the relationship endpoint and in an update alike.
 *
 * A separate, opt-in contract rather than a method on {@see RelationMetadataInterface}.
 * Metadata that doesn't implement it offers both codes on every write that can detach a
 * member.
 */
interface DeclaresDetachmentRefusal extends RelationMetadataInterface
{
    /**
     * Whether detaching a member can be refused, such as an inverse `NOT NULL` foreign
     * key the persister will not null.
     */
    public function canRefuseDetachment(): bool;
}
