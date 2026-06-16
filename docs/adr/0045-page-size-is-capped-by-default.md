# Client-controlled page sizes are capped, on by default

The page-size strategies (`PagePaginator`, `OffsetPaginator`, `CursorPaginator`)
now clamp the client-controlled `page[size]`/`page[limit]` to a maximum
(`withMaxPerPage()`, immutable wither), **defaulting to `100` with the cap on
without configuration**, so an over-large `page[size]=1000000` is clamped to the
cap and returns `200` rather than being silently honoured. We add this because an
uncapped client size is a denial-of-service vector — a single request could force
a store to fetch a million rows — and the obvious alternative, a `400` for
out-of-range sizes, would break the library's deliberate clamp-don't-`400`
pagination stance (every other garbage `page[…]` value is normalised, never
rejected), so clamping to a ceiling is the consistent and safe-by-default choice.
The cap is applied to the **resolved** size in one shared derivation, so the fetch
window the store loads and the rendered `meta.page` size always agree; it only
clamps *down* (never raising a smaller request) and leaves an absent `page[size]`
on its configured default untouched as long as that default sits at or below the
cap. `withMaxPerPage(0)` disables the cap for the rare endpoint that genuinely
wants unlimited sizes. `FixedPagePaginator` carries no cap because its size is
server-fixed, never client-controlled.
