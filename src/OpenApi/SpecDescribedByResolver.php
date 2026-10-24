<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi;

use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;

/**
 * Points each link's `describedby` at its operation in one OpenAPI document: the
 * document's URL plus a JSON Pointer fragment (`#/paths/~1albums~1%7Bid%7D/get`).
 *
 * The URL is used as given, so a host-relative one (`/api/v5/docs.json`) yields
 * host-relative references and an absolute one absolute references. An operation the
 * document doesn't carry resolves to `null`, so a link never points at a fragment that
 * isn't there. Build it from the projected document ({@see fromDocument()}) or the
 * server metadata the document is projected from ({@see fromMetadata()}) so the set of
 * operations is the one the document holds.
 */
final readonly class SpecDescribedByResolver implements DescribedByResolverInterface
{
    private string $documentUrl;

    /**
     * @param string                      $documentUrl the served document's URI-reference; any fragment is dropped
     * @param array<string, list<string>> $operations  path template → the lower-case methods the document carries for it
     */
    public function __construct(
        string $documentUrl,
        private array $operations,
    ) {
        $hash = \strpos($documentUrl, '#');
        $this->documentUrl = $hash === false ? $documentUrl : \substr($documentUrl, 0, $hash);
    }

    public static function fromDocument(string $documentUrl, OpenApi $document): self
    {
        return self::fromPaths($documentUrl, $document->paths ?? new Paths());
    }

    public static function fromPaths(string $documentUrl, Paths $paths): self
    {
        return new self($documentUrl, self::operationsOf($paths));
    }

    /**
     * The operations a document (or its `paths`) carries, in the shape the constructor
     * takes, so an adapter can build the map once at warmup and construct the resolver
     * per request without re-projecting.
     *
     * @return array<string, list<string>> path template → the lower-case methods the document carries for it
     */
    public static function operationsOf(Paths|OpenApi $source): array
    {
        $paths = $source instanceof OpenApi ? ($source->paths ?? new Paths()) : $source;

        $operations = [];
        foreach ($paths->items as $path => $item) {
            $operations[$path] = \array_keys($item->operations);
        }

        return $operations;
    }

    public static function fromMetadata(string $documentUrl, ServerMetadataInterface $metadata, OpenApiProjector $projector = new OpenApiProjector()): self
    {
        return self::fromDocument($documentUrl, $projector->project($metadata));
    }

    public function documentUrl(): string
    {
        return $this->documentUrl;
    }

    /**
     * @return array<string, list<string>> path template → the lower-case methods the document carries for it
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function describedBy(OperationReference $operation): ?string
    {
        if (!\in_array($operation->method, $this->operations[$operation->path] ?? [], true)) {
            return null;
        }

        return $this->documentUrl . '#' . $operation->fragment();
    }
}
