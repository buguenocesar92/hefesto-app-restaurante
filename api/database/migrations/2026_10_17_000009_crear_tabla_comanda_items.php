<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items de la comanda: lo que sale de verdad a cocina.
 *
 * CADA ITEM TIENE SU PROPIA MAQUINA DE ESTADOS. No existe un estado de
 * "la comanda esta en cocina": existen cuatro o cinco pedidos con tiempos
 * distintos, y el ultimo en salir es el que define cuando el comensal puede
 * comer. Un unico estado por comanda obliga a declarar la comanda completa
 * cuando solo un plato salio, y ese es justamente el dato que servicio
 * necesita para saber que falta algo.
 *
 * Estados: pendiente | enviado | en_preparacion | listo | entregado | anulado
 *
 * - `pendiente`: esta en la comanda del salon pero todavia no se ha enviado a
 *   cocina. El mesero lo escribe, el cocinero no lo ve todavia.
 * - `enviado`: llego a pantalla de cocina. Empieza a contar el tiempo.
 * - `en_preparacion`: alguien tomo el pedido.
 * - `listo`: esta en el pasillo esperando que alguien lo lleve. Es el estado
 *   que mas problemas genera, porque nadie lo reclama y el tiempo sigue
 *   corriendo. Por eso la pantalla ordena por tiempo y no por orden de
 *   llegada.
 * - `entregado`: llego al comensal.
 * - `anulado`: se elimino antes de la cuenta.
 *
 * `estacion_id` se COPIA al item aunque ya exista en el producto. Si el
 * producto cambia de estacion, las comandas de ayer tienen que seguir
 * mostrando la coccion de ayer.
 *
 * `tiempo_objetivo_segundos` tambien se copia, resuelto (el del producto si
 * existe, si no el de la estacion). Es el umbral contra el que la pantalla
 * pinta "atrasado". Cambiar el objetivo no reescribe el historico.
 *
 * `precio_unitario` se copia del producto por la misma razon.
 *
 * SOBRE LOS TIEMPOS: las columnas `enviado_at`, `listo_at` y `entregado_at`
 * se guardan para el historico y los reportes. LA PANTALLA DE COCINA NO LAS
 * LEE: calcula el tiempo en vivo contra Redis, porque se consulta cada varios
 * segundos por item. Ver App\Services\Cocina\RelojDeCocina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comanda_items', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('comanda_id')->constrained('comandas')->cascadeOnDelete();
            $tabla->foreignId('producto_id')->constrained('productos')->restrictOnDelete();

            $tabla->string('nombre_producto', 160);
            $tabla->unsignedSmallInteger('cantidad')->default(1);
            $tabla->unsignedInteger('precio_unitario');

            // Nota de preparacion. Es texto, y es correcto que lo sea: "sin
            // sal", "bien pasado" o "urgente" no son datos consultables. Los
            // cambios de preparacion con efecto en el precio o en el
            // inventario (sin cebolla, extra queso) NO van aqui: van en
            // comanda_item_modificadores.
            $tabla->text('notas_preparacion')->nullable();

            $tabla->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();
            $tabla->string('tipo_linea', 24);
            $tabla->unsignedInteger('tiempo_objetivo_segundos');

            // pendiente | enviado | en_preparacion | listo | entregado | anulado
            $tabla->string('estado', 24)->default('pendiente');

            $tabla->timestampTz('enviado_at')->nullable();
            $tabla->timestampTz('listo_at')->nullable();
            $tabla->timestampTz('entregado_at')->nullable();

            $tabla->foreignId('anulado_por_usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->text('motivo_anulacion')->nullable();
            $tabla->timestampTz('anulado_at')->nullable();

            $tabla->unsignedInteger('orden')->default(0);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'estacion_id', 'estado']);
            $tabla->index(['comanda_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comanda_items');
    }
};