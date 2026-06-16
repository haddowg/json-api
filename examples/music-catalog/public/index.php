<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

use function haddowg\JsonApi\Examples\MusicCatalog\bootstrap;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

// The same fully-wired, freshly-seeded Server the tests boot — the single wiring
// source of truth lives in examples/music-catalog/src/bootstrap.php. Each request
// gets a fresh in-memory store, so writes do not persist across requests (this is
// a demo of the library, not a database-backed app).
$server = bootstrap();

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$response = $server->handle($request);

\http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        \header(\sprintf('%s: %s', $name, $value), false);
    }
}
echo $response->getBody();
