<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A resource's declaration that one of its type's resource-scope custom actions renders
 * as a member of each resource object's `links`, declared in
 * {@see \haddowg\JsonApi\Resource\AbstractResource::links()} beside its
 * {@see DeclaredLink}s.
 *
 * The link is keyed by the action's path, and the adapter builds its href, method and
 * `describedby` from the action itself. The declaration adds what the action can't say
 * on its own: a condition the model and request must meet for the link to render, and the
 * description the OpenAPI projection gives the link.
 *
 * Build one with {@see for()}. An adapter checks the action's security first and asks
 * {@see appliesTo()} only when that passes.
 */
final readonly class ActionLink
{
    /**
     * Build through {@see for()}, which checks the declaration is complete.
     */
    public function __construct(
        public string $path,
        public ?string $description = null,
        private ?\Closure $when = null,
    ) {}

    /**
     * Declares the link for the resource-scope action at `$path` on the resource's own type.
     */
    public static function for(string $path): ActionLinkBuilder
    {
        return new ActionLinkBuilder($path);
    }

    /**
     * Whether the link renders for this model and request. With no condition it always
     * does; otherwise the condition must return `true`.
     */
    public function appliesTo(mixed $model, JsonApiRequestInterface $request): bool
    {
        return $this->when === null || ($this->when)($model, $request) === true;
    }
}
