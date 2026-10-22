# Authentication and authorization failures carry core error codes

The adapters rendered a firewall `401` and an access-denied `403` as bare `status` +
`title` errors, so a client had no `code` to match them on, and each adapter would have
picked its own if left to it. Core now ships `AuthenticationRequired`
(`AUTHENTICATION_REQUIRED`, 401) and `AccessDenied` (`ACCESS_DENIED`, 403) even though it
never throws either. The code vocabulary is core's, the generated clients pin it, and a
code defined once here reads the same from every adapter. The titles stay the reason
phrases the adapters already sent.

## Consequences

`401` stops being the permanent exception in
[ADR 0139](0139-error-responses-are-shared-components-narrowed-per-status.md): a secured
operation's `Unauthorized` response now carries `ErrorDocument401`. Neither code names an
`ErrorFeature`. `401` is projected only on secured operations anyway, and `403` is
advertised on every operation because the application can deny a request anywhere, so a
read-only server's `403` now narrows to `ACCESS_DENIED` rather than falling back to the
generic document.

## Considered options

- **A feature gate on `AUTHENTICATION_REQUIRED` for servers with no security
  requirement.** Effective security is resolved per operation, per relation and per action,
  and the code only matters where a `401` response references it. An unsecured document
  lists the variant in the open `ErrorDocument` catalogue and nowhere else, which costs
  one unused component.
- **Leaving the codes to each adapter.** Two adapters, two spellings, and a client that
  cannot type either from the catalogue.
