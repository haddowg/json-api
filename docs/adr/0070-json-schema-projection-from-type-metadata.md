# Project JSON Schema 2020-12 directly from type metadata, and never guess a keyword

OpenAPI 3.1 Schema Objects are JSON Schema 2020-12, and the constraint vocabulary maps onto that dialect almost 1:1, so schemas are projected straight from field and constraint metadata rather than down-projected to an older draft. A constraint with no faithful 2020-12 expression (`When`, `CompareField`, date bounds on a `date-time` string) degrades to a human-readable note in the schema `description`, never a wrong or approximate keyword.
