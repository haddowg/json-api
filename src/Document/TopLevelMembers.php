<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Document;

/**
 * The canonical order of a JSON:API document's top-level members.
 *
 * JSON:API does not mandate member order, but every serialized response is
 * normalised to this order — `data` (or, in its place, `errors`, or the Atomic
 * Operations extension's `atomic:results`) first and `jsonapi` last — so a
 * document's shape is identical regardless of how it was assembled. The generated
 * OpenAPI envelope schemas mirror the same order, so the rendered documentation
 * matches the wire.
 */
final class TopLevelMembers
{
    /**
     * @var list<string>
     */
    public const ORDER = ['data', 'errors', 'atomic:results', 'included', 'links', 'meta', 'jsonapi'];

    /**
     * Puts `$body`'s known top-level members in {@see ORDER}. `array_key_exists`
     * keeps a present-but-null member, such as an empty to-one's `data: null`, and an
     * unknown member follows the known set.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function order(array $body): array
    {
        $ordered = [];
        foreach (self::ORDER as $member) {
            if (\array_key_exists($member, $body)) {
                $ordered[$member] = $body[$member];
                unset($body[$member]);
            }
        }

        return [...$ordered, ...$body];
    }
}
