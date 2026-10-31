# Core owns the entity's id encoding; the integration owns id lookups

An `Id` field may carry an `IdEncoderInterface` so the wire id differs from the stored key (a binary UUID, a hashed integer). Core encodes on every serialize and decodes a client-generated id on create, because only there does the id flow as a typed value on the domain object. Decoding a route `{id}` before a lookup and decoding linkage ids in relationship writes stay in the integration's data layer, so its provider/persister SPI stays wire-id everywhere and a provider without an encoder needs no change.
