# The parsed request flows by substitution through the middleware chain

The JSON:API-parsed request is propagated by *replacing* the request object
passed down the PSR-15 chain, not by stashing it on a request attribute. The
first middleware that needs it wraps the incoming request in `JsonApiRequest` if
it isn't already one — an idempotent, memoizing operation — and passes that down,
so every downstream middleware, the handler, and the operation adapter share one
memoized parse. The only request *attribute* the library reads is the
routing-supplied operation `Target`.

This is a deliberate deviation from the obvious "stash it on an attribute"
pattern: it avoids an attribute-key coupling and a redundant second parse. The
trade-off is that it relies on middleware ordering being correct.
