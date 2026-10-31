# Relationship-mutation gates are thrown by core, not left to routing

Relations declare `cannotReplace()`/`cannotAdd()`/`cannotRemove()` and endpoint exposure (`withoutRelatedEndpoint()`/`withoutRelationshipEndpoint()`), and core's relationship hydration throws the matching `403` itself (`FullReplacementProhibited`, `AdditionProhibited`, `RemovalProhibited`). Gating at the hydration seam rather than by not registering routes is necessary because core is router-agnostic and Atomic Operations dispatches mutations with no HTTP method to route on. A relationship mutation is an update of the parent, so a relation read-only on update fails the same gates.

## Consequences

Convention links omit a `self`/`related` link for a suppressed endpoint; hosts enforce the `404` for suppressed endpoints. Predicate-gated restrictions are judged per request and leave the static gates permissive, so routes and the OpenAPI document show the superset.
