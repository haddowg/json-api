# Filters declare overridable default values; request presence always wins

A collection endpoint often wants a filter applied unless the client says
otherwise — `status` defaulting to `active`, a date window defaulting to the
last 30 days. Declaring the default on the filter value object
(`Where::make('status')->default('active')`, surfaced through the
`HasDefaultValue` capability interface) keeps it where the rest of the filter's
contract already lives: `filters()` remains the single declarative description
of a type's query surface. The semantics are decided once in
`FilterDefaults::apply()` — a default fills only its *absent* key, and a
requested key wins by **presence** (`array_key_exists`), so an explicit empty
or null value still overrides — rather than each adapter re-deciding presence
rules per data layer.

A default is deliberately a *convenience*, never a constraint: anything the
client must not be able to undo (soft-delete exclusion, tenancy) is the data
layer's job, not the filter vocabulary's. The presence-only filters
(`WhereNull`, `WhereHas`, …) therefore don't participate — their requested
presence *is* their semantics, so a "default" would be indistinguishable from
an always-on constraint.
