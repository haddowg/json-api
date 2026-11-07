# @haddowg/json-api-witness

The OpenAPI 3.1 documents [haddowg/json-api](https://github.com/haddowg/json-api) projects
for its contract witness server, published with each release so nothing has to commit a
copy:

- `contract-witness.json`: the witness server projected whole.
- `contract-witness-baseline.json`: the same server without the structures a scoped
  generator-contract bump covers.

The package version is the json-api release that projected them. A pull request publishes
`0.0.0-pr.<number>.<sha>` under the dist-tag `pr-<number>`, deleted when the pull request
closes.

Install it from GitHub Packages, with the scope mapped in `.npmrc` and a token that can read
packages:

```
@haddowg:registry=https://npm.pkg.github.com
```
