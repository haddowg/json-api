<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Atomic;

/**
 * The result fragment of one atomic operation, as rendered into the response's
 * `atomic:results` array.
 *
 * Per the extension, each operation contributes a result object — either a `data`
 * member (the created/updated resource or its identifier, optionally with `meta`)
 * or, for operations with nothing to return (a `remove`, or an `update` the server
 * fully applied), an empty object. {@see empty()} models the latter;
 * {@see fromDocument()} the former, taking the already-rendered `{data?, meta?}`
 * fragment.
 *
 * The extension is exhaustive on the result-object shape: a result object MAY
 * contain `data` and/or `meta` only. `links` and `included` are **not** result-object
 * members and must never appear here — a per-resource self link belongs inside the
 * resource object's own `data.links`, not as a sibling of `data`. This is a leaf
 * value object: the readonly property is the accessor — no getters.
 *
 * @see https://jsonapi.org/ext/atomic/#result-objects
 */
final readonly class AtomicResult
{
    /**
     * @param array<string, mixed> $fragment the rendered `{data?, meta?}` members,
     *                                        or `[]` for an empty result
     */
    private function __construct(public array $fragment) {}

    /**
     * An empty result: the operation completed with no content to return, rendered
     * as an empty object in the `atomic:results` array.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * A result carrying a rendered document fragment — the `{data?, meta?}` members
     * of one operation's result object (never `links` or `included`: not result-object
     * members).
     *
     * @param array<string, mixed> $fragment
     */
    public static function fromDocument(array $fragment): self
    {
        return new self($fragment);
    }

    /**
     * Whether this result carries content (a non-empty fragment).
     */
    public function hasContent(): bool
    {
        return $this->fragment !== [];
    }
}
