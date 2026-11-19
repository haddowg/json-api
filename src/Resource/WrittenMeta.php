<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The typed `meta` a write sent, once an adapter validated it: the request document's
 * top-level `meta` and its resource object's `data.meta`, each in the form its declared
 * members cast to, a date as a `DateTimeImmutable` and an enum as its case. An object its
 * type does not declare is carried as sent.
 *
 * An adapter puts it on the request it hands the hydrator and the write's hooks, under
 * this class's name as a request attribute; {@see of()} reads it back, empty when the
 * request carries none.
 */
final readonly class WrittenMeta
{
    /**
     * @param array<string, mixed> $document the request document's top-level `meta`
     * @param array<string, mixed> $resource the resource object's `data.meta`
     */
    public function __construct(
        public array $document = [],
        public array $resource = [],
    ) {}

    /**
     * The written meta a request carries, or an empty one.
     */
    public static function of(ServerRequestInterface $request): self
    {
        $meta = $request->getAttribute(self::class);

        return $meta instanceof self ? $meta : new self();
    }

    /**
     * `$request` carrying this written meta.
     *
     * @template T of ServerRequestInterface
     *
     * @param T $request
     *
     * @return T
     */
    public function on(ServerRequestInterface $request): ServerRequestInterface
    {
        /** @var T */
        return $request->withAttribute(self::class, $this);
    }

    /**
     * One member of the top-level `meta`, or `$default` when it was not sent.
     */
    public function document(string $name, mixed $default = null): mixed
    {
        return \array_key_exists($name, $this->document) ? $this->document[$name] : $default;
    }

    /**
     * One member of `data.meta`, or `$default` when it was not sent.
     */
    public function resource(string $name, mixed $default = null): mixed
    {
        return \array_key_exists($name, $this->resource) ? $this->resource[$name] : $default;
    }
}
