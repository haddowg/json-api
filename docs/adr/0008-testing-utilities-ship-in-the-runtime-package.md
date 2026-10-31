# Testing utilities ship in the runtime package

The JSON:API-aware assertions and request/operation builders are in the production autoload, not a dev-only package, so consumers get document-aware test helpers without re-implementing them. In exchange the surface stays narrow (assertions and builders only, no factories, fixtures, database traits or HTTP clients) so it never drags a test framework or persistence dependency into a production install.
