# Links resolve to the request origin when no base URI is configured

With an empty `base_uri`, every generated link is prefixed with the request's own scheme and authority, so a multi-host deployment emits correct absolute links with no configuration; a configured base still pins one canonical host. A configured base's path is treated as the server's mount: document links strip a leading mount from the request path on a segment boundary, so a prefixed server never doubles it. An already-absolute href is never prefixed.
