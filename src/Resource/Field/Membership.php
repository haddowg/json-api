<?php

declare(strict_types=1);

namespace haddowg\JsonApi\Resource\Field;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Schema\ResourceIdentifier;

/**
 * Compares a to-many relation's current members with a replacement's, the rule
 * {@see GuardsMembershipInterface::assertMembershipUnchanged()} applies.
 */
final class Membership
{
    /**
     * Whether `$incoming` names a different set of members from `$current`. Order and
     * duplicates are ignored; a member is its `type` and `id`, and one sent with only
     * a `lid` is never a current member.
     *
     * @param iterable<ResourceIdentifier|array{0: string, 1: string|int}> $current
     */
    public static function differs(iterable $current, ToManyRelationship $incoming): bool
    {
        $held = [];
        foreach ($current as $member) {
            $held[self::key(...($member instanceof ResourceIdentifier ? [$member->type, $member->id] : $member))] = true;
        }

        $sent = [];
        foreach ($incoming->resourceIdentifiers as $identifier) {
            if ($identifier->id === null) {
                return true;
            }
            $sent[self::key($identifier->type, $identifier->id)] = true;
        }

        return \array_diff_key($held, $sent) !== [] || \array_diff_key($sent, $held) !== [];
    }

    private static function key(string $type, string|int|null $id): string
    {
        return $type . "\0" . $id;
    }
}
