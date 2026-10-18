<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turnos: las ventanas horarias en las que se opera.
 *
 * Un turno no es un turno de una persona. Es la franja en que el local esta
 * abierto (almuerzo, cena, noche). Las personas se asignan a un turno en
 * `turno_asignaciones`.
 *
 * La existencia de esta tabla resuelve un problema concreto: el comensal del
 *viernes a las 14:00 no deberia poder abrir una comanda si el local cerro a la
 * una. Y el mismo mesero, a las 23:00, no deberia cobrar una comanda que
 * quedo abierta de la noche anterior sin que nadie lo notara.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 80);
            $tabla->time('hora_inicio');
            $tabla->time('hora_fin');

            // Un turno puede cruzar la medianoche (una noche que empieza a las
            // 20:00 y termina a las 03:00). Con true, la condicion de "estamos
            // dentro del turno" se evalua como intervalo que envuelve el cero.
            $tabla->boolean('cruza_medianoche')->default(false);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'activo']);
        });

        Schema::create('turno_asignaciones', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            $tabla->foreignId('turno_id')->constrained('turnos')->cascadeOnDelete();
            $tabla->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();

            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();

            // Una persona no se asigna dos veces al mismo turno.
            $tabla->unique(['turno_id', 'usuario_id']);
            $tabla->index(['tenant_id', 'usuario_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turno_asignaciones');
        Schema::dropIfExists('turnos');
    }
};