<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Response;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Atomic\AtomicResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\Internal\RenderedDocument;
use haddowg\JsonApi\Server\ServerInterface;

/**
 * The success response of an Atomic Operations request: a `200 OK` document whose
 * sole primary member is `atomic:results`, an ordered array of one result fragment
 * per operation (each the operation's `{data?, meta?}`, or an empty object for an
 * operation with nothing to return — a result object carries no `links`).
 *
 * The response advertises the extension by carrying `ext="https://jsonapi.org/ext/atomic"`
 * on its `Content-Type` media-type parameter (see {@see extensions()}), alongside
 * the document-level `meta`/`links`/`jsonapi` the base supplies (those top-level
 * members are governed by the base rules; the per-result fragment is not). The
 * results array is rendered verbatim from the {@see AtomicResult} fragments the
 * executor produced; this response does no transformation of its own (the
 * per-operation rendering already happened in the loop).
 *
 * @see https://jsonapi.org/ext/atomic/
 */
final class AtomicResultsResponse extends AbstractResponse
{
    /**
     * @param list<AtomicResult> $results
     */
    private function __construct(private readonly array $results) {}

    /**
     * @param list<AtomicResult> $results the per-operation result fragments, in
     *                                     operation order
     */
    public static function fromResults(array $results): self
    {
        return new self(\array_values($results));
    }

    protected function render(ServerInterface $server, JsonApiRequestInterface $request): RenderedDocument
    {
        // An empty result must serialize as a JSON object `{}` (a result object),
        // not a JSON array `[]`: `json_encode([])` emits `[]`, so an empty fragment
        // is mapped to a `\stdClass` (encoded as `{}`). Content-bearing fragments
        // carry string keys and already encode as objects.
        $fragments = \array_map(
            static fn(AtomicResult $result): array|\stdClass => $result->hasContent() ? $result->fragment : new \stdClass(),
            $this->results,
        );

        $body = [AtomicExtension::RESULTS_MEMBER => $fragments];

        if ($this->meta !== []) {
            $body['meta'] = $this->meta;
        }

        if ($this->links !== null) {
            $body['links'] = $this->links->transform();
        }

        $body['jsonapi'] = $this->resolveJsonApi($server)->transform();

        return new RenderedDocument($body, 200);
    }

    /**
     * Advertises the Atomic Operations extension on the response `Content-Type`.
     *
     * @return list<string>
     */
    protected function extensions(): array
    {
        return [AtomicExtension::URI];
    }
}
