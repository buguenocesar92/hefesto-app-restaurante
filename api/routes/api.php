<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\ProductoController;
use App\Http\Controllers\Api\V1\ComandaController;
use App\Http\Controllers\Api\V1\CuentaController;
use App\Http\Controllers\Api\V1\CocinaController;
use App\Http\Controllers\Api\V1\SalonController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API de la aplicacion de restaurante
|--------------------------------------------------------------------------
|
| Convenciones que se respetan en todas las rutas:
|
|  - `auth` primero: sin sesion no hay ni usuario ni contexto de restaurante.
|  - `rol:...` limita el AREA. La autorizacion sobre el recurso concreto la
|    decide la Policy.
|  - `turno` exige un turno abierto SOLO en las rutas que mueven dinero o
|    generan trabajo. La pantalla de cocina se lee sin turno, porque al cerrar
|    el local quedan pedidos en preparacion.
|
*/

Route::middleware(['web', 'auth'])->group(function (): void {

    // ---------------------------------------------------------------------
    // Salud. No exige sesion ni turno: la usa el orquestador para decidir si
    // el contenedor responde.
    // ---------------------------------------------------------------------
    Route::get('/salud-interno', fn () => response()->json([
        'estado' => 'ok',
        'servicio' => 'api-restaurante',
        'hora' => now()->toIso8601String(),
    ]))->name('salud');

    // ---------------------------------------------------------------------
    // Salon. Cualquier persona del turno ve el mapa.
    // ---------------------------------------------------------------------
    Route::middleware('rol:mesero,cajero,cocinero,jefe_cocina,administrador')->group(function (): void {
        Route::get('/salon', [SalonController::class, 'index'])->name('salon');
    });

    // ---------------------------------------------------------------------
    // Comandas.
    // ---------------------------------------------------------------------
    Route::prefix('comandas')->name('comandas.')->group(function (): void {

        Route::get('/', [ComandaController::class, 'index'])->name('index');

        Route::middleware(['rol:mesero,cajero,administrador', 'turno'])->group(function (): void {
            Route::post('/', [ComandaController::class, 'store'])->name('store');
        });

        Route::get('/{comanda}', [ComandaController::class, 'show'])->name('show');
        Route::get('/{comanda}/eventos', [ComandaController::class, 'eventos'])->name('eventos');

        Route::middleware(['rol:mesero,cajero,administrador', 'turno'])->group(function (): void {
            Route::post('/{comanda}/items', [ComandaController::class, 'agregarItem'])->name('items.store');
            Route::post('/{comanda}/enviar-a-cocina', [ComandaController::class, 'enviarACocina'])->name('enviar');
            Route::post('/{comanda}/items/{item}/anular', [ComandaController::class, 'anularItem'])->name('items.anular');
            Route::post('/{comanda}/pedir-cuenta', [ComandaController::class, 'pedirCuenta'])->name('pedir-cuenta');
            Route::post('/{comanda}/reabrir', [ComandaController::class, 'reabrir'])->name('reabrir');
            Route::post('/{comanda}/anular', [ComandaController::class, 'anular'])->name('anular');
        });
    });

    // ---------------------------------------------------------------------
    // Cocina.
    //
    // `index` NO lleva `turno`: al cerrar el local quedan pedidos en el pasillo
    // y alguien tiene que poder marcarlos. `cambiarEstado` tampoco, por la
    // misma razon.
    // ---------------------------------------------------------------------
    Route::prefix('cocina')->name('cocina.')->group(function (): void {
        Route::middleware('rol:cocinero,jefe_cocina,administrador')->group(function (): void {
            Route::get('/panel', [CocinaController::class, 'index'])->name('panel');
            Route::post('/items/{item}/estado', [CocinaController::class, 'cambiarEstado'])->name('estado');
        });
    });

    // ---------------------------------------------------------------------
    // Cuentas. El mesero pide y divide; el cobro lo hace caja.
    // ---------------------------------------------------------------------
    Route::middleware('rol:mesero,cajero,administrador')->group(function (): void {
        Route::get('/cuentas/{cuenta}', [CuentaController::class, 'show'])->name('cuentas.show');

        Route::middleware('turno')->group(function (): void {
            Route::post('/comandas/{comanda}/cuenta', [CuentaController::class, 'abrir'])->name('cuentas.abrir');
            Route::post('/cuentas/{cuenta}/dividir', [CuentaController::class, 'dividir'])->name('cuentas.dividir');
        });
    });

    Route::middleware(['rol:cajero,administrador', 'turno'])->group(function (): void {
        Route::post('/cuentas/{cuenta}/cobrar', [CuentaController::class, 'cobrar'])->name('cuentas.cobrar');
    });

    // ---------------------------------------------------------------------
    // Administracion. Solo administrador en el area, y la Policy revisa de nuevo
    // sobre el recurso.
    // ---------------------------------------------------------------------
    Route::prefix('admin')->name('admin.')->middleware('rol:administrador')->group(function (): void {
        Route::get('/productos', [ProductoController::class, 'index'])->name('productos.index');
        Route::get('/productos/{producto}', [ProductoController::class, 'show'])->name('productos.show');
        Route::post('/productos', [ProductoController::class, 'store'])->name('productos.store');
        Route::patch('/productos/{producto}/precio', [ProductoController::class, 'actualizarPrecio'])->name('productos.precio');
    });
});