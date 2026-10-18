<?php

declare(strict_types=1);

namespace App\Services\Cuentas;

use App\Enums\EstadoItemComanda;
use App\Enums\FormaPago;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Cuenta;
use App\Models\CuentaDivision;
use App\Models\Pago;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Servicio de cuentas.
 *
 * El total se calcula sumando todo lo que se pidio y restando lo anulado: un
 * item anulado nunca llego a cobrarse y por eso no puede estar en la cuenta.
 */
class ServicioCuentas
{
    /** Abre (o devuelve) la cuenta de una comanda. */
    public function abrirPara(Comanda $comanda): Cuenta
    {
        $cuenta = $comanda->cuenta()->first();

        if ($cuenta !== null) {
            return $cuenta;
        }

        $subtotal = $this->calcularSubtotal($comanda);

        return Cuenta::query()->create([
            'comanda_id' => $comanda->id,
            'estado' => 'abierta',
            'subtotal' => $subtotal,
            'propina' => 0,
            'total' => $subtotal,
            'abierta_at' => now(),
        ]);
    }

    /**
     * Suma de todo lo pedido menos lo anulado.
     *
     * Los items anulados se excluyen porque no se cobraron. Los items en
     * cualquier otro estado, incluido `pendiente`, SI se cuentan: el comensal
     * los pidio y la cocina los va a preparar.
     */
    public function calcularSubtotal(Comanda $comanda): int
    {
        $items = ComandaItem::query()
            ->with('modificadores')
            ->where('comanda_id', $comanda->id)
            ->whereNotIn('estado', [EstadoItemComanda::Anulado->value])
            ->get();

        $subtotal = 0;

        foreach ($items as $item) {
            $modificadores = $item->modificadores->sum('precio');
            $subtotal += ($item->precio_unitario + $modificadores) * $item->cantidad;
        }

        return $subtotal;
    }

    /**
     * Divide en partes.
     *
     * @param  list<int>|null  $montos  montos personalizados; null reparte igual
     * @return list<CuentaDivision>
     */
    public function dividir(Cuenta $cuenta, int $partes, ?array $montos = null, int $propina = 0): array
    {
        if ($partes < 1 || $partes > 40) {
            throw new RuntimeException('Una cuenta se divide entre 1 y 40 partes.');
        }

        $cuenta->propina = $propina;
        $cuenta->total = $cuenta->subtotal + $propina;
        $cuenta->save();

        $cuenta->divisiones()->delete();

        // Con montos personalizados la suma tiene que calzar con el total. Si
        // no calza, es un error de captura del mesero y es mejor rechazarlo que
        // dejar una cuenta que no cuadra: el descuadre apareceria al cerrar el
        // turno, cuando ya no hay quien lo explique.
        if ($montos !== null) {
            if (count($montos) !== $partes) {
                throw new RuntimeException('Se recibieron ' . count($montos) . ' montos para ' . $partes . ' partes.');
            }

            $cuenta->total = array_sum($montos);

            for ($indice = 0; $indice < $partes; $indice++) {
                CuentaDivision::query()->create([
                    'cuenta_id' => $cuenta->id,
                    'parte' => $indice + 1,
                    'monto' => $montos[$indice],
                ]);
            }

            return $cuenta->divisiones()->orderBy('parte')->get()->all();
        }

        $cuenta->repartir($partes);

        return $cuenta->divisiones()->orderBy('parte')->get()->all();
    }

    /**
     * Registra el pago de las partes indicadas.
     *
     * @param  list<int>  $divisiones  ids de las divisiones; vacio = todas las pendientes
     * @return list<Pago>
     */
    public function cobrar(
        Cuenta $cuenta,
        array $divisiones,
        FormaPago $forma,
        Usuario $usuario,
        ?string $identificacion = null,
    ): array {
        $consulta = CuentaDivision::query()
            ->where('cuenta_id', $cuenta->id)
            ->where('estado', 'pendiente')
            ->lockForUpdate();

        if ($divisiones !== []) {
            $consulta->whereIn('id', $divisiones);
        }

        $pendientes = $consulta->orderBy('parte')->get();

        if ($pendientes->isEmpty()) {
            throw new RuntimeException('No hay partes pendientes de cobro en esta cuenta.');
        }

        $pagos = [];

        foreach ($pendientes as $division) {
            $pagos[] = Pago::query()->create([
                'cuenta_id' => $cuenta->id,
                'cuenta_divisiones_id' => $division->id,
                'monto' => $division->monto,
                'forma_pago' => $forma->value,
                // La referencia del POS queda en blanco mientras no exista la
                // integracion. No se inventa.
                'referencia_pos' => null,
                'recibido_por_usuario_id' => $usuario->id,
            ]);

            $division->estado = 'pagada';
            $division->identificacion_pagador = $identificacion;
            $division->save();
        }

        return $pagos;
    }

    public function cerrarCuenta(Cuenta $cuenta): void
    {
        $cuenta->estado = 'cobrada';
        $cuenta->cobrada_at = now();
        $cuenta->save();
    }
}