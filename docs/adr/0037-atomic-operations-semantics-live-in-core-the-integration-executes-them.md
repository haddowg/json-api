# Atomic Operations semantics live in core; the integration executes them

Core owns the framework-agnostic part of the Atomic Operations extension: parsing `atomic:operations` into ordered descriptors, the all-or-nothing loop over a small backend interface (`begin`/`commit`/`rollback`/`executeOne`), cross-operation `lid` resolution, and the `atomic:results` document. The integration owns only the endpoint, request detection and the transactional backend. Keeping the wire contract next to the document model means every integration gets the same conformance.

## Consequences

Any exception from an operation or the commit rolls the whole batch back and renders one error document with pointers prefixed by `/atomic:operations/<index>`. Because `lid` has meaning only inside a batch, a `lid` in a regular request body is a `400 LOCAL_ID_NOT_SUPPORTED` rather than being silently dropped.
