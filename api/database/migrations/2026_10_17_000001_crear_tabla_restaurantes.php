<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurantes: el tenant del sistema.
 *
 * El tenant de esta aplicacion es un restaurante. La columna de todas las
 * tablas de negocio se llama igual `tenant_id`, para reutilizar exactamente el
 * patron de apps/portal-autoservicio (columna + Row Level Security) en vez de
 * un patron paralelo que habria que mantener dos veces.
 *
 * Esta tabla NO lleva RLS a proposito: se consulta en el inicio de sesion,
 * cuando todavia no hay `app.tenant_id` que poner. El punto ciego y sus
 * compensaciones estan en docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurantes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            $tabla->string('nombre', 160);
            // Identificador corto para la URL y los nombres de host. Es publico
            // por diseno: ver la nota sobre enumeracion en docs/03.
            $tabla->string('slug', 80)->unique();
            $tabla->string('zona_geografica', 80)->nullable();
            $tabla->string('correo_contacto', 160)->nullable();

            $tabla->boolean('activo')->default(true);

            // Propia del restaurante, no de Hefesto. Un local por instalacion
            // es lo habitual; una cadena usa varias filas.
            $tabla->string('timezone', 64)->default('America/Santiago');

            $tabla->timestampsTz();

            $tabla->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurantes');
    }
};