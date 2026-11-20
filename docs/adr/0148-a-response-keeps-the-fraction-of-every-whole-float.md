# A response keeps the fraction of every whole float

A whole `Decimal` went out as `5`, because `json_encode` drops the fraction of a whole float, so a client that tells integers from decimals by their fraction read an integer. Every response now encodes with `JSON_PRESERVE_ZERO_FRACTION`, added to whatever encode options the server or the response sets. A flag scoped to `Decimal` values was not possible: PHP's encoder has no per-value hook, and rewriting the encoded string afterwards would cost a scan of every body and break on strings that look like numbers. So the flag covers every float in a response, which is in practice a `Decimal` value or a float a hook or meta entry returns. OpenAPI documents keep their encoding, since a float bound such as `exclusiveMinimum: 0.0` means the same as `0` and changing it would move their bytes for nothing.

ADR 0157 supersedes the last sentence: generated documents now keep the fraction too.
