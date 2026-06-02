# Testing utilities ship in the runtime package

The JSON:API-aware assertion wrappers and the request/operation builders are part
of the package's production autoload, not `require-dev`. Consumers get test
helpers that understand JSON:API documents without re-implementing them, at the
small cost of a handful of extra classes on the runtime classpath.

The surface is kept deliberately narrow — assertions and builders only, no
factories, fixtures, database traits, or HTTP clients — so shipping it in the
runtime package never drags a test framework or persistence dependency into a
consumer's production install.
