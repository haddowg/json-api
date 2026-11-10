# A write hook changes how a value is stored, not what is valid

A `fillUsing()` or `deserializeUsing()` hook on a list or nested object used to own the value's shape, so the write schema left it open and nothing looked inside it, while the same schema still advertised the element's member counts. The published document then promised checks the server never made. A write hook now decides only how the value is stored: the write schema keeps the declared shape, closed or open through `additionalProperties()`, with its counts, and hydration and the adapters' validation walk the value as if no hook were there. This supersedes the write-hook half of the consequence in [ADR 0088](0088-declared-objects-are-closed.md). A read hook still owns the shape it renders, because the server never validates its own output.

## Consequences

An application whose write hook took members its field does not declare now gets them refused. Data with keys only the client knows declares them through `additionalProperties()` or uses an `ArrayHash`.

[ADR 0110](0110-an-extract-hook-supplies-a-value-and-the-declared-shape-renders-it.md) narrows the read side to `serializeUsing()`: an `extractUsing()` value now renders through the declared shape.
