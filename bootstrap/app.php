<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $esFalloSqlClinico = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('antecedentes.index', 'antecedentes.store', 'antecedentes.edit', 'antecedentes.update')) {
                return false;
            }
            do {
                if ($exception instanceof QueryException) {
                    return true;
                }
                $exception = $exception->getPrevious();
            } while ($exception !== null);

            return false;
        };

        $exceptions->report(function (Throwable $exception) use ($esFalloSqlClinico): ?bool {
            if (! $esFalloSqlClinico($exception, request())) {
                return null;
            }
            Log::error('Error de base de datos en RF-058.', [
                'modulo' => 'RF-058', 'operacion' => request()->route()->getName(),
            ]);

            return false;
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlClinico): ?Response {
            if (! $esFalloSqlClinico($exception, $request)) {
                return null;
            }
            $mensaje = 'No fue posible completar la operación de antecedentes clínicos. Intente nuevamente.';
            $response = $request->expectsJson()
                ? response()->json(['message' => $mensaje], 503)
                : response($mensaje, 503)->header('Content-Type', 'text/plain; charset=UTF-8');

            return $response->header('Cache-Control', 'no-store, private');
        });
    })->create();
