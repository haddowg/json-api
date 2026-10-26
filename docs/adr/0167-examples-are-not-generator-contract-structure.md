# Examples are not generator-contract structure

> Amended by ADR 0176: an example's value is still not structure, but whether a media type carries an `example` at all now is, since generators read operation examples.

The contract guard used to count a changed `example` as a structural change, so improving a sample (the shared `LinkObject` example, say) would have forced a contract bump that announces nothing a generator reads. `bin/contract-guard.php` now strips every `example` before comparing, as it already did for `description` and `summary` prose, because a code generator keys on shape and never on sample values. Keys inside maps of names (`properties`, a discriminator `mapping`, `paths`, the `components` sections) are never stripped, so an attribute named `example` or `description` still counts as structure, and `ContractGuardTest` runs the guard against a throwaway release tag to prove a real structural change still fails.
