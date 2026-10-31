# Operation handlers resolve serializers and hydrators through an interface

`OperationContext::$server` is typed `ResolvingServerInterface` (the render contract plus serializer and hydrator resolvers) so a custom operation handler resolves through an interface instead of downcasting to the `final` `Server`, which would freeze the headline extension point onto a concrete class.

## Considered options

- An intersection type on the property: no new name, but re-spelled at every use and implement site.
- Resolver accessors on `OperationContext`: moves the resolution surface onto the context and forces it to be built with resolvers.
