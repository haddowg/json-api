<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Link\Internal;

use haddowg\JsonApi\OpenApi\DescribedByResolverInterface;
use haddowg\JsonApi\OpenApi\OperationReference;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\LinkObject;
use haddowg\JsonApi\Schema\Link\OperationLink;
use haddowg\JsonApi\Server\ConfiguresLinksInterface;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * Renders the links the library builds (by-convention, top-level `self`, pagination
 * and contributed links) in the server's chosen form. Author-supplied link containers
 * never pass through here, so they keep the shape their author gave them.
 *
 * @internal
 */
final readonly class LinkRendering
{
    public function __construct(
        public bool $objects = true,
        public ?DescribedByResolverInterface $describedBy = null,
    ) {}

    public static function forServer(ServerInterface $server): self
    {
        if (!$server instanceof ConfiguresLinksInterface) {
            return new self();
        }

        return new self($server->rendersLinkObjects(), $server->describedByResolver());
    }

    /**
     * Names the operation a dispatched response was produced by, so its top-level links
     * can be described. Left alone when the handler already named one, when the server
     * has no resolver to describe it with, or when the operation has no single path.
     *
     * @template T of \haddowg\JsonApi\Response\AbstractResponse
     *
     * @param T $response
     *
     * @return T
     */
    public static function describeResponse(
        \haddowg\JsonApi\Response\AbstractResponse $response,
        \haddowg\JsonApi\Operation\JsonApiOperationInterface $operation,
        \haddowg\JsonApi\Server\ResolvingServerInterface $server,
    ): \haddowg\JsonApi\Response\AbstractResponse {
        if ($response->operationReference() !== null
            || !$server instanceof ConfiguresLinksInterface
            || $server->describedByResolver() === null) {
            return $response;
        }

        $reference = OperationReference::forOperation($operation, $server);

        return $reference === null ? $response : $response->withOperationReference($reference);
    }

    /**
     * A built link to `$href`, described by `$operation` when it is known.
     *
     * @param array<string, mixed> $meta
     *
     * @return string|array<string, mixed>
     */
    public function render(string $href, ?OperationReference $operation = null, array $meta = []): string|array
    {
        if (!$this->objects) {
            return $meta === [] ? $href : ['href' => $href, 'meta' => $meta];
        }

        $link = ['href' => $href];

        $describedBy = $this->describedByFor($operation);
        if ($describedBy !== null) {
            $link['describedby'] = $describedBy;
        }

        if ($meta !== []) {
            $link['meta'] = $meta;
        }

        return $link;
    }

    /**
     * A contributed {@see Link}. A {@see LinkObject} is already an explicit object and
     * renders as its contributor built it; an {@see OperationLink} is described by the
     * operation it names.
     *
     * @return string|array<string, mixed>
     */
    public function renderLink(Link $link, string $baseUri): string|array
    {
        if ($link instanceof LinkObject) {
            return $link->transform($baseUri);
        }

        return $this->render(
            $link->resolvedHref($baseUri),
            $link instanceof OperationLink ? $link->operation : null,
            $link->meta,
        );
    }

    /**
     * Re-renders an already-rendered string link (the shape the link containers emit
     * for a meta-less {@see Link}) in this form. An object stays as it is.
     */
    public function upgrade(mixed $rendered, ?OperationReference $operation): mixed
    {
        return \is_string($rendered) ? $this->render($rendered, $operation) : $rendered;
    }

    /**
     * The top-level `describedby` link for a document produced by `$operation`, or
     * `null` when there is no resolver or it doesn't describe that operation.
     *
     * @return string|array<string, mixed>|null
     */
    public function documentDescribedBy(?OperationReference $operation): string|array|null
    {
        $describedBy = $this->describedByFor($operation);
        if ($describedBy === null) {
            return null;
        }

        return $this->objects ? ['href' => $describedBy] : $describedBy;
    }

    private function describedByFor(?OperationReference $operation): ?string
    {
        if ($operation === null || $this->describedBy === null) {
            return null;
        }

        return $this->describedBy->describedBy($operation);
    }
}
