# A hand-authored, immutable OpenAPI 3.1 value-object model

The OpenAPI document is built from core's own `final readonly` value objects, one per OAS 3.1 object, reusing the JSON Schema `Schema` node for every schema slot. The available libraries were OAS 3.0-only and abandoned, or magic-property models that fight PHPStan level 9 and lag on 2020-12 fidelity. Emitted documents are validated against the official OAS 3.1 meta-schema in the test suite.
