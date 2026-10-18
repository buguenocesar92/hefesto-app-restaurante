<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estaciones de trabajo: el destino de cada pedido.
 *
 * ESTA TABLA ES LA RESPUESTA A "LAS BEBIDAS SON MAS RAPIDAS QUE LA COMIDA".
 *
 * Un modelo con una sola "cocina" obliga a que las bebidas esperen en la misma
 * cola que un pastel de choclo. En el mostrador real no es asi: la barra
 * sirve una cerveza en dos minutos mientras el comensal sigue esperando el
 * plato. Si el modelo no distingue estaciones, el tiempo en pantalla de la
 * bebida se infla artificialmente y el tiempo del plato se ve mas rapido de lo
 * que es. Los dos numeros mienten.
 *
 * Por eso `estaciones.tipo` distingue:
 *   - comida:  cocina caliente, partidas que tardan
 *   - bebida:  barra, bebidas rapidas
 *   - postura: vitrina de postres y cafe
 *
 * Y cada producto apunta a SU estacion. La pantalla de cocina se filtra por
 * estacion, no por tipo de linea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 80);

            // comida | bebida | postura
            $tabla->string('tipo', 24);

            // Tiempo que la estacion se compromete a entregar. Es el criterio
            // con el que la pantalla marca "atrasado": no es una promesa al
            // comensal, es el umbral interno que usa el jefe de cocina para
            // saber que tiene que empujar.
            $tabla->unsignedInteger('tiempo_objetivo_segundos')->default(900);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estaciones');
    }
};