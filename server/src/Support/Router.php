<?php

declare(strict_types=1);

namespace LockSend\Support;

final class Router
{
    /** @var list<array{0:string,1:string,2:callable}> */
    private array $routes = [];

    /** Pattern placeholders: {id} = 22-char base64url, {n} = decimal integer. */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = strtr(preg_quote($pattern, '#'), [
            '\{id\}' => '(?P<id>[A-Za-z0-9_-]{22})',
            '\{n\}' => '(?P<n>[0-9]{1,10})',
        ]);
        $this->routes[] = [$method, '#^' . $regex . '$#D', $handler];
    }

    public function dispatch(Request $req): Response
    {
        $methodMismatch = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $req->path, $m) !== 1) {
                continue;
            }
            if ($method !== $req->method) {
                $methodMismatch = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req, $params);
        }
        throw new HttpException($methodMismatch ? 405 : 404, $methodMismatch ? 'method_not_allowed' : 'not_found');
    }
}
