<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoComanda;
use App\Enums\Rol;
use App\Models\Comanda;
use App\Models\Usuario;

/**
 * Autorizacion sobre la comanda.
 *
 * LA REGLA QUE PROTEGE EL COBRO
 * -----------------------------
 * Una comanda cobrada NO se anula desde este sistema, para ningun rol,
 * incluido el administrador. Un cobro ya realizado se revierte en el punto de
 * venta que lo emitio, con su propia autorizacion y su propia bitacora.
 * Adivinar aqui una anulacion seria inventar una devolucion que nadie
 * registro, y en un local con caja eso es un problema serio.
 *
 * Esta es la primera prueba que hay que correr en la suite: `un_mesero_no_puede
 * _anular_una_comanda_cobrada`.
 */
class ComandaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->activo;
    }

    public function view(Usuario $usuario, Comanda $comanda): bool
    {
        // El filtro por restaurante ya lo hizo la RLS de PostgreSQL. La
        // comprobacion explicita de rol es para la interfaz, no para el
        // aislamiento.
        return $usuario->activo && ! $usuario->es(Rol::Cocinero);
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador);
    }

    /** Agregar items: la comanda tiene que seguir abierta. */
    public function agregarItem(Usuario $usuario, Comanda $comanda): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador)
            && $comanda->estado->admiteItems();
    }

    public function editarItem(Usuario $usuario, Comanda $comanda): bool
    {
        return $this->agregarItem($usuario, $comanda);
    }

    /** Pasar a "por cobrar". Lo puede pedir el mesero. */
    public function pedirCuenta(Usuario $usuario, Comanda $comanda): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador)
            && $comanda->estado === EstadoComanda::Abierta;
    }

    /**
     * Reabrir una comanda que estaba por cobrar, porque el comensal pidio algo
     * mas. Desde cobrada NO: el cobro ya ocurrio.
     */
    public function reabrir(Usuario $usuario, Comanda $comanda): bool
    {
        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::Administrador)
            && $comanda->estado === EstadoComanda::PorCobrar;
    }

    /**
     * Anular la comanda entera.
     *
     * El estado se consulta primero a proposito: desde `cobrada` se devuelve
     * falso sin mirar el rol, para que el mensaje de error sea siempre el
     * mismo y no dependa de quien pregunta.
     */
    public function anular(Usuario $usuario, Comanda $comanda): bool
    {
        if (! $comanda->estado->admiteAnulacion()) {
            return false;
        }

        return $usuario->es(Rol::Mesero, Rol::Cajero, Rol::JefeCocina, Rol::Administrador);
    }

    /** Cobrar: solo caja. El mesero pide la cuenta, no la cobra. */
    public function cobrar(Usuario $usuario, Comanda $comanda): bool
    {
        return $usuario->es(Rol::Cajero, Rol::Administrador)
            && $comanda->estado->admiteCobro();
    }
}