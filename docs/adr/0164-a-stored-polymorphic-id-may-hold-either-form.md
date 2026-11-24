# A stored polymorphic id may hold either form

A polymorphic relation's id column often holds the ids of several kinds of target in one text
column, where the natural value is the wire id, while a write through core stores the storage
key. Reads and filters accept both, recognising a wire id by decoding it and encoding it back,
so such a column works without a migration and writes keep storing the storage key.
