# Lifecycle decision points are public, stateless seams usable without the middleware

The throwable-to-500 mapping (`Schema\Error\InternalServerError::for()`) and the method-by-target choice of operation (`Operation\OperationFactory::fromRequest()`) are public, stateless seams that the PSR-15 middleware and adapter merely delegate to. A framework integration that drives the lifecycle without core's middleware (Symfony kernel listeners calling `Server::dispatch()`) can then render errors and build operations identically to core instead of re-implementing them and drifting.
