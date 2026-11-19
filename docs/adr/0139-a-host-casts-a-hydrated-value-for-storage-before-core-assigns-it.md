# A host casts a hydrated value for storage before core assigns it

Core hydrates a `Decimal` as a `float` and assigns it with strict types, so a decimal column a mapper reads as a string, typed `string` on the entity, failed with a `TypeError` that only the data layer could prevent, because only it knows the column. `Server::withStorageCast()` gives the host one call per attribute assignment, with the model, member, value and field, rather than changing what a field hydrates for every data layer. Composites and `fillUsing()` hooks write their own values and are not cast.
