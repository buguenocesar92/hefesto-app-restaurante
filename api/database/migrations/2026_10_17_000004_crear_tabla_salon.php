<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salon: zonas, mesas y el agrupamiento de mesas combinables.
 *
 * LA DECISION IMPORTANTE: LA COMANDA NO CUELGA DE UNA MESA, SINO DE UN GRUPO.
 *
 * La exception que rompe el modelo de "una mesa, una comanda" es la mesa
 * combinada: se unen dos mesas para una mesa de ocho. Hay dos caminos:
 *
 *  a) Guardar `comanda.mesa_id` mas un campo booleano `combinada` y un
 *     segundo id. El caso normal paga elmodelo complicated siempre.
 *
 *  b) Tratar SIEMPRE una mesa como un grupo. Una mesa sola es un grupo de un
 *     miembro. Una mesa combinada es un grupo de dos o mas.
 *
 * Se eligio (b). La comanda apunta a `grupos_mesas` y no a `mesas`. El caso
 * corriente y el caso raro comparten el mismo camino de codigo, y por eso la
 * combinacion no necesita tratamento especial en ningun endpoint.
 *
 * `mesas.estado` esta desnormalizado a proposito: el mapa del salon dibuja
 * cientos de mesas y no puede pagar una consulta al grupo por cada una. Se
 * actualiza dentro de la misma transaccion que el grupo. La fuente de verdad
 * es la existencia de una comanda abierta en el grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 80);
            $tabla->unsignedInteger('orden')->default(0);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'orden']);
        });

        Schema::create('mesas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('zona_id')->constrained('zonas')->restrictOnDelete();
            $tabla->string('nombre', 40);
            $tabla->unsignedSmallInteger('capacidad')->default(4);

            // libre | ocupada | por_cerrar
            $tabla->string('estado', 24)->default('libre');

            // Posicion en el mapa, en centimetros. Es solo para dibujar.
            $tabla->unsignedInteger('posicion_x')->default(0);
            $tabla->unsignedInteger('posicion_y')->default(0);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            // El nombre de mesa es unico dentro del restaurante, no del mundo.
            $tabla->unique(['tenant_id', 'nombre']);
            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('grupos_mesas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            // Nombre legible del agrupamiento. Para una mesa sola coincide con
            // el nombre de la mesa ("Mesa 5"). Para una combinacion, describe
            // la union ("Mesas 5+6").
            $tabla->string('nombre', 80);

            $tabla->boolean('es_combinacion')->default(false);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'es_combinacion']);
        });

        Schema::create('grupo_mesas_mesas', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('grupo_mesas_id')->constrained('grupos_mesas')->cascadeOnDelete();
            $tabla->foreignId('mesa_id')->constrained('mesas')->cascadeOnDelete();

            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            // Una mesa fisica no puede estar en dos grupos a la vez.
            $tabla->unique(['mesa_id']);
            $tabla->index(['tenant_id', 'grupo_mesas_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grupo_mesas_mesas');
        Schema::dropIfExists('grupos_mesas');
        Schema::dropIfExists('mesas');
        Schema::dropIfExists('zonas');
    }
};