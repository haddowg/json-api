# Only an octet-stream location may vary its type

An action that answers with a URL projects its location's `type` as the media type it
declares, so a handler sending another type would break the published document. Declaring
`application/octet-stream` already meant the type varies, so that declaration projects `type`
as any string with the declared type as its example, and every other declaration keeps its
`const`. Dispatch throws when a handler's `withType()` changes the type itself, though it may
change the parameters. We didn't widen every declaration, because a client that knows the
type in advance should be able to rely on it.
