# Custom actions are a first-class core operation

Non-CRUD endpoints dispatch as a `CustomActionOperation` through the same `Server::dispatch()` and `OperationHandlerInterface::handle()` as CRUD, rather than a separate dispatch path or a verb tunnelled through a CRUD operation, so actions inherit strict query validation, serving hooks and scope enforcement for free. The integration constructs the operation; core's `OperationFactory` stays CRUD-only. A raw-bodied action may skip only the JSON:API request `Content-Type` assertion while still negotiating a JSON:API response.
