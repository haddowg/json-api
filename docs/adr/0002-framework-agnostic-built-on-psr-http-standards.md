# Framework-agnostic, built on PSR HTTP standards

The library couples to no web framework or data store: it speaks PSR-7, PSR-15 and PSR-17 (and accepts PSR-3/PSR-11 where useful), and never assumes a container, a router or a framework's HTTP objects. This keeps one core usable under Laravel, Symfony or a bare PSR-7 stack, at the cost of consumers (or a framework integration) wiring factories, routing and persistence themselves.
