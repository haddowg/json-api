<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Link;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * The fluent authoring surface for an {@see ActionLink}, returned by
 * {@see ActionLink::for()}. Each method mutates the builder and returns it;
 * {@see build()} checks the declaration and freezes it.
 */
final class ActionLinkBuilder
{
    private ?string $description = null;

    private ?\Closure $when = null;

    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * Sets the condition the link renders under. It runs after the action's security
     * check passes, with the model being rendered and the request, and the link renders
     * only when it returns `true`.
     *
     * The model parameter may be typed as the resource's model class.
     *
     * @template TModel
     *
     * @param \Closure(TModel, JsonApiRequestInterface): bool $condition
     */
    public function when(\Closure $condition): self
    {
        $this->when = $condition;

        return $this;
    }

    /**
     * Sets the description the OpenAPI projection gives the link, in place of the generic
     * one every action link gets.
     */
    public function describedAs(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function build(): ActionLink
    {
        if ($this->path === '') {
            throw new \LogicException('An action link needs the path of the action it links to.');
        }

        return new ActionLink($this->path, $this->description, $this->when);
    }
}
