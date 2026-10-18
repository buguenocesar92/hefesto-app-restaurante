<?php

declare(strict_types=1);

use App\Http\Middleware\ExigirRol;
use App\Http\Middleware\ExigirTurnoActivo;
use App\Http\Middleware\ResolverRestaurante;
use App\Services\Tenancy\ContextoRestaurante;
use DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/*
|--------------------------------------------------------------------------
| Arranque de la aplicacion de Salon y Cocina de Hefesto
|--------------------------------------------------------------------------
|
| Los tres middleware que importan para el comportamiento del negocio son:
|
|   - ResolverRestaurante: fija el tenant (el restaurante) del usuario
|     autenticado dentro de una transaccion. Es lo que activa la politica de
|     Row Level Security de PostgreSQL.
|   - ExigirTurnoActivo: impide abrir comandas, agregar items y cobrar fuera
|     del turno. La pantalla de cocina SI puede leerse fuera de turno, porque
|     el cierre del local no cancela lo que ya esta en preparacion.
|   - ExigirRol: separa el area de administracion de la sala.
|
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/salud-interno',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AuthenticateSession::class,
        ]);

        $middleware->alias([
            'restaurante' => ResolverRestaurante::class,
            'turno' => ExigirTurnoActivo::class,
            'rol' => ExigirRol::class,
        ]);

        // La API usa sesion con cookie, no token. Por eso las escrituras
        // pasan por CSRF igual que las del navegador.
        $middleware->api(prepend: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $middleware->trustProxies(at: [
            Request::HEADER_X_FORWARDED_FOR,
            Request::HEADER_X_FORWARDED_HOST,
        ]);
    })
    ->withExceptions(function (Exceptions $excepciones): void {
        // Un DomainException es una regla de negocio que se opone (por
        // ejemplo, anular una comanda cobrada). No es un fallo del sistema:
        // va como 422 con el mensaje que escribio quien definio la regla, que
        // esta en espanol y explica que hacer.
        $excepciones->render(function (DomainException $error, Request $peticion) {
            if ($peticion->expectsJson()) {
                return response()->json(['mensaje' => $error->getMessage()], 422);
            }

            return null;
        });

        $excepciones->render(function (ValidationException $error, Request $peticion) {
            return response()->json([
                'mensaje' => 'Revisa los datos enviados.',
                'errores' => $error->errors(),
            ], 422);
        });

        $excepciones->render(function (Throwable $error, Request $peticion) {
            $estado = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;

            if ($peticion->expectsJson()) {
                return response()->json([
                    'mensaje' => $estado === 500
                        ? 'Ocurrió un error en el sistema. Hefesto fue notificado.'
                        : $error->getMessage(),
                ], $estado);
            }

            return null;
        });
    })
    ->withBindings([
        ContextoRestaurante::class,
    ], is_singleton: true)
    ->create();