# Framework-agnostic, built on PSR HTTP standards

The library couples to no web framework. It speaks PSR-7 messages, PSR-15
middleware and handlers, and PSR-17 factories throughout, and accepts a PSR-3
logger where one is useful. Anything that would tie it to a persistence or query
layer is pushed out to consumer-provided adapters (see
[ADR 0007](0007-metadata-in-core-execution-in-adapters.md)).

This keeps the package usable from Laravel, Symfony, Slim, or a bare PSR-7 stack,
at the cost of consumers wiring up the PSR-17 factories and routing themselves —
the library never assumes a container, a router, or a framework's HTTP objects.
