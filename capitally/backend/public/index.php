<?php
declare(strict_types=1);

/** API front controller. All requests under /api/ are routed here. */

require dirname(__DIR__) . '/src/bootstrap.php';

use CapiTally\Http\Request;
use CapiTally\Http\Response;
use CapiTally\Http\Session;
use CapiTally\Support\AppException;
use CapiTally\Support\Logger;

try {
    Session::start();
    $request = Request::fromGlobals();
    $router  = require dirname(__DIR__) . '/src/Http/routes.php';
    Response::ok($router->dispatch($request));
} catch (AppException $e) {
    Response::error($e->httpStatus(), $e->errorCode(), $e->getMessage(), $e->details());
} catch (Throwable $e) {
    Logger::error('Unhandled error', ['type' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    Response::error(500, 'SERVER_ERROR', 'Something went wrong. Please try again.');
}
