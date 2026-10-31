# Collection windowing and keyset execution live in core

The storage-agnostic part of fetching a collection window (no-window passthrough, counted page, count-free probe, and the cursor branch) is one `Collection\WindowExecutor`, and the keyset machinery (active sort to keyset columns, staleness check, NULL ordering, token minting) is `Collection\Keyset`. Data layers supply only store-specific closures, so every provider gets identical branch behaviour; the integrations had each carried byte-identical copies, which invited exactly the drift the classes exist to prevent.

## Consequences

Cursor pagination is another `WindowInterface` strategy under the same seam: the keyset follows the client's active sort plus a primary-key tiebreak, a token whose columns no longer match is a `400` stale cursor, and a cursor page is always count-free.
