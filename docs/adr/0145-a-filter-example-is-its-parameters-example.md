# A filter's example is its parameter's example

A filter's `->example(...)` was collected and never projected. It now becomes the
`filter[<key>]` Parameter Object's `example`, not the value schema's, even though a field's
example goes on its schema. A field has nowhere else to put one, but a parameter does, and
OAS 3.1 deprecates the Schema Object's `example` in favour of the parameter's own. The value
is the parameter's whole value (an object for a `deepObject` range, a list for a set
filter), emitted as declared, and the generator reads it through a new opt-in
`ExemplifiedFilter` interface so `DescribedFilter` implementors don't break.
