# The contract witness is published, not committed

The contract witness churned history on every projector change and drifted from the copies the code generators committed, so it is exported in CI instead and published as `@haddowg/json-api-witness` on GitHub Packages: each release under its version, and each pull request from this repository as `0.0.0-pr.<n>.<sha>`, deleted when it closes, so a generator can test against unreleased output. The contract guard compares against the last release's published witness, falling back to the one committed at a tag from before the package. What committing gave for free, a reviewable diff, comes from a CI job that diffs the pull request's witness against its base's and fails until a `generated-output` label says someone read it.

## Considered options

- npmjs: anonymous installs, but deleting a version is restricted after 72 hours, and publishing would need a long-lived token or trusted publishing set up per package. GitHub Packages publishes and deletes with the workflow's own token; the cost is that installing needs a token even for a public package.
