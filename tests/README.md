# Tests

The test suite mirrors `src/` so that each source file can be paired
one-to-one with its test file.

## Spec-section group convention

Tests that assert a JSON:API 1.1 specification requirement are tagged with a
PHPUnit group named `spec:<section>`, where `<section>` is the spec's anchor
name. This gives spec traceability without maintaining a separate test layer:
you can run every test that covers, say, document structure with a single
`--group` filter.

Use the PHPUnit `#[Group]` attribute (attributes only — no annotations):

```php
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentStructureTest extends TestCase
{
    #[Test]
    #[Group('spec:document-structure')]
    public function aDocumentMustContainAtLeastOneTopLevelMember(): void
    {
        // ...
    }
}
```

Run a single spec section:

```bash
vendor/bin/phpunit --group spec:document-structure
```

### Known spec anchors

`spec:document-structure`, `spec:fetching-data`, `spec:fetching-resources`,
`spec:fetching-relationships`, `spec:inclusion-of-related-resources`,
`spec:sparse-fieldsets`, `spec:sorting`, `spec:pagination`, `spec:filtering`,
`spec:crud`, `spec:errors`, `spec:query-parameters`, `spec:content-negotiation`,
`spec:extensions-and-profiles`.

(The list grows as the suite grows; the canonical compliance tracker is
`docs/spec-compliance.md`.)
