<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a singleton type resolves the caller's id from: the type, the request when there is
 * one, and whatever else the adapter has to hand, such as the authenticated user or its
 * framework's own request object, as named attributes.
 *
 * The request is `null` when an operation is dispatched with no HTTP message behind it. Each
 * adapter documents the attributes it passes.
 */
final readonly class SingletonContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $type,
        public ?ServerRequestInterface $request = null,
        public array $attributes = [],
    ) {}

    /**
     * The attribute named `$name`, or `$default` when the adapter passed none.
     */
    public function attribute(string $name, mixed $default = null): mixed
    {
        return \array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self($this->type, $this->request, [...$this->attributes, $name => $value]);
    }
}
