# Operation handlers resolve serializers and hydrators through an interface

`OperationContext::$server` is typed `ResolvingServerInterface` — the render
contract (`ServerInterface`) composed with `SerializerResolverInterface` and a new
`HydratorResolverInterface` (`hydratorFor()` / `hasHydratorFor()`, the hydrator-side
mirror of the existing serializer resolver) — so a custom `OperationHandler` resolves
the serializer or hydrator for a type through an interface rather than downcasting to
the final `Server`. Before this, `hydratorFor()` lived only on the concrete `Server`
(no interface exposed it), forcing every non-trivial handler — including the reference
Symfony bundle — to `assert($server instanceof Server)`; freezing that coupling at 1.0
would have made the headline extension point depend on a `final` class.

## Considered options

- **A named `ResolvingServerInterface` composing the three interfaces (chosen)** — one
  stable, self-documenting type a handler programs against and an alternative server
  implements; the cost is one new public interface name.
- **An intersection type on the context property** (`ServerInterface &
  SerializerResolverInterface & HydratorResolverInterface`) — no new name, but the
  three-part type is re-spelled at every use and implement site.
- **Resolver accessor methods on `OperationContext` itself** — keeps `$server`
  render-thin, but moves the resolution surface onto the context and requires it to be
  constructed with the resolvers.

The named interface won for discoverability on the headline extension point. The
concrete `Server` and the registry (`ResourceRegistry`) implement the resolver
interfaces; the render-only `ServerInterface` is unchanged, so the serializer-free
response layer still depends only on it.
