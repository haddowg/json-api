# A class-level `@internal` PHPDoc is a binding non-public boundary

The package marks a type as non-public in two ways: by placing it in an `Internal\`
sub-namespace, or by a class-level `@internal` PHPDoc on a type that otherwise lives
in a public namespace (26 such types — the transformer pipeline, the concrete
`Schema\Document\*`/`Data\*` classes and their interfaces, `Request\MediaType`,
`Pagination\QueryParam`, `Server\Entry`). Both mean the same thing and are **equally
binding**: the type is implementation detail, exempt from the 1.0 semver freeze, and
may change in any release. The 1.0-readiness rule "no `@internal` type leaks through a
public method signature" holds across both.

We **ratify the `@internal` PHPDoc as co-equal to the namespace**, rather than
relocating the 26 types under `Internal\`. The PHPDoc tag is the established PHP
ecosystem convention for exactly this (Symfony, Doctrine, PHPUnit) and is tool-enforced
— PHPStan and PhpStorm warn when code outside the declaring package uses an
`@internal` symbol — so it is a real boundary, not a comment. Relocating ~26 types on
the eve of the freeze would be high-churn, bug-prone, and no more binding than the tag.

A member-level `@internal` (e.g. `Error::transform()`, `ErrorSource::transform()`)
marks a single method/property internal on an otherwise **public** class — those
classes (and any other type without a class-level tag) remain public, frozen surface.
Going forward: prefer the `Internal\` namespace when a whole subtree is internal, and a
class-level `@internal` when a single implementation type sits among public siblings.
