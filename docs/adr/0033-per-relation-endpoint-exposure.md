# Per-relation endpoint exposure and conditional convention links

A relation declares per-endpoint exposure — `withoutRelatedEndpoint()` /
`withoutRelationshipEndpoint()` suppress its related / relationship-linkage HTTP
endpoints — plus `cannotAdd()`, mirroring the existing `cannotReplace()` /
`cannotRemove()` mutability flags. The by-convention relationship links omit the
`related` / `self` link for a suppressed endpoint so a rendered link never points
at a host 404, and `AdditionProhibited` (403) completes the replace / add / remove
gate trio thrown from core's relationship-mutation path. Core declares the flags,
keeps the convention links consistent, and gates its own hydrator path; host
adapters enforce the HTTP statuses (404 for a suppressed endpoint, 403 for a
prohibited addition).
