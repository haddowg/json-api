<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Schema\Document\MetaDocument;
use haddowg\JsonApi\Schema\Document\SingleResourceDocument;
use haddowg\JsonApi\Serializer\SerializerInterface;
use haddowg\JsonApi\Server\ServerInterface;
use haddowg\JsonApi\Transformer\DocumentTransformer;
use haddowg\JsonApi\Transformer\ResourceDocumentTransformation;

/**
 * A `202 Accepted` response: the server has accepted a write for asynchronous
 * processing but has not completed it. The declarative async-write affordance —
 * a handler that queues the work returns this instead of hand-assembling a PSR-7
 * `202` (JSON:API 1.1 §"Asynchronous Processing").
 *
 * The body describes the accepted operation: either a **job resource** the client
 * can poll ({@see forResource()}), or a meta-only status document
 * ({@see fromMeta()}). {@see withContentLocation()} advertises the URL of that job
 * resource in the `Content-Location` header (spec-recommended), and
 * {@see withRetryAfter()} hints when to poll it. When the job completes, that URL
 * responds with a {@see SeeOtherResponse} (`303`) pointing at the created resource.
 *
 * Unlike {@see DataResponse}, no top-level `self` is merged — the request URI (the
 * write target) is not the job resource's URI; the job's own `self` comes from its
 * serializer links, and its polling URL is the `Content-Location`.
 */
final class AcceptedResponse extends AbstractResponse
{
    /**
     * @param \haddowg\JsonApi\Serializer\SerializerInterface|null $resource the job
     *        resource's serializer, or null for a meta-only status document
     */
    private function __construct(
        private readonly mixed $data,
        private readonly ?SerializerInterface $resource,
    ) {}

    /**
     * A `202` whose `data` is a job resource the client can poll for completion —
     * the representation of the accepted operation's progress.
     */
    public static function forResource(mixed $object, SerializerInterface $resource): self
    {
        return new self($object, $resource);
    }

    /**
     * A `202` carrying only a top-level `meta` status document (no primary `data`),
     * for an async accept that has no pollable job resource to represent.
     *
     * @param array<string, mixed> $meta
     */
    public static function fromMeta(array $meta): self
    {
        $self = new self(null, null);
        $self->meta = $meta;

        return $self;
    }

    /**
     * Advertises the URL of the accepted operation's job resource in the
     * `Content-Location` header — where the client polls for completion. The JSON:API
     * async recommendation says the `202` SHOULD carry this.
     */
    public function withContentLocation(string $uri): static
    {
        return $this->withHeader('Content-Location', $uri);
    }

    /**
     * Sets the `Retry-After` header hinting how long the client should wait before
     * polling the job resource. An `int` is emitted as delta-seconds; a
     * {@see \DateTimeInterface} as an IMF-fixdate HTTP-date (RFC 7231), normalised to
     * GMT without mutating the passed value.
     */
    public function withRetryAfter(int|\DateTimeInterface $after): static
    {
        $value = \is_int($after)
            ? (string) $after
            : \DateTimeImmutable::createFromInterface($after)
                ->setTimezone(new \DateTimeZone('GMT'))
                ->format('D, d M Y H:i:s') . ' GMT';

        return $this->withHeader('Retry-After', $value);
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        $document = $this->resource !== null
            ? new SingleResourceDocument($this->resource, $this->resolveJsonApi($server), $this->meta, $this->links)
            : new MetaDocument($this->resolveJsonApi($server), $this->meta, $this->links);

        $transformation = new ResourceDocumentTransformation(
            $document,
            $this->data,
            $request,
            '',
            '',
            [],
            \haddowg\JsonApi\Server\RequestBaseUri::resolve($server->baseUri(), $request->getUri()),
            $server->maxIncludeDepth(),
        );

        $transformer = new DocumentTransformer();
        $result = $this->resource !== null
            ? $transformer->transformResourceDocument($transformation)->result
            : $transformer->transformMetaDocument($transformation)->result;

        return new RenderedDocument($result, 202);
    }
}
