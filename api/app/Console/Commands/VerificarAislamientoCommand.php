<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que la barrera de aislamiento entre restaurantes siga en pie.
 *
 * QUE COMPRUEBA
 * -------------
 * 1. Que el rol de la base NO sea superusuario. Con superusuario, PostgreSQL
 *    ignora TODAS las politicas de Row Level Security, y el aislamiento deja de
 *    existir sin dar ningun error. Es la forma mas comun de perder la
 *    seguridad de este patron sin enterarse.
 * 2. Que el rol NO tenga BYPASSRLS. Es lo mismo, por otra via.
 * 3. Que toda tabla de negocio tenga RLS activado.
 * 4. Que toda tabla de negocio tenga su politica de aislamiento.
 * 5. Que la funcion de contexto siga fallando cerrada: una consulta sin
 *    `app.tenant_id` tiene que fallar, no devolver cero filas en silencio.
 *
 * ESTE COMANDO SE CORRE EN EL DESPLIEGUE Y A DIARIO.
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'restaurante:verificar-aislamiento';

    protected $description = 'Verifica que la Row Level Security entre restaurantes este activa y sea efectiva.';

    /** @var list<string> */
    private array $tablasProtegidas = [
        'usuarios', 'turnos', 'turno_asignaciones', 'zonas', 'mesas',
        'grupos_mesas', 'grupo_mesas_mesas', 'estaciones', 'categorias_productos',
        'productos', 'ingredientes', 'receta_ingredientes', 'grupos_modificadores',
        'modificadores', 'producto_grupos_modificadores', 'comandas',
        'contadores_folio', 'comanda_items', 'comanda_item_modificadores',
        'comanda_eventos', 'cuentas', 'cuenta_divisiones', 'pagos',
    ];

    public function handle(): int
    {
        $problemas = [];

        $problemas = array_merge($problemas, $this->verificarRolDeLaBase());
        $problemas = array_merge($problemas, $this->verificarPoliticas());
        $problemas = array_merge($problemas, $this->verificarFalloCerrado());

        if ($problemas === []) {
            $this->info('Aislamiento verificado: el rol no evade RLS, todas las tablas tienen politica y el contexto falla cerrado.');

            return self::SUCCESS;
        }

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        $this->error('El aislamiento entre restaurantes NO esta garantizado. Revisar antes de abrir el local.');

        return self::FAILURE;
    }

    /** @return list<string> */
    private function verificarRolDeLaBase(): array
    {
        // La columna del superusuario en `pg_roles` se llama `rolsuper`.
        // Antes se pedía `usesuper`, que no existe: la consulta reventaba y el
        // comando terminaba sin verificar nada. Un verificador que revienta no
        // informa de nada, y quien lo corre se queda con la impresión de que
        // el sistema está roto en vez de con la de que el aislamiento falla.
        try {
            $actual = DB::selectOne('SELECT rolname AS rol, rolsuper AS superusuario, rolbypassrls AS evade_rls FROM pg_roles WHERE rolname = current_user');
        } catch (\Throwable $e) {
            return [
                'No se pudo leer el rol actual de PostgreSQL: ' . $e->getMessage(),
                'Sin ese dato no se puede afirmar nada sobre el aislamiento: el comando no verificó el rol.',
            ];
        }

        if ($actual === null) {
            return ['No se pudo leer el rol actual de PostgreSQL.'];
        }

        $problemas = [];

        if ((bool) $actual->superusuario) {
            $problemas[] = $this->explicacionDelRolDefectuoso($actual->rol, 'ES SUPERUSUARIO');
        }

        if ((bool) $actual->evade_rls) {
            $problemas[] = $this->explicacionDelRolDefectuoso($actual->rol, 'tiene BYPASSRLS');
        }

        return $problemas;
    }

    /**
     * El texto que hay que leer cuando el rol conectado no es el correcto.
     *
     * Decir solamente "el rol es superusuario" no sirve de nada: quien lee esto
     * en la consola del despliegue tiene que entender por qué eso anula el
     * aislamiento y qué tiene que hacer al respecto, porque si no lo corrige y
     * vuelve a correr el comando, va a pasar lo mismo. Por eso el mensaje
     * explica las tres cosas: qué se detectó, por qué importa, y dónde está
     * el arreglo. La referencia del patrón está en
     * `apps/almacen/deploy/postgres/inicializar-rol.sh`.
     */
    private function explicacionDelRolDefectuoso(string $nombre, string $problema): string
    {
        return sprintf(
            'El rol "%s" %s. Un superusuario, o un rol con BYPASSRLS, ignora TODAS las políticas de '
            . 'Row Level Security. `FORCE ROW LEVEL SECURITY` sujeta al dueño de la tabla, pero NO al '
            . 'superusuario ni a un rol con BYPASSRLS: con ese rol ninguna política de esta base se '
            . 'aplica y el aislamiento entre restaurantes no existe. Qué hacer: `POSTGRES_USER` debe ser '
            . 'el rol de ARRANQUE `restaurante_admin`, que la aplicación nunca usa, y el rol de la '
            . 'aplicación tiene que estar creado NOSUPERUSER NOBYPASSRLS en `deploy/postgres/'
            . 'inicializar-rol.sh`. Patrón de referencia: apps/almacen/deploy/postgres/inicializar-rol.sh.',
            $nombre,
            $problema,
        );
    }

    /** @return list<string> */
    private function verificarPoliticas(): array
    {
        $problemas = [];

        foreach ($this->tablasProtegidas as $tabla) {
            $estado = DB::selectOne(
                'SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzado
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = ''public'' AND c.relname = ?',
                [$tabla]
            );

            if ($estado === null) {
                $problemas[] = 'La tabla public.' . $tabla . ' no existe. Faltan migraciones.';

                continue;
            }

            if (! $estado->rls) {
                $problemas[] = 'La tabla public.' . $tabla . ' NO tiene Row Level Security activado.';
            }

            if (! $estado->forzado) {
                $problemas[] = 'La tabla public.' . $tabla . ' no tiene FORCE ROW LEVEL SECURITY: su dueno la saltaria.';
            }

            $politicas = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE schemaname = ''public'' AND tablename = ?',
                [$tabla]
            );

            if ((int) ($politicas->total ?? 0) === 0) {
                $problemas[] = 'La tabla public.' . $tabla . ' no tiene ninguna politica de aislamiento.';
            }
        }

        return $problemas;
    }

    /**
     * Comprueba que una consulta SIN contexto falle.
     *
     * Esta es la comprobacion mas importante y la mas incomoda: obliga a que la
     * funcion de contexto lance excepcion. Si un dia alguien la cambia para que
     * devuelva NULL en vez de fallar, el aislamiento sigue "funcionando" pero
     * devuelve cero filas en silencio, y una consulta sin tenant se ve como un
     * local sin datos.
     *
     * @return list<string>
     */
    private function verificarFalloCerrado(): array
    {
        $problemas = [];

        try {
            // Consulta a una tabla protegida SIN fijar app.tenant_id. Si la
            // funcion falla cerrado, esto revienta, que es lo que se busca.
            DB::select('SELECT count(*) FROM public.comandas');

            $problemas[] = 'Una consulta a comandas SIN contexto de restaurante NO fallo. '
                . 'La funcion app_tenant_id() deberia lanzar excepcion y parece que no.';
        } catch (\Throwable) {
            // Falla como debe. No hay nada que reportar.
        }

        return $problemas;
    }
}