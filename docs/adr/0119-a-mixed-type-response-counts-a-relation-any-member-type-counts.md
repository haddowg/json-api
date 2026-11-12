# A mixed-type response counts a relation any member type counts

On a response whose primary data mixes types, `?withCount=<rel>` is accepted when at least one member type declares `<rel>` countable, and each member renders its own `meta.total` where its type has the relation. This matches how `?include` already behaves on a mixed response. Requiring every member type to count the name would make `withCount` almost unusable on polymorphic relations, and adding a new member type would start breaking requests that worked before.

## Considered options

Summing the members' counts into one figure was rejected: the members' relations are different relations that happen to share a name, so a total across them means nothing.
