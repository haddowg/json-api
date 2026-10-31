# Client-controlled page sizes are clamped to a cap that is on by default

`page[size]`/`page[limit]` is clamped to a maximum (100 unless configured, `withMaxPerPage(0)` disables it) rather than honoured or rejected, because an uncapped size is a denial-of-service vector and a `400` would break the library's stance that a bad `page[…]` value is normalised, never rejected. The cap applies to the resolved size in one place, so the fetched window and the rendered `meta.page` agree.

## Consequences

The projected page schema states the cap as a plain `maximum` and the defaults as `default`, without advertising the clamping: tolerance is not part of the contract. A default configured above the cap projects as the cap, since that is what an absent key is served.
