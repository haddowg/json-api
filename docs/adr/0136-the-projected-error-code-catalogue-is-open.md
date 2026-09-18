# The projected error-code catalogue is open

The projection described `Error.code` as a bare `{"type": "string"}`, so the 53 stable
codes core raises — each with a fixed status, a fixed default title and a known `source`
member — reached a generated client as an opaque string to match on by hand. We now emit
one named `components.schemas.<Code>Error` per code (`allOf: [$ref Error, {code: const,
status: const, source: required}]`, with core's default title as the schema's `title`
annotation) and offer them from `ErrorDocument.errors.items` as an **`anyOf` whose first
branch is the open generic `Error`**.

The open branch is the whole decision. A closed `oneOf` would read as an exhaustive list,
and it would be wrong the first time an application threw an error of its own: the server
would emit a document that fails its own published schema. Keeping the generic branch
means the `anyOf` constrains nothing — every error object already satisfies it — so the
catalogue is **discoverable without being authoritative**. A generator reads the named
variants to emit typed exception subclasses and falls back to a status-based exception,
with the raw code intact, for a code it does not recognise. We accept that the validation
value is nil and the document grows by roughly 50 schema components; discovery is the
point, and validation of `code` was never possible anyway.

Two supporting decisions. **`context` is not a property.** `Error::$context` is the
interpolation input core fills into the `title` / `detail` templates ([ADR
0128](0128-localizable-error-catalogue-via-code-keyed-resolver.md)) and never reaches the
wire, so typing it as a member would describe something no server sends. It is published
as an `x-error-context` extension naming the `{placeholder}` tokens and their types —
useful to whoever writes the replacement templates, honest about not being data the
client receives. **The catalogue is registration-aware.** Each descriptor names the
`ErrorFeature` it depends on (atomic operations, cursor pagination, a pagination menu,
the Countable profile, client-generated ids, or any write at all) and the projector drops
codes the server cannot raise, the same gating [ADR
0131](0131-registration-aware-openapi-projection.md) applies to `?withCount` and the
write components.

## Considered options

- **A closed `oneOf`.** Rejected above: it makes an application's own error codes invalid
  against the server's own schema.
- **A `discriminator` on `code`.** OpenAPI's `discriminator` needs a closed mapping to be
  useful and is only advisory in 3.1; it buys a generator nothing the `const` does not,
  and reintroduces the closed-list problem in the mapping.
- **Serializing `context` into the error's `meta`.** It would make the typed-context story
  work end to end, but it changes every error document for every existing server, and ADR
  0128 deliberately kept context internal. Out of scope for a projection change.
- **Reading the catalogue by reflecting over `src/Exception` and constructing each
  exception with placeholder arguments.** Fragile and dishonest — `getErrors()` builds its
  errors from constructor state, so the arguments would have to be invented and the
  resulting `detail` would be fiction. Instead each exception gained a static
  `describe(): ErrorDescriptor` (a new opt-in `DescribedErrorInterface`, not a widening of
  `JsonApiExceptionInterface`, so an application's own exceptions keep working undescribed)
  and now renders its `Error` **through** that descriptor, so the published description
  and the rendered error cannot disagree.
