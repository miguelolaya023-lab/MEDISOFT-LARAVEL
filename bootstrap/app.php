<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventClinicalResponseCaching;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        $middleware->prependToPriorityList(EncryptCookies::class, PreventClinicalResponseCaching::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['motivo_consulta', 'diagnostico_principal', 'observacion_final', 'descripcion_tratamiento']);
        $exceptions->render(function (ValidationException $exception, Request $request): ?Response {
            if ($request->routeIs('atenciones.clinica.tratamientos.store', 'atenciones.clinica.tratamientos.update')) {
                $request->session()->forget('_old_input');
                if ($request->expectsJson()) {
                    return null;
                }

                $destino = $exception->redirectTo ?? route('atenciones.clinica.tratamientos.index', $request->route('atencion'));

                return redirect($destino)->withErrors($exception->errors(), $exception->errorBag);
            }

            if (! $request->routeIs('atenciones.clinica.formulas.store') || $request->expectsJson()) {
                return null;
            }

            return redirect()->route('atenciones.clinica.formulas.index', $request->route('atencion'))
                ->withErrors($exception->errors())->header('Cache-Control', 'no-store, private');
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->routeIs('pacientes.historia.show', 'pacientes.historial.*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });

        $esFalloSqlClinico = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('antecedentes.index', 'antecedentes.store', 'antecedentes.edit', 'antecedentes.update', 'atenciones.clinica.*', 'pacientes.historia.show', 'pacientes.historial.*')) {
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
            $modulo = match (true) {
                request()->routeIs('pacientes.historial.*') => 'RF-013',
                request()->routeIs('pacientes.historia.show') => 'RF-059',
                request()->routeIs('atenciones.clinica.*') => 'AtencionMedica',
                default => 'RF-058',
            };
            Log::error('Error de base de datos en '.$modulo.'.', [
                'modulo' => $modulo, 'operacion' => request()->route()->getName(),
            ]);

            return false;
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlClinico): ?Response {
            if (! $esFalloSqlClinico($exception, $request)) {
                return null;
            }
            $mensaje = match (true) {
                $request->routeIs('pacientes.historial.*') => 'No fue posible consultar el historial de consultas. Intente nuevamente.',
                $request->routeIs('pacientes.historia.show') => 'No fue posible consultar la historia clínica. Intente nuevamente.',
                $request->routeIs('atenciones.clinica.*') => 'No fue posible completar la operación de atención médica. Intente nuevamente.',
                default => 'No fue posible completar la operación de antecedentes clínicos. Intente nuevamente.',
            };
            $response = $request->expectsJson()
                ? response()->json(['message' => $mensaje], 503)
                : response($mensaje, 503)->header('Content-Type', 'text/plain; charset=UTF-8');

            return $response->header('Cache-Control', 'no-store, private');
        });

        $esFalloSqlEps = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('eps.index', 'eps.search', 'eps.create', 'eps.store', 'eps.edit', 'eps.update', 'eps.show')) {
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
        $exceptions->report(function (Throwable $exception) use ($esFalloSqlEps): ?bool {
            if (! $esFalloSqlEps($exception, request())) {
                return null;
            }
            Log::error('Error de base de datos en catálogo EPS.', ['modulo' => 'EPS', 'operacion' => request()->route()->getName()]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlEps): ?Response {
            if (! $esFalloSqlEps($exception, $request)) {
                return null;
            }
            $mensaje = 'No fue posible completar la operación de EPS. Intente nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $mensaje], 503) : response($mensaje, 503))
                ->header('Cache-Control', 'no-store, private');
        });

        $esFalloSqlRemision = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('remisiones.index', 'remisiones.search', 'remisiones.create', 'remisiones.store', 'remisiones.edit', 'remisiones.update', 'remisiones.show', 'remisiones.state', 'remisiones.changeState')) {
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
        $exceptions->report(function (Throwable $exception) use ($esFalloSqlRemision): ?bool {
            if (! $esFalloSqlRemision($exception, request())) {
                return null;
            }
            Log::error('Error de base de datos en remisiones EPS.', ['modulo' => 'RemisionesEPS', 'operacion' => request()->route()->getName()]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlRemision): ?Response {
            if (! $esFalloSqlRemision($exception, $request)) {
                return null;
            }
            $message = 'No fue posible completar la operación de remisiones. Intente nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $message], 503) : response($message, 503))->header('Cache-Control', 'no-store, private');
        });
        $esFalloSqlDisponibilidad = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('disponibilidad.index', 'disponibilidad.store', 'disponibilidad.edit', 'disponibilidad.update')) {
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
        $exceptions->report(function (Throwable $exception) use ($esFalloSqlDisponibilidad): ?bool {
            if (! $esFalloSqlDisponibilidad($exception, request())) {
                return null;
            }
            Log::error('Error de base de datos en disponibilidad médica.', ['modulo' => 'RF-060', 'operacion' => request()->route()->getName()]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlDisponibilidad): ?Response {
            if (! $esFalloSqlDisponibilidad($exception, $request)) {
                return null;
            }
            $mensaje = 'No fue posible completar la operación de disponibilidad médica. Intente nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $mensaje], 503) : response($mensaje, 503))->header('Cache-Control', 'no-store, private');
        });
        $esFalloSqlCita = static function (Throwable $exception, Request $request): bool {
            if (! $request->routeIs('citas.create', 'citas.store', 'citas.confirmation', 'citas.eps.*')) {
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
        $exceptions->report(function (Throwable $exception) use ($esFalloSqlCita): ?bool {
            if (! $esFalloSqlCita($exception, request())) {
                return null;
            }
            $eps = request()->routeIs('citas.eps.*');
            Log::error($eps ? 'Error de base de datos en citas EPS.' : 'Error de base de datos en citas particulares.', [
                'modulo' => $eps ? 'RF-025' : 'RF-024',
                'operacion' => request()->route()->getName(),
            ]);

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) use ($esFalloSqlCita): ?Response {
            if (! $esFalloSqlCita($exception, $request)) {
                return null;
            }
            $mensaje = $request->routeIs('citas.eps.*')
                ? 'No fue posible completar la operación de citas EPS. Intente nuevamente.'
                : 'No fue posible completar la operación de citas particulares. Intente nuevamente.';

            return ($request->expectsJson() ? response()->json(['message' => $mensaje], 503) : response($mensaje, 503))->header('Cache-Control', 'no-store, private');
        });
    })->create();
