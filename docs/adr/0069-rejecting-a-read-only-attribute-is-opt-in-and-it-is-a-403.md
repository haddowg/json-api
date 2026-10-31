# Rejecting a read-only attribute is opt-in, and it is a 403

JSON:API lets a server ignore or reject a read-only member in a write; core ignores by default and a server may opt in to rejecting. A rejection is `403 ATTRIBUTE_WRITE_PROHIBITED` with a `/data/attributes/<name>` pointer, matching core's other prohibited-write `403`s: the body is well formed and the client is asking for something the server will not do, which is neither a `422` invalid value nor a `400` malformed document.
