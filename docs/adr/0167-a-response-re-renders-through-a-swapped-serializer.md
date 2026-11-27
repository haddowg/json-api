# A response re-renders through a swapped serializer

An adapter that decorates a type's serializer, to rebuild a relation against another
resolver, could do it only for responses it built itself, so a response a custom action
returned rendered without the decoration. `withSerializer()` on `DataResponse`, `RelatedResponse`,
`IdentifierResponse` and a job-resource `AcceptedResponse` swaps the serializer that renders the resources and keeps every other piece of state, and `RendersRelationsTrait::relationshipCallable()`
rebuilds one relation's callable with its `notSparseField()` behaviour, so the decorator never
touches the internal marker. We didn't add a resolver override to the response, because
included resources take their serializer from the relationships the primary serializer
builds, never from the server's resolver, so the override would have changed nothing a
decorator can't already reach.
