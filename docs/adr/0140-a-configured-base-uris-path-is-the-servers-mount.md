# A configured base URI's path is the server's mount

When a server is routed under a prefix and its `base_uri` carries that prefix (`/api/v5`, or
`https://host/api/v5`), resource links append a server-relative path to the base, but document
`self` and pagination links reused the request path, which already starts with the prefix, so the
mount appeared twice. A configured base's path is now treated as the server's mount: a document
link is the base plus the request path with a leading mount removed on a segment boundary. That
gives one rule for both kinds of link without new configuration, keeps a request path a proxy
already stripped working as before, and leaves an empty base (request-origin links, ADR 0087)
unchanged.
