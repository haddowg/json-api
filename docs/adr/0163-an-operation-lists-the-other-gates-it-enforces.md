# An operation lists the other gates it enforces

A to-many related or relationship read is refused when the caller may not list a type it
relates to, a rule that belongs to another type and decides without a resource. The
catalogue carries such rules as `AdditionalGate`s beside the primary rule rather than folding
them into one expression, because an ability name can't be joined and a consumer needs each
part, and because a refusing gate then answers `Denied` even when the primary rule needs the
resource.
