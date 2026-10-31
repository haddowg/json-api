# Unrecognised query input is rejected by default

The spec permits a `400` for a well-named custom parameter the server does not support, and we take that option by default so a client typo is not a wrong-but-`200` result: `Server` rejects a query-parameter family it does not recognise and, under the same flag, a `fields[type]` member the type does not declare. The check runs in `Server` rather than the negotiation middleware so integrations driving `dispatch()` get it too, before any serving hook. `withStrictQueryParameters(false)` restores tolerance.

## Consequences

The recognised families are assembled per request (the reserved families, host-registered ones and the keywords of negotiated profiles). A fieldset member is unknown only if it names no declared field at all, including hidden ones, so a hidden field and a bogus name behave identically and nothing leaks.
