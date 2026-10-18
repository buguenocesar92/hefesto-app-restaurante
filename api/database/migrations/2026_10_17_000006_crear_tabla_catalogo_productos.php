<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de venta: categorias y productos.
 *
 * `tipo_linea` NO es lo mismo que `estaciones.tipo`. El tipo de linea dice que
 * se vende (comida, bebida, postre); la estacion dice quien lo prepara. Un
 * plato puede ser de tipo comida y estar en la estacion de postura.
 *
 * `precio` esta en pesosChileanos enteros. Un restaurante chileno no necesita
 * decimales en el menu: los precios son 3.500, 4.900. Guardarlo en enteros
 * evita el problema classico de redondeo al dividir la cuenta.
 *
 * `precio_es_costo_real` distingue lo que Hefesto no implementa (integracion
 * con el POS): si el precio mostrado aqui coincide con el que cobra el POS. Si
 * el POS es la autoridad y este catalogo se desincroniza, hay que saberlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_productos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 100);
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'orden']);
        });

        Schema::create('productos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('categoria_productos_id')->constrained('categorias_productos')->restrictOnDelete();
            $tabla->string('codigo', 40);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();

            $tabla->unsignedInteger('precio');
            $tabla->string('unidad', 24)->default('porcion');

            // comida | bebida | postre
            $tabla->string('tipo_linea', 24);

            // Donde se prepara. Una bebida va a barra; no existe el caso de una
            // bebida que vaya a la cocina caliente, y el modelo lo refleja.
            $tabla->foreignId('estacion_id')->constrained('estaciones')->restrictOnDelete();

            // Ajuste fino sobre el objetivo de la estacion, en segundos, para
            // el caso de un plato que se sale del promedio. Nulo = usar el de
            // la estacion.
            $tabla->unsignedInteger('tiempo_objetivo_segundos')->nullable();

            $tabla->boolean('acepta_modificadores')->default(true);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->index(['tenant_id', 'tipo_linea', 'activo']);
            $tabla->index(['tenant_id', 'estacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias_productos');
    }
};