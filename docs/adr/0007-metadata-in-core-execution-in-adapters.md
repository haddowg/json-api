# Validation and query metadata in core, execution in adapters

Constraints, filters, and sorts are declared in core as inert value-object
*metadata*: `Constraint`, `Filter`, and `Sort` carry no `apply()` or `validate()`
method and the core never executes them. Execution lives elsewhere — constraints
are consumed by the JSON Schema compiler; filters and sorts are executed by
consumer-provided handlers whose query argument is a templated `mixed`, so no data
store or query builder is coupled into core.

A reader expecting `Filter::apply($query)` will not find it — this split is what
keeps the library persistence- and framework-agnostic (see
[ADR 0002](0002-framework-agnostic-on-psr-standards.md)). Core ships small
in-memory array handlers as reference examples only, never as a production query layer.
