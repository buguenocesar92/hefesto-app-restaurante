<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitacora de la comanda: que paso, cuando y quien lo hizo.
 *
 * No es un registro decorativo. Responde a tres preguntas del dia a dia:
 *
 *  - "Cuando mando este plato?" (para explicar al comensal la espera)
 *  - "Quien lo anulo y por que?" (un reclamo por comida cobrada no cobrada)
 *  - "Este plato quedo listo a las 21:40 y nadie lo llevo hasta las 22:10:
 *    estaba en el pasillo o nunca se marco?" (el problema de gestion que
 *    motiva la pantalla de cocina)
 *
 * Los eventos se escriben DENTRO de la misma transaccion que el cambio de
 * estado. Si el cambio se confirma, el evento existe; si el evento existe, el
 * cambio se confirmo. Nunca hay uno sin el otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comanda_eventos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('comanda_id')->constrained('comandas')->cascadeOnDelete();
            $tabla->foreignId('comanda_item_id')->nullable()->constrained('comanda_items')->cascadeOnDelete();
            $tabla->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();

            // comanda_abierta | item_agregado | enviado_a_cocina |
            // item_en_preparacion | item_listo | item_entregado |
            // item_anulado | comanda_por_cobrar | comanda_cobrada | comanda_anulada
            $tabla->string('tipo', 40);

            $tabla->string('resumen', 240)->nullable();
            $tabla->jsonb('detalle')->default('{}');

            $tabla->timestampTz('ocurrio_en')->useCurrent();
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'comanda_id']);
            $tabla->index(['tenant_id', 'ocurrio_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comanda_eventos');
    }
};