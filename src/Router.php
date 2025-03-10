<?php

namespace React\Router\Http;

use Closure;
use HttpSoft\Response\HtmlResponse;
use HttpSoft\Response\TextResponse;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response as MessageResponse;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;
use ReflectionMethod;
use RingCentral\Psr7\Response;
use React\Router\Http\Exceptions\InvalidResponse;
use React\Router\Http\Exceptions\InvalidRoute;

use function React\Promise\resolve;

class Router {
    protected array $beforeMiddleware = [];
    protected array $afterMiddleware = [];
    protected array $routes = [
        Methods::GET->value => [],
        Methods::POST->value => [],
        Methods::PATCH->value => [],
        Methods::DELETE->value => [],
        Methods::HEAD->value => [],
        Methods::OPTIONS->value => [],
        Methods::PUT->value => []
    ];
    protected array $E404Handlers = [];
    protected array $E500Handlers = [];
    protected string $baseRoute = "";
    protected HttpServer $http;

    /**
     * @param SocketServer $socket
     */
    public function __construct(protected SocketServer $socket) {
    }

    public function setBaseRoute(string $baseRoute): self
    {
        $this->baseRoute = $baseRoute;
        return $this;
    }
    
    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function get(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::GET], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function post(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::POST], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function head(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::HEAD], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function options(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::OPTIONS], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function patch(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::PATCH], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function delete(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::DELETE], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function put(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::PUT], $pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function all(string $pattern, array|Closure $handler): self
    {
        return $this->map([Methods::ALL] ,$pattern, $handler);
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @throws InvalidArgumentException 
     * @return self
     */
    public function map404(string $pattern, array|Closure $handler): self
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        $this->E404Handlers[] = [
            "pattern" => $pattern,
            "fn" => $handler
        ];

        return $this;
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @throws InvalidArgumentException 
     * @return self
     */
    public function map500(string $pattern, array|Closure $handler): self
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        $this->E500Handlers[] = [
            "pattern" => $pattern,
            "fn" => $handler
        ];

        return $this;
    }

    /**
     * @param array $methods
     * @param string $pattern
     * @param array|Closure $handler
     * @throws InvalidArgumentException 
     * @return self
     */
    public function map(array $methods, string $pattern, array|Closure $handler): self
    {
        $pattern = $this->baseRoute . '/' . trim($pattern, '/');
        $pattern = $this->baseRoute ? rtrim($pattern, '/') : $pattern;

        if (in_array(Methods::ALL, $methods)) {
            $methods = [
                Methods::DELETE,
                Methods::GET,
                Methods::HEAD,
                Methods::OPTIONS,
                Methods::PATCH,
                Methods::POST,
                Methods::PUT
            ];
        }

        foreach ($methods as $method) {
            if (!$method instanceof Methods) {
                throw new InvalidArgumentException("The methods array must only contain instances of \Router\Http\Methods");
            }

            $this->routes[$method->value][] = [
                "pattern" => $pattern,
                "fn" => $handler
            ];
        }

        return $this;
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function beforeMiddleware(string $pattern, array|Closure $handler): self
    {
        $this->beforeMiddleware[] = [
            "pattern" => $pattern,
            "fn" => $handler
        ];

        return $this;
    }

    /**
     * @param string $pattern
     * @param array|Closure $handler
     * @return Router
     */
    public function afterMiddleware(string $pattern, array|Closure $handler): self
    {
        $this->afterMiddleware[] = [
            "pattern" => $pattern,
            "fn" => $handler
        ];

        return $this;
    }

    /**
     * @return void
     */
    public function createHttpServer() {
        if (isset($this->http)) {
            return;
        }

        $this->http = new HttpServer(function (ServerRequestInterface $request) {
            try {
                return $this->handle($request);
            } catch (\Throwable $e) {
                echo "{$e->getMessage()}\n{$e->getTraceAsString()}";

                $_500handler = $this->getMatchingRoutes($request, $this->E500Handlers, true)[0] ?? null;

                if (is_null($_500handler)) {
                    return new TextResponse("An internal server error has occurred", TextResponse::STATUS_INTERNAL_SERVER_ERROR);
                }

                return $this->invoke($request, (new Response), null, $_500handler);
            }
        });
    }

    public function listen() {
        if (!isset($this->http)) {
            $this->createHttpServer();
        }

        $this->http->listen($this->socket);
    }

    public function getHttpServer(): ?HttpServer
    {
        if (!isset($this->http)) {
            $this->createHttpServer();
        }

        return $this->http;
    }

    public function getSocketServer(): ?SocketServer
    {
        return $this->socket;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface|null $response
     * @param Closure|null $next
     * @param array $route
     * @throws LogicException
     * @throws InvalidResponse
     * @return PromiseInterface
     */
    protected function invoke(ServerRequestInterface $request, ?ResponseInterface $response, ?Closure $next, array $route, mixed ...$extra): PromiseInterface
    {
        if (is_null($response) && !is_null($next)) {
            $params = [$request, $next];
        } else if (!is_null($response) && is_null($next)) {
            $params = [$request, $response];
        } else {
            $params = [$request, $response, $next];
        }

        $params = array_merge($params, $route["params"], $extra);

        if (is_callable($route["fn"])) {
            $return = call_user_func($route["fn"], ...$params);
        } else if (is_array($route["fn"])) {
            $method = new ReflectionMethod($route["fn"][0], $route["fn"][1]);
            
            if (!$method->isStatic()) {
                throw new LogicException("You controller must be a static method");
            }

            $return = $method->invoke(null, ...$params);
        }

        if ($return instanceof PromiseInterface) {
            return $return;
        }

        if ($return instanceof ResponseInterface) {
            return resolve($return);
        } else {
            throw new InvalidResponse;
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @throws InvalidRoute 
     * @return PromiseInterface
     */
    protected function handle(ServerRequestInterface $request): PromiseInterface
    {
        $beforeMiddleware = $this->getMatchingRoutes($request, $this->beforeMiddleware, true)[0] ?? null;
        $targetRoute = $this->getMatchingRoutes($request, $this->routes[$request->getMethod()], true)[0] ?? null;
        $afterMiddleware = $this->getMatchingRoutes($request, $this->afterMiddleware, true)[0] ?? null;

        if (is_null($targetRoute)) {
            $_404handler = $this->getMatchingRoutes($request, $this->E404Handlers, true)[0] ?? null;

            if (is_null($_404handler)) {
                throw new InvalidRoute($request->getRequestTarget());
            }

            return $this->invoke($request, new MessageResponse, null, $_404handler);
        }

        $next = function (ResponseInterface $response, mixed ...$extra) use ($request, $targetRoute, $afterMiddleware) {
            $next = function ($response, mixed ...$extra) use ($afterMiddleware, $request) {
                return $this->invoke($request, $response, null, $afterMiddleware, ...$extra);
            };

            return $this->invoke($request, $response, is_null($afterMiddleware) ? null : $next, $targetRoute, ...$extra);
        };
        
        if ($beforeMiddleware !== null) {
            return $this->invoke($request, null, $next, $beforeMiddleware);
        } else {
            return $next(new MessageResponse);
        }
    }

    /**
     * @param string $pattern
     * @param string $uri
     * @param array|null $matches
     * @return bool
     */
    protected function patternMatches(string $pattern, string $uri, array|null &$matches): bool
    {
      $pattern = preg_replace('/\/{(.*?)}/', '/(.*?)', $pattern);

      return (bool)preg_match_all('#^' . $pattern . '$#', $uri, $matches, PREG_OFFSET_CAPTURE);
    }
    
    /**
     * @param ServerRequest $request
     * @param array $routes
     * @return array
     */
    protected function getMatchingRoutes(ServerRequest $request, array $routes, bool $findOne = false): array
    {
        $uri = explode("?", $request->getRequestTarget())[0];

        $matched = [];

        foreach ($routes as $route) {
            $is_match = $this->patternMatches($route['pattern'], $uri, $matches);

            if ($is_match) {
                $matches = array_slice($matches, 1);

                $params = array_map(function ($match, $index) use ($matches) {
                    if (isset($matches[$index + 1]) && isset($matches[$index + 1][0]) && is_array($matches[$index + 1][0])) {
                        if ($matches[$index + 1][0][1] > -1) {
                            return trim(substr($match[0][0], 0, $matches[$index + 1][0][1] - $match[0][1]), '/');
                        }
                    }

                    return isset($match[0][0]) && $match[0][1] != -1 ? trim($match[0][0], '/') : null;
                }, $matches, array_keys($matches));

                $route["params"] = $params;
                $matched[] = $route;

                if ($findOne) {
                    break;
                }
            }
        }

        return $matched;
    }
}
