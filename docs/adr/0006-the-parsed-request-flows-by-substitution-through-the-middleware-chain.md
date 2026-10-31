# The parsed request flows by substitution through the middleware chain

The JSON:API-parsed request is propagated by replacing the request object passed down the PSR-15 chain, not by stashing it on a request attribute: the first middleware that needs it wraps the request in `JsonApiRequest` (idempotently) and passes that on, so the whole chain shares one memoized parse. This avoids an attribute-key coupling and a second parse, at the cost of relying on correct middleware ordering. The only request attribute the library reads is the routing-supplied `Target`.
