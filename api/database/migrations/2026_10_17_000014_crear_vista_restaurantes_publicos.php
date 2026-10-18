<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista publica de restaurantes, para el inicio de sesion.
 *
 * `restaurantes` no lleva RLS porque se consulta cuando todavia no hay sesion y
 * por lo tanto no hay `app.tenant_id` que poner. Esta vista es la
 * compensacion: antes de autenticarse, la aplicacion NO lee la tabla cruda,
 * lee esto, que expone solo tres columnas y solo de los restaurantes activos.
 *
 * No expone correos, zonas geograficas ni identificadores internos.
 *
 * RIESGO RESIDUAL, dicho con todas las letras: el `slug` se puede enumerar
 * antes de autenticarse, porque aparece en la URL de entrada y en los enlaces
 * que el restaurante reparte. Un atacante puede descubrir que existe un
 * restaurante con ese nombre. No puede ver nada mas. La alternativa (dejar el
 * campo en blanco hasta el login y resolver por correo) queda anotada como
 * decision pendiente del cliente, no como algo resuelto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE VIEW public.vw_restaurantes_publicos AS
            SELECT
                slug,
                nombre,
                activo
            FROM public.restaurantes
            WHERE activo = true;

        SQL;

        DB::unprepared($sql);

        $sql = <<<'SQL'
            COMMENT ON VIEW public.vw_restaurantes_publicos IS
            'Unica forma de leer restaurantes antes de iniciar sesion. Expone slug, nombre y activo.';
        SQL;

        DB::unprepared($sql);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_restaurantes_publicos');
    }
};