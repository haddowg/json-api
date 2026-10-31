# Relationship endpoints are linkage-only

`GET /{type}/{id}/relationships/{rel}` renders linkage only and never a compound `included`; `?include` there is tolerated but inert. Resolving the include tree against the parent produced resources unreachable from the primary linkage, which is incoherent as a compound document, and the spec's compound-document machinery is defined for endpoints whose primary data is resources. Inclusion stays available on the related endpoint.
