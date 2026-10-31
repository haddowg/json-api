# Action operation ids are unique, and an action declares its safety as `x-safety`

Consumers key on operation ids and some flatten them into tool names, so action declarations are rejected when the same path appears at both scopes, when a path flattens to a word the type's CRUD ids use, or when two action ids differ only in case or punctuation; a multi-method action gets one id per method. Whether an action reads or writes cannot be inferred from its method (a `POST` lookup reads like a write), so an action may declare read-only, destructive and idempotent flags, projected as one `x-safety` object.

## Consequences

A relation's name is not reserved: an action may share it, since the `action.` and `fetchRelated.` prefixes already differ.
