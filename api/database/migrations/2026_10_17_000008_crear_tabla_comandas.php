<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comandas: el corazon del sistema.
 *
 * La comanda es lo que viaja a cocina. El estado de esta fila es lo que
 * responde a la pregunta que se hace el dueño en cinco segundos: "que esta
 * pasando en mi local ahora".
 *
 * Estados: abierta | por_cobrar | cobrada | anulada
 *
 * `por_cobrar` es un estado propio, no un detalle de `abierta`. Cuando el
 * mesero pide la cuenta, deja de poder agregar items, y el restaurante cambia
 * de actividad. Mezclarlo en un solo estado obliga a guardar la misma verdad
 * en un campo aparte, y los dos se pueden desincronizar.
 *
 * La comanda cuelga de `grupos_mesas` y no de `mesas`. Ver la migracion
 * 2026_10_17_000004 para el porque.
 *
 * `folio` es un correlativo POR RESTAURANTE, que es como funciona un ticket de
 * comanda real. El correlativo vive en `contadores_folio` con bloqueo de fila
 * para que dos meseros no saquen el mismo numero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comandas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('grupos_mesas_id')->constrained('grupos_mesas')->restrictOnDelete();

            // Correlativo por restaurante, empezando en 1.
            $tabla->unsignedInteger('folio');

            // abierta | por_cobrar | cobrada | anulada
            $tabla->string('estado', 24)->default('abierta');

            // Cantidad de comensales. La cuenta se divide por esta cifra, asi
            // que se guarda al abrir y no se recalcula al cobrar.
            $tabla->unsignedSmallInteger('comensales')->default(1);

            $tabla->foreignId('abierta_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();

            // Turno en el que se abrio. Permite saber al cierre que una comanda
            // quedo abierta de la noche anterior.
            $tabla->foreignId('abierta_en_turno_id')->nullable()->constrained('turnos')->nullOnDelete();

            $tabla->text('notas')->nullable();

            $tabla->timestampTz('abierta_at');
            $tabla->timestampTz('cerrada_at')->nullable();
            $tabla->timestampTz('cobrada_at')->nullable();
            $tabla->timestampTz('anulada_at')->nullable();

            $tabla->foreignId('anulada_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->text('motivo_anulacion')->nullable();

            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            // El folio es unico dentro del restaurante.
            $tabla->unique(['tenant_id', 'folio']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'grupos_mesas_id']);
        });

        Schema::create('contadores_folio', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');
            $tabla->string('concepto', 60);
            $tabla->unsignedBigInteger('ultimo_valor')->default(0);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->unique(['tenant_id', 'concepto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contadores_folio');
        Schema::dropIfExists('comandas');
    }
};