# Validation and query metadata in core, execution in adapters

Constraints, filters and sorts are inert value-object metadata with no `apply()` or `validate()`: constraints are consumed by the JSON Schema compiler and framework validators, and filters and sorts by adapter-provided handlers whose query argument is a templated `mixed`. A reader expecting `FilterInterface::apply($query)` will not find it; the split is what keeps core persistence- and framework-agnostic. Core's in-memory array handlers are reference implementations and the conformance witness, never a production query layer.
