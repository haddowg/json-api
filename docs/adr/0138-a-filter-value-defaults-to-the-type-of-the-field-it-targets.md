# A filter value defaults to the type of the field it targets

A `filter[<key>]` parameter's value schema came from the filter's declared value
constraints and nothing else, so a filter that declared none — the common case, since
constraints exist for validation and most authors reach for them only where a bad value
would break something — projected `"schema": {}`. That is a parameter a generated client
types as `mixed` and a reader learns nothing from. The information was already in the
document: a filter names a column, and the fields of the type it filters name theirs. We
now match the two, and a filter that resolves to exactly one field documents as that
field's JSON type.

The fallback types the value and stops. No `format`, no `enum`, no `maxLength`, nothing
else the field carries: those describe the whole value of a member in a document body,
while a `filter[<key>]` is an **operand** the filter's operator compares, and the fallback
models no operator. A substring match against an enum column takes a substring, not an
enum member; an equality against a `date-time` column takes whatever the adapter parses,
which is not guaranteed to be RFC 3339. The type survives that move; nothing else reliably
does.

It applies only where the filter declared **no** value constraints at all. A filter that
declared one keeps exactly what it declared, even a `format` with no `type` beside it, and
even a type its column contradicts — an author who writes `Where::make('views')->uuid()`
over an integer column has said something, and a projector that quietly rewrote half of it
would produce a schema neither of them meant. This is a fallback, never an override.

Where the column does not resolve, the value documents as `string`. A relationship path
(`WhereThrough`), a relationship name (`WhereHas`), a group fanning one value across
several columns (`WhereAny`), a column two fields share, a computed field that backs no
column, a composite or relation field with no scalar wire form, a consumer filter that
names no column at all: in each of these a *narrower* type could only be guessed, and the
guess would sometimes be wrong. But `string` is not a guess. A query parameter is a string
on the wire whatever the server parses it into, so `{}` states less than the protocol
already guarantees, and a client generator reads it as `mixed` rather than as the one
thing it certainly is. Refusing to invent a narrowing is right; refusing to write down
what is already true is just throwing information away.

This is a floor and only a floor. It fills the scalar slot the container leaves open, so a
`Range` is still a `min`/`max` object and a set is still an array whose `items` the floor
types. A column-derived type beats it, and a declared constraint beats both.

Separately, each filter **kind** now projects its container shape whether or not it
declared constraints. A set filter (`WhereIn` and friends) is an array whose OAS style
spells its declared delimiter — `form`, `pipeDelimited` or `spaceDelimited`, and a
delimiter OAS cannot spell documents as the single opaque string the client really sends.
A presence-only filter (`WhereNull`, `WhereNotNull`, `WhereHas`, `WhereDoesntHave`) is a
string: the server decides the match and discards the value, so the column type would be a
lie there, and those four now report `isPresenceTriggered()` so they also carry the
"the value you send is ignored" sentence a `fixed()` filter already had. `Range` already
worked this way, and is unchanged.

## Consequences

Every document this library emits gains type information on filters that had none. It
never removes any, and never contradicts a declared constraint, so an integrator reading
the diff sees `{}` becoming `{"type": …}` and nothing else changing shape. A generated
client that typed those values as `mixed` will start typing them, which is the point and
the only thing to review.

With the `string` floor in place, no `filter[<key>]` this library projects carries an
empty value schema any more. A client generator therefore has a type for every filter
argument, and the ones it types as `string` are the ones to look at: that is exactly the
set where declaring a constraint would tell a reader something the projector cannot work
out on its own.
