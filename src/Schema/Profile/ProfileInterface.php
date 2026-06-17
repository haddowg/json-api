<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Schema\Profile;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * A JSON:API 1.1 profile: a named set of document members and processing rules,
 * reserved for implementors, that a server MAY apply to a response.
 *
 * Profiles are advisory. A server applies the profiles it recognizes and MUST
 * ignore any it does not; an unrecognized profile is never an error (unlike an
 * unsupported extension). A profile declares its canonical {@see uri()} (matched
 * against the negotiated `profile` media-type parameter), the keywords it
 * reserves, and an optional document-finalisation hook.
 *
 * @see https://jsonapi.org/format/1.1/#profiles
 */
interface ProfileInterface
{
    /**
     * The profile's canonical URI — the value advertised in `jsonapi.profile` and
     * echoed in the response `Content-Type` `profile` parameter.
     */
    public function uri(): string;

    /**
     * The member / link-relation / query-parameter names this profile reserves.
     *
     * Used for documentation and introspection (and future schema validation);
     * it does not gate negotiation.
     *
     * @return list<string>
     */
    public function keywords(): array;

    /**
     * Finalisation hook, run once for this profile after the document body array
     * has been assembled and before it is encoded. The hook receives the body and
     * the active request and returns the (possibly augmented) body. Only profiles
     * the server has applied are run.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function finalizeDocument(array $document, JsonApiRequestInterface $request): array;
}
