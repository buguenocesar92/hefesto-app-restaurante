<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas, division por comensales y pagos.
 *
 * Aqui NO hay integracion con el POS. El cobro ocurre contra el POS que ya
 * tiene el restaurante, y esta tabla guarda lo que el salon necesita saber
 * para poder dividirse la cuenta: cuantas partes, quien pago, cuanto.
 *
 * Lo que NO esta hecho a proposito: emitir boleta, anular una venta cobrada
 * en el POS, o sincronizar precios. Esas operaciones ocurren en el POS y esta
 * aplicacion no las ejecuta. El documento de integracion es
 * docs/09-API.md, seccion "Integracion con el POS: guia, no implementacion".
 *
 * `forma_pago` y `referencia_pos` existen para que, cuando se implemente la
 * integracion, el dato este disponible sin cambiar el historial.
 *
 * La division en partes iguales es el caso comun pero no el unico: por eso
 * cada division guarda SU monto y no solo el numero de comensal. Una mesa de
 * cuatro donde el nino no paga, o donde una parte incluye el plato de otro,
 * se puede expresar con esta tabla sin encajes raros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('comanda_id')->constrained('comandas')->cascadeOnDelete();

            // abierta | cobrada | anulada
            $tabla->string('estado', 24)->default('abierta');

            $tabla->unsignedInteger('subtotal')->default(0);
            $tabla->unsignedInteger('propina')->default(0);
            $tabla->unsignedInteger('total')->default(0);

            $tabla->timestampTz('abierta_at');
            $tabla->timestampTz('cobrada_at')->nullable();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('cuenta_divisiones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();

            // Numero de la parte, contando desde 1.
            $tabla->unsignedSmallInteger('parte');

            $tabla->unsignedInteger('monto');
            $tabla->unsignedInteger('propina')->default(0);

            // pendiente | pagada
            $tabla->string('estado', 24)->default('pendiente');

            // Quien se identificó como quien paga. Es informativo para reparto
            // en el salon; no es autenticacion.
            $tabla->string('identificacion_pagador', 80)->nullable();

            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->unique(['cuenta_id', 'parte']);
            $tabla->index(['tenant_id', 'cuenta_id']);
        });

        Schema::create('pagos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('cuenta_id')->constrained('cuentas')->restrictOnDelete();
            $tabla->foreignId('cuenta_divisiones_id')->nullable()->constrained('cuenta_divisiones')->nullOnDelete();

            $tabla->unsignedInteger('monto');

            // efectivo | tarjeta_debito | tarjeta_credito | transferencia
            $tabla->string('forma_pago', 32);

            // Identificador del cobro en el POS. Se llena cuando exista la
            // integracion. Hoy queda en blanco a proposito: no se inventa un
            // identificador de un sistema con el que no hablamos.
            $tabla->string('referencia_pos', 120)->nullable();

            $tabla->foreignId('recibido_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'cuenta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('cuenta_divisiones');
        Schema::dropIfExists('cuentas');
    }
};