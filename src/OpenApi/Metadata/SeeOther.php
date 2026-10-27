<?php

declare(strict_types=1);

namespace haddowg\JsonApi\OpenApi\Metadata;

/**
 * `303 See Other`: the response points the client at another URL through `Location`.
 *
 * On fetch-one it is the completion half of the async lifecycle: the job is done and
 * `Location` names the produced resource. Its runtime counterpart there is
 * {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect}.
 *
 * On a custom action it is the plain `303` too, unless it names a `mediaType`: what the
 * URL serves, such as `text/csv`, or `application/octet-stream` when that is not known.
 * Naming it opts the action in to answering with a URL, such as a short-lived download
 * link. The action then answers a request that prefers JSON:API with a `200` document
 * carrying the URL as its `links.location`, typed with that media type, and any other
 * request with the `303` (see {@see \haddowg\JsonApi\Response\SeeOtherResponse}).
 * Both are projected.
 *
 * `description` replaces the generated description of the projected responses.
 */
final readonly class SeeOther implements FetchOneResponse, ActionResponse
{
    public function __construct(
        public ?string $description = null,
        public ?string $mediaType = null,
    ) {
        if ($mediaType === null) {
            return;
        }

        $essence = \strtolower(\trim(\explode(';', $mediaType, 2)[0]));
        if (\preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $essence) !== 1 || \str_contains($essence, '*')) {
            throw new \InvalidArgumentException(\sprintf('A See Other response needs a concrete media type such as "text/csv"; got "%s".', $mediaType));
        }
    }

    public function status(): int
    {
        return 303;
    }

    public function jobType(): ?string
    {
        return null;
    }
}
