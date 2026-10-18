<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE RESTAURANTES: Row Level Security (RLS) de PostgreSQL.
 *
 * Es el corazon de la seguridad de este producto y es el mismo mecanismo que
 * usa apps/portal-autoservicio: columna `tenant_id` mas politica de RLS, no
 * un esquema por cliente. El analisis de por que se descarto el esquema por
 * cliente esta en apps/portal-autoservicio/docs/03-MULTITENANCY.md y se
 * repite adaptado en docs/03-MULTITENANCY-Y-ROLES.md de este proyecto.
 *
 * AQUI EL TENANT ES UN RESTAURANTE. Que un mesero del Restaurante A vea la
 * comanda del Restaurante B es exactamente el fallo que hay que impedir.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee la variable de sesion `app.tenant_id`. Si no
 *    esta definida, LANZA un error. No devuelve NULL: falla de forma ruidosa.
 * 2. Cada tabla de negocio tiene una politica que exige
 *    `tenant_id = app_tenant_id()`, al leer (USING) y al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la politica aplique tambien al dueno
 *    de la tabla. Sin FORCE, en desarrollo (donde el rol de la aplicacion suele
 *    ser el dueno) el aislamiento seria decorativo.
 * 4. La variable se fija con `set_config(..., true)`, alcance de TRANSACCION.
 *    Al confirmar o revertir, PostgreSQL la descarta sola. Por eso el worker de
 *    colas, que reutiliza la misma conexion para restaurantes distintos, no
 *    puede arrastrar el tenant de otro.
 *
 * `restaurantes` NO lleva RLS a proposito: se consulta en el inicio de sesion,
 * cuando todavia no hay tenant. Se compensa con la vista publica de la
 * migracion siguiente y con que el rol de la aplicacion no sea superusuario.
 *
 * REQUISITO DE DESPLIEGUE: el rol de la base NO puede ser superusuario ni
 * tener BYPASSRLS; con cualquiera de los dos PostgreSQL ignora las politicas.
 * El comando `php artisan restaurante:verificar-aislamiento` lo comprueba.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
    private array $tablasProtegidas = [
        'usuarios',
        'turnos',
        'turno_asignaciones',
        'zonas',
        'mesas',
        'grupos_mesas',
        'grupo_mesas_mesas',
        'estaciones',
        'categorias_productos',
        'productos',
        'ingredientes',
        'receta_ingredientes',
        'grupos_modificadores',
        'modificadores',
        'producto_grupos_modificadores',
        'comandas',
        'contadores_folio',
        'comanda_items',
        'comanda_item_modificadores',
        'comanda_eventos',
        'cuentas',
        'cuenta_divisiones',
        'pagos',
    ];

    public function up(): void
    {
        // 1. Funcion de contexto. Falla cerrado.
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de tenant en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otro restaurante.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverRestaurante, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL;

        DB::unprepared($sql);

        $sql = <<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
            'Devuelve el restaurante de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL;

        DB::unprepared($sql);

        // 2. Politicas y activacion por tabla.
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_' . $tabla;

            $sql = <<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL;

        DB::unprepared($sql);
        }

        // 3. Falla ruidosamente si alguna tabla de la lista no tiene
        //    `tenant_id`. Sin esto, un error de tipeo dejaria una tabla de
        //    datos de negocio sin politica y la migracion pasaria igual.
        $this->verificarColumnaTenant();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ''public'' AND table_name = ? AND column_name = ''tenant_id''
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migracion antes de continuar.'
                );
            }
        }
    }
};