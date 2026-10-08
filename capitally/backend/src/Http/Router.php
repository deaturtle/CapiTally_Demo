<?php
declare(strict_types=1);

namespace CapiTally\Http;

use CapiTally\Services\AuthService;
use CapiTally\Support\AppException;
use CapiTally\Support\Permissions;

/**
 * Minimal router. Every non-public route requires a valid session, a CSRF
 * token on state-changing methods, and the named ability for the user's role.
 */
final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:callable,ability:?string,public:bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, ?string $ability = null, bool $public = false): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'ability', 'public');
    }

    public function dispatch(Request $request): mixed
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            if (!$route['public']) {
                $request->user = AuthService::requireUser();
                if ($request->method !== 'GET') {
                    AuthService::requireCsrf($request->header('X-CSRF-Token'));
                }
                if ($route['ability'] !== null && !Permissions::can($request->user['role'], $route['ability'])) {
                    throw new AppException('You do not have permission to do that.', 'FORBIDDEN', 403);
                }
            }

            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw new AppException('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
        }
        throw new AppException('Not found.', 'NOT_FOUND', 404);
    }
}
