# Range filters take a nested `min`/`max` value, documented as a `deepObject`

`Range`/`DateRange` read a structured value, `filter[<key>][min]=…&filter[<key>][max]=…`, rather than a delimited `10,100`, because it is self-documenting, handles open-ended bounds, and maps to an OpenAPI `deepObject` parameter a generated client can serialise. Both bounds and the column are coerced through the filter's deserializer before comparing, so a range is numeric or temporal, never lexical (two ISO-8601 instants with different offsets compare equal).
