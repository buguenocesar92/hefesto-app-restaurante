<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que trabajan en el restaurante.
 *
 * El rol es una columna, no una tabla de roles: son cinco roles fijos del
 * negocio y un modelo de permisos por tabla seria mas complejidad de la que
 * el caso justifica. Lo que cambia por local es que personas haya, no que
 * puede hacer cada una.
 *
 * El identificador de Authentik se guarda tal cual. La aplicacion no crea
 * usuarios: Authentik es la unica fuente de identidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->uuid('tenant_id');

            // Identificador del objeto en Authentik (sub del id_token).
            $tabla->string('authentik_id', 160)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('apellido', 80)->nullable();
            $tabla->string('correo', 160);

            // administrador | cajero | mesero | cocinero | jefe_cocina
            $tabla->string('rol', 32);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->foreign('tenant_id')->references('id')->on('restaurantes')->cascadeOnDelete();
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};