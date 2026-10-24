<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * Action metadata for an action the server also renders as a member of its type's
 * resource `links`, so the type's resource links schema can list it by name.
 *
 * A separate, opt-in contract rather than a method on {@see ActionMetadataInterface},
 * so an existing implementation keeps working, the same shape as
 * {@see DeclaresWriteOnlyRelation}.
 */
interface DeclaresActionLink extends ActionMetadataInterface
{
    /**
     * The name of the resource `links` member the action renders as, or `null` when
     * it renders no link. Only a {@see ActionScope::Resource} action has a resource to
     * hang a link off; a name on a collection-scope action is ignored.
     */
    public function linkName(): ?string;
}
