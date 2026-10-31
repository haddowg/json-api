# The projected error-code catalogue is open

Each stable error code projects as a named `<Code>Error` schema, offered from the error document as an `anyOf` whose first branch is the generic `Error`, so the catalogue is discoverable without being authoritative: a server raising an application's own code still validates against its own schema. Membership is open as well: `ErrorCatalog` composes sources (core's, discovered from `src/Exception`, plus codes a server contributes through `ContributesErrorCodes`), assembled per server and gated by the `ErrorFeature`s it offers, and two classes claiming one code throws. Each code is described statically by `describe(): ErrorDescriptor`, which the exception also renders through, so the description and the error cannot disagree.

## Considered options

- A closed `oneOf` or a `discriminator` on `code`: both make an application's own codes invalid against the server's schema.
- A hand-written roster, or constructing exceptions with placeholder arguments: a second copy that is closed by construction, or fiction in every `detail`.

## Consequences

`Error::$context` is never projected; it is template input that never reaches the wire. Core also catalogues codes only adapters raise (`AUTHENTICATION_REQUIRED`, `ACCESS_DENIED`, `METHOD_NOT_ALLOWED`), so every adapter answers with one vocabulary; an adapter-only code is gated on an opt-in metadata interface so a server that does not use it projects an unchanged document.
