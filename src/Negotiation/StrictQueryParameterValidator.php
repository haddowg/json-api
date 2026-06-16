<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Negotiation;

use haddowg\JsonApi\Exception\QueryParamUnrecognized;
use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Up-front strict query-parameter validation: rejects any query parameter whose
 * **base name / family** the server does not recognize, with a `400`
 * {@see QueryParamUnrecognized}.
 *
 * This complements — and is stricter than — the spec-baseline
 * {@see \haddowg\JsonApi\Request\JsonApiRequest::validateQueryParams()}, which
 * rejects only an all-`a-z` base name outside the reserved set. The baseline
 * silently tolerates a *well-named* custom family (one carrying a non-`a-z`
 * character, e.g. `relatedQuery`, `withCount`, or a typo'd `myFilter`), because
 * the spec permits a server to either reject or ignore one it does not support.
 * Strict mode takes the **reject** option for every base name not in the
 * recognized set, so a client typo (a wrong-but-`200` silent drop) surfaces as a
 * clean error instead.
 *
 * The recognized set is the family base names the server understands for the
 * resolved primary resource:
 *  - the reserved JSON:API families (`include`, `fields`, `sort`, `page`,
 *    `filter`, `profile`) — their internal key validation is the responsibility
 *    of the request parsers; this validator only gates the **family** name;
 *  - the implementation-specific custom families the server recognizes
 *    (`withCount`, a negotiated profile's reserved keywords, and any
 *    app-registered custom param).
 *
 * The validator is a pure, request-agnostic function of that set: the
 * {@see \haddowg\JsonApi\Server\Server} assembles the set (reserved + custom
 * registry + the keywords of every registered profile the request negotiated)
 * and runs this up front, before the operation handler.
 */
final readonly class StrictQueryParameterValidator
{
    /**
     * The reserved JSON:API query-parameter families. Their family name is always
     * recognized; the spec-defined internal key validation (an unknown
     * `filter`/`sort` key, a malformed `page`) is performed by the request
     * parsers, not here.
     *
     * @var list<string>
     */
    public const array RESERVED_FAMILIES = ['include', 'fields', 'sort', 'page', 'filter', 'profile'];

    /**
     * The recognized base names, as a name → name map for O(1) membership testing.
     *
     * @var array<string, string>
     */
    private array $recognized;

    /**
     * @param iterable<string> $customFamilies the implementation-specific family
     *                                          base names the server recognizes
     *                                          (e.g. `withCount`, a negotiated
     *                                          profile's keywords, app-registered
     *                                          custom params)
     */
    public function __construct(iterable $customFamilies = [])
    {
        $recognized = [];
        foreach (self::RESERVED_FAMILIES as $family) {
            $recognized[$family] = $family;
        }
        foreach ($customFamilies as $family) {
            $recognized[$family] = $family;
        }

        $this->recognized = $recognized;
    }

    /**
     * Rejects the first query parameter whose base name is not recognized.
     *
     * @throws QueryParamUnrecognized
     */
    public function validate(JsonApiRequestInterface $request): void
    {
        foreach (\array_keys($request->getQueryParams()) as $base) {
            $base = (string) $base;
            if (isset($this->recognized[$base]) === false) {
                throw new QueryParamUnrecognized($base);
            }
        }
    }
}
