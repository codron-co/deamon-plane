<?php

namespace App\Mcp;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Runs a panel route in-process for an MCP tool call, so an agent goes through
 * exactly what an operator's click does: the same FormRequest validation,
 * policies, services, audit rows and background jobs. Nothing is reimplemented.
 *
 * Route middleware (session, CSRF, IP allowlist) is not run: the MCP endpoint
 * authenticates the token itself. The request asks for JSON, so controllers that
 * offer it queue a background job; redirects are read back like
 * ConvertOpsAjaxRedirect reads them for the panel's fetch calls.
 */
class OpsRouteInvoker
{
    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  route parameters, already resolved (models or scalars)
     * @param  array<string, mixed>  $input  request body
     * @return array{ok: bool, http_status: int, type: string, message: string, data?: array<string, mixed>, errors?: array<string, list<string>>, flash?: array<string, mixed>}
     */
    public function invoke(string $routeName, array $parameters, array $input, User $user, ?string $ip): array
    {
        $registered = $this->router->getRoutes()->getByName($routeName);
        if (! $registered instanceof Route) {
            throw new \LogicException("Route [{$routeName}] is not registered.");
        }

        $route = clone $registered;
        $method = collect($route->methods())->first(fn (string $verb): bool => $verb !== 'HEAD') ?? 'GET';
        $request = Request::create(
            route($routeName, $parameters, false),
            $method,
            server: [
                'REMOTE_ADDR' => $ip ?: '127.0.0.1',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($input === [] ? new \stdClass : $input, JSON_THROW_ON_ERROR),
        );

        $session = new Store('plane_mcp', new ArraySessionHandler(1));
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        $route->bind($request);
        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn () => $route);

        $previous = $this->app->make('request');
        $redirector = $this->app->make(Redirector::class);
        $this->swapRequest($request);
        $redirector->setSession($session);
        Auth::setUser($user);

        try {
            $response = $route->run();
            $response = $response instanceof Response ? $response : response()->json($response);

            return $this->read($response, $session);
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'http_status' => 422,
                'type' => 'validation_error',
                'message' => (string) collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                'errors' => $exception->errors(),
            ];
        } catch (AuthorizationException $exception) {
            return [
                'ok' => false,
                'http_status' => 403,
                'type' => 'forbidden',
                'message' => 'Your Plane role does not allow this ('.($user->opsRole()->value ?? 'no role').').',
            ];
        } catch (ModelNotFoundException) {
            return ['ok' => false, 'http_status' => 404, 'type' => 'not_found', 'message' => 'Not found.'];
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            return [
                'ok' => false,
                'http_status' => $status,
                'type' => $status === 404 ? 'not_found' : ($status === 403 ? 'forbidden' : 'error'),
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Error'),
            ];
        } finally {
            $this->swapRequest($previous);
            if ($this->app->bound('session.store')) {
                $redirector->setSession($this->app->make('session.store'));
            }
        }
    }

    /**
     * @return array{ok: bool, http_status: int, type: string, message: string, data?: array<string, mixed>, flash?: array<string, mixed>}
     */
    private function read(Response $response, Store $session): array
    {
        $status = $response->getStatusCode();

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            $data = is_array($data) ? $data : ['value' => $data];
            $ok = $status < 400 && ($data['ok'] ?? true) !== false;

            return [
                'ok' => $ok,
                'http_status' => $status,
                'type' => is_string($data['type'] ?? null) ? $data['type'] : ($ok ? 'status' : 'error'),
                'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
                'data' => array_diff_key($data, array_flip(['ok', 'type', 'message'])),
            ];
        }

        if ($response instanceof RedirectResponse) {
            $error = $session->pull('error');
            $bag = $session->pull('errors');
            if ((! is_string($error) || $error === '') && $bag instanceof ViewErrorBag && $bag->any()) {
                $error = (string) $bag->first();
            }
            $warning = $session->pull('warning');
            $message = $session->pull('status');
            $hasError = is_string($error) && $error !== '';
            $hasWarning = is_string($warning) && $warning !== '';

            $result = [
                'ok' => ! $hasError,
                'http_status' => $status,
                'type' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'status'),
                'message' => $hasError ? $error : ($hasWarning ? $warning : (is_string($message) ? $message : '')),
            ];

            // One-time values the panel shows once (a generated admin password).
            $flash = collect((array) $session->get('_flash.new', []))
                ->reject(fn ($key): bool => in_array($key, ['error', 'errors', 'warning', 'status', '_old_input'], true))
                ->mapWithKeys(fn ($key): array => [$key => $session->get($key)])
                ->all();
            if ($flash !== []) {
                $result['flash'] = $flash;
            }

            return $result;
        }

        return [
            'ok' => $status < 400,
            'http_status' => $status,
            'type' => $status < 400 ? 'status' : 'error',
            'message' => '',
        ];
    }

    private function swapRequest(Request $request): void
    {
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }
}
