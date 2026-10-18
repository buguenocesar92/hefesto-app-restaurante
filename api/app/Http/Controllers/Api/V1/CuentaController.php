<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoComanda;
use App\Enums\EstadoMesa;
use App\Enums\FormaPago;
use App\Http\Controllers\Controller;
use App\Http\Requests\CobrarCuentaRequest;
use App\Http\Requests\DividirCuentaRequest;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Services\Comandas\ServicioComandas;
use App\Services\Cuentas\ServicioCuentas;
use Illuminate\Support\Facades\DB;

/**
 * Cuentas, division y cobro.
 *
 * LO QUE ESTA APLICACION NO HACE, Y POR QUE
 * ------------------------------------------
 * No emite boleta, no anula una venta cobrada y no sincroniza precios con el
 * punto de venta. Esas operaciones ocurren en el POS que el restaurante ya
 * tiene, con su propia autorizacion y su propia bitacora. Inventar aqui una
 * "anulacion de comanda cobrada" seria simular un documento tributario que
 * nadie emitio.
 *
 * Lo que si hace es lo que el salon necesita para dividir la cuenta: cuantas
 * partes, quien pago, cuanto y con que forma.
 */
class CuentaController extends Controller
{
    public function __construct(
        private readonly ServicioCuentas $servicioCuentas,
        private readonly ServicioComandas $servicioComandas,
    ) {}

    public function show(Cuenta $cuenta)
    {
        $this->authorize('ver', $cuenta);

        return response()->json([
            'id' => $cuenta->id,
            'comanda_id' => $cuenta->comanda_id,
            'estado' => $cuenta->estado,
            'subtotal' => $cuenta->subtotal,
            'propina' => $cuenta->propina,
            'total' => $cuenta->total,
            'saldo' => $cuenta->saldo(),
            'divisiones' => $cuenta->divisiones()->orderBy('parte')->get(),
            'pagos' => $cuenta->pagos()->with('recibidoPor')->get(),
        ]);
    }

    /** Abre la cuenta si no existe. Se llama al pedir la cuenta. */
    public function abrir(Comanda $comanda)
    {
        $this->authorize('pedirCuenta', $comanda);

        $cuenta = $this->servicioCuentas->abrirPara($comanda);

        return response()->json(['id' => $cuenta->id, 'total' => $cuenta->total], 201);
    }

    /**
     * Divide la cuenta.
     *
     * Con `montos` vacio reparte en partes iguales, repartiendo el peso
     * sobrante entre las primeras para que la suma sea exacta.
     */
    public function dividir(DividirCuentaRequest $peticion, Cuenta $cuenta)
    {
        $this->authorize('dividir', $cuenta);

        if ($cuenta->estado === 'cobrada') {
            abort(422, 'La cuenta ya fue cobrada y no se puede redividir desde este sistema.');
        }

        DB::transaction(function () use ($peticion, $cuenta): void {
            $partes = $peticion->integer('partes');
            $montos = $peticion->montosPersonalizados();

            $this->servicioCuentas->dividir($cuenta, $partes, $montos, $peticion->integer('propina', 0));
        });

        return response()->json([
            'id' => $cuenta->id,
            'total' => $cuenta->total,
            'divisiones' => $cuenta->divisiones()->orderBy('parte')->get(),
        ]);
    }

    /** Registra el pago de una o varias partes. */
    public function cobrar(CobrarCuentaRequest $peticion, Cuenta $cuenta)
    {
        $this->authorize('cobrar', $cuenta);

        $divisiones = $peticion->divisiones();

        $resultado = DB::transaction(function () use ($peticion, $cuenta, $divisiones): array {
            $cobradas = $this->servicioCuentas->cobrar(
                $cuenta,
                $divisiones,
                $peticion->formaDePago(),
                $peticion->user(),
                $peticion->input('identificacion_pagador'),
            );

            // Cuando no queda saldo, la cuenta queda cobrada y la comanda pasa
            // a cobrada. Es el unico camino por el que una comanda llega a
            // `cobrada` en este sistema.
            if ($cuenta->fresh()->saldo() <= 0) {
                $this->servicioCuentas->cerrarCuenta($cuenta);

                $comanda = $cuenta->comanda;
                $this->servicioComandas->cambiarEstado($comanda, EstadoComanda::Cobrada, $peticion->user());

                $ids = $comanda->grupoMesas?->mesas()->pluck('mesas.id');

                if ($ids !== null && $ids->isNotEmpty()) {
                    Mesa::query()->whereIn('id', $ids)->update(['estado' => EstadoMesa::Libre->value]);
                }
            }

            return ['cobradas' => count($cobradas)];
        });

        return response()->json([
            'id' => $cuenta->id,
            'estado' => $cuenta->fresh()->estado,
            'saldo' => $cuenta->fresh()->saldo(),
            'divisiones_pagadas' => $resultado['cobradas'],
        ]);
    }
}