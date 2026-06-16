# Response-backed test-assertion families carry a plain-data envelope

The `Testing\JsonApiDocument` / `Testing\JsonApiErrors` wrappers asserted over a
**decoded body only**, so a test could never assert the status, content type, and
body *as a unit*, had no collection-level (`?sort`) witness, and no exact-match to
catch a leaked attribute. We extended both wrappers with response-envelope
assertions (`assertStatus` / `assertContentType` / `assertHeader`), a collection
family (`assertFetchedMany` / `assertFetchedManyInOrder` — the order-sensitive
sort witness — `assertCollectionCount` / `assertCollectionContains` /
`assertFetchedManyExact`), an exact-match family (`assertFetchedOneExact`,
`assertExactMeta` / `assertExactLinks`, `assertHasExactError` / `assertErrorsExact`),
included-membership (`assertHasIncludedResource` / `assertIncludedExactly`), and
absence (`assertNoData` / `assertNoMeta` / `assertNoLink`). Everything is additive
— every existing method keeps working.

The status + headers are carried as a tiny **plain-scalar** value object
(`ResponseMeta`: a nullable `int $status` plus an `array<string,string>` header
map with case-insensitive lookup), supplied either explicitly via a new nullable
constructor argument or extracted from a PSR-7 response by `Internal\Decode`. This
deliberately keeps the assertion path free of any `psr/http-message` dependency,
so both the core PSR-7 caller and a framework caller — the Symfony bundle's
`JsonApiBrowser` over an HttpFoundation `Response` — feed the envelope the same
way. Exact-match failures print a stable, readable diff because both sides are
recursively key-sorted first (`Internal\Diff`), while list order (significant for
a collection) is preserved.
