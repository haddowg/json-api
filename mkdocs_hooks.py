"""MkDocs build hooks for the published documentation site.

The docs link into the repository with relative paths (``../examples/…``,
``../src/…``) so the snippets resolve on GitHub. Those targets are not part of the
rendered site, so rewrite them to absolute GitHub ``blob`` URLs at build time. The
source Markdown keeps its GitHub-friendly relative links untouched.
"""

import re

_GH_BLOB = "https://github.com/haddowg/json-api/blob/main/"

# A Markdown link ``](../…/<target>)`` into a repository path that lives outside
# docs/ (so it cannot be served): rewrite to a GitHub blob URL. <target> is a known
# top-level directory or file (a trailing path is optional, e.g. ``../tests/``).
_REPO_LINK = re.compile(
    r"\]\((?:\.\./)+((?:examples|src|tests|config|LICENSE|CONTRIBUTING)[^)]*)\)"
)

# Maintainer-facing pages excluded from the published site (the compliance ledger
# and the pre-release readiness checklist): point their in-doc links at the GitHub
# source so references still resolve.
_OFFSITE = re.compile(r"\]\((spec-compliance|release-readiness)\.md([^)]*)\)")


def on_page_markdown(markdown, **kwargs):
    markdown = _REPO_LINK.sub(rf"]({_GH_BLOB}\1)", markdown)
    markdown = _OFFSITE.sub(rf"]({_GH_BLOB}docs/\1.md\2)", markdown)
    return markdown
