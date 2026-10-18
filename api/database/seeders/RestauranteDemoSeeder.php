<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Enums\EstadoMesa;
use App\Enums\Rol;
use App\Enums\TipoEstacion;
use App\Enums\TipoLinea;
use App\Enums\TipoModificador;
use App\Models\CategoriaProducto;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\ComandaItemModificador;
use App\Models\Estacion;
use App\Models\GrupoMesas;
use App\Models\Ingrediente;
use App\Models\Mesa;
use App\Models\Modificador;
use App\Models\Producto;
use App\Models\ProductoGrupoModificadores;
use App\Models\RecetaIngrediente;
use App\Models\Restaurante;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Restaurante de ejemplo, con una comanda abierta y cocina trabajando.
 *
 * La semilla no arma un catalogo bonito y vazio: arma un local en plena
 * operacion. Hay una comanda abierta con un item entregado, uno en
 * preparacion y uno recien enviado, y una bebida en barra. Asi, al levantar la
 * aplicacion, la pantalla de cocina muestra tiempos reales en vez de una lista
 * vacia, que es lo que hace incomodo evaluar el sistema.
 *
 * La semilla NO arma el reloj de cocina: la pantalla de cocina lee Redis y la
 * semilla solo escribe en la base. Despues de sembrar hay que correr
 * `php artisan restaurante:reconstruir-reloj` para que el reloj tenga marcas.
 * Se deja explicito porque es el paso que se olvidaria.
 */
class RestauranteDemoSeeder extends Seeder
{
    public function run(): void
    {
        $restaurante = $this->crearRestaurante();
        $restauranteId = $restaurante->id;

        $usuarios = $this->crearUsuarios($restauranteId);
        $this->crearTurnos($restauranteId, $usuarios);

        $estaciones = $this->crearEstaciones($restauranteId);
        $gruposMesas = $this->crearSalon($restauranteId);
        $this->crearCatalogo($restauranteId, $estaciones);

        $this->crearComandaDeEjemplo($restauranteId, $gruposMesas['mesa_3'], $usuarios['mesero']);

        $this->command?->info('');
        $this->command?->info('Restaurante de ejemplo creado: ' . $restaurante->nombre);
        $this->command?->info('Importante: la pantalla de cocina lee Redis, no la base.');
        $this->command?->info('Correr "php artisan restaurante:reconstruir-reloj" para llenarla.');
    }

    private function crearRestaurante(): Restaurante
    {
        return Restaurante::query()->create([
            'nombre' => 'Restaurante La Vertiente',
            'slug' => 'la-vertiente',
            'zona_geografica' => 'Valparaiso',
            'correo_contacto' => 'contacto@ejemplo.cl',
            'activo' => true,
            'timezone' => 'America/Santiago',
        ]);
    }

    /** @return array<string, Usuario> */
    private function crearUsuarios(string $restauranteId): array
    {
        $personas = [
            'administradora' => ['Mariana Acosta', 'mariana.acosta@ejemplo.cl', Rol::Administrador],
            'cajero' => ['Jorge Fuentes', 'jorge.fuentes@ejemplo.cl', Rol::Cajero],
            'mesero' => ['Camila Rojas', 'camila.rojas@ejemplo.cl', Rol::Mesero],
            'mesera_noche' => ['Bastián Muñoz', 'bastian.munoz@ejemplo.cl', Rol::Mesero],
            'cocinero' => ['Andrés Vega', 'andres.vega@ejemplo.cl', Rol::Cocinero],
            'jefe_cocina' => ['Patricia Ñanco', 'patricia.nanco@ejemplo.cl', Rol::JefeCocina],
        ];

        $creados = [];

        foreach ($personas as $clave => [$nombre, $correo, $rol]) {
            [$nombre, $apellido] = explode(' ', $nombre, 2);

            $creados[$clave] = Usuario::query()->create([
                'tenant_id' => $restauranteId,
                'authentik_id' => (string) Str::uuid(),
                'nombre' => $nombre,
                'apellido' => $apellido,
                'correo' => $correo,
                'rol' => $rol->value,
                'activo' => true,
            ]);
        }

        return $creados;
    }

    /** @param  array<string, Usuario>  $usuarios */
    private function crearTurnos(string $restauranteId, array $usuarios): void
    {
        $almuerzo = Turno::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Almuerzo',
            'hora_inicio' => '12:00:00',
            'hora_fin' => '17:00:00',
            'cruza_medianoche' => false,
            'activo' => true,
        ]);

        $cena = Turno::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Cena',
            'hora_inicio' => '19:00:00',
            'hora_fin' => '23:59:00',
            'cruza_medianoche' => false,
            'activo' => true,
        ]);

        // Turno que cruza la medianoche: existe para ejercitar el caso en el
        // que la comparacion simple de horas daria un resultado falso.
        $noche = Turno::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Noche tardia',
            'hora_inicio' => '20:00:00',
            'hora_fin' => '02:30:00',
            'cruza_medianoche' => true,
            'activo' => true,
        ]);

        $almuerzo->usuarios()->attach([$usuarios['mesero']->id, $usuarios['cocinero']->id, $usuarios['administradora']->id]);
        $cena->usuarios()->attach([$usuarios['mesero']->id, $usuarios['mesera_noche']->id, $usuarios['cocinero']->id, $usuarios['jefe_cocina']->id, $usuarios['cajero']->id]);
        $noche->usuarios()->attach([$usuarios['mesera_noche']->id, $usuarios['cocinero']->id, $usuarios['jefe_cocina']->id]);
    }

    /** @return array<string, Estacion> */
    private function crearEstaciones(string $restauranteId): array
    {
        $estaciones = [];

        // Los tres tiempos objetivo son distintos a proposito: si todos fueran
        // 15 minutos, el ejemplo no mostraria por que separar estaciones.
        $estaciones['cocina'] = Estacion::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Cocina caliente',
            'tipo' => TipoEstacion::Comida->value,
            'tiempo_objetivo_segundos' => 900,
            'activo' => true,
        ]);

        $estaciones['barra'] = Estacion::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Barra',
            'tipo' => TipoEstacion::Bebida->value,
            'tiempo_objetivo_segundos' => 240,
            'activo' => true,
        ]);

        $estaciones['postres'] = Estacion::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Postres',
            'tipo' => TipoEstacion::Postura->value,
            'tiempo_objetivo_segundos' => 420,
            'activo' => true,
        ]);

        return $estaciones;
    }

    /**
     * @return array<string, GrupoMesas>
     */
    private function crearSalon(string $restauranteId): array
    {
        $terraza = Zona::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Terraza', 'orden' => 1, 'activo' => true,
        ]);

        $salon = Zona::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Salón', 'orden' => 2, 'activo' => true,
        ]);

        $grupos = [];

        // Terraza: cuatro mesas de dos.
        for ($indice = 1; $indice <= 4; $indice++) {
            $mesa = $this->crearMesa($restauranteId, $terraza->id, 'T' . $indice, 2, $indice * 120, 60);
            $grupos['terraza_' . $indice] = $this->crearGrupoDeUnaMesa($restauranteId, $mesa);
        }

        // Salon: ocho mesas de cuatro. Mesas 1 a 8.
        for ($indice = 1; $indice <= 8; $indice++) {
            $mesa = $this->crearMesa($restauranteId, $salon->id, (string) $indice, 4, $indice * 120, 400);
            $grupos['mesa_' . $indice] = $this->crearGrupoDeUnaMesa($restauranteId, $mesa);
        }

        return $grupos;
    }

    private function crearMesa(string $restauranteId, int $zonaId, string $nombre, int $capacidad, int $x, int $y): Mesa
    {
        return Mesa::query()->create([
            'tenant_id' => $restauranteId,
            'zona_id' => $zonaId,
            'nombre' => $nombre,
            'capacidad' => $capacidad,
            'estado' => EstadoMesa::Libre->value,
            'posicion_x' => $x,
            'posicion_y' => $y,
            'activo' => true,
        ]);
    }

    /**
     * Toda mesa se agrupa en un grupo de un solo miembro.
     *
     * Es la base de la decision de modelo: la comanda cuelga del grupo, nunca de
     * la mesa, y asi una mesa combinada es un grupo de dos y no necesita ningun
     * camino de codigo distinto.
     */
    private function crearGrupoDeUnaMesa(string $restauranteId, Mesa $mesa): GrupoMesas
    {
        $grupo = GrupoMesas::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Mesa ' . $mesa->nombre,
            'es_combinacion' => false,
        ]);

        $grupo->mesas()->attach($mesa->id);

        return $grupo;
    }

    private function crearCatalogo(string $restauranteId, array $estaciones): void
    {
        $entradas = CategoriaProducto::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Entradas', 'orden' => 1, 'activo' => true,
        ]);

        $principales = CategoriaProducto::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Principales', 'orden' => 2, 'activo' => true,
        ]);

        $bebidas = CategoriaProducto::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Bebidas', 'orden' => 3, 'activo' => true,
        ]);

        $postres = CategoriaProducto::query()->create([
            'tenant_id' => $restauranteId, 'nombre' => 'Postres', 'orden' => 4, 'activo' => true,
        ]);

        // Ingredientes. La cebolla existe para que "sin cebolla" tenga sentido.
        $ingredientes = [];

        foreach ([
            'carne' => ['Carne de res', 'gramo'],
            'cebollas' => ['Cebolla', 'gramo'],
            'papas' => ['Papas', 'gramo'],
            'queso' => ['Queso gauda', 'gramo'],
            'masa' => ['Masa', 'gramo'],
        ] as $clave => [$nombre, $unidad]) {
            $ingredientes[$clave] = Ingrediente::query()->create([
                'tenant_id' => $restauranteId,
                'nombre' => $nombre,
                'unidad' => $unidad,
                'alergenos' => $clave === 'queso' ? ['lacteos'] : [],
                'activo' => true,
            ]);
        }

        $grupos = $this->crearGruposModificadores($restauranteId, $ingredientes);

        // ------------------------------------------------------------------
        // Productos. Las bebidas van a barra con objetivo de 4 minutos; los
        // principales a cocina con 15. El mismo modelo, dos realidades.
        // ------------------------------------------------------------------
        $empanadas = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $entradas->id,
            'codigo' => 'ENT-001',
            'nombre' => 'Empanadas de pino',
            'descripcion' => 'Seis unidades.',
            'precio' => 5900,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Comida->value,
            'estacion_id' => $estaciones['cocina']->id,
            'tiempo_objetivo_segundos' => 600,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $lomo = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $principales->id,
            'codigo' => 'PRI-001',
            'nombre' => 'Lomo saltado',
            'descripcion' => 'Con papas y arroz.',
            'precio' => 8900,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Comida->value,
            'estacion_id' => $estaciones['cocina']->id,
            'tiempo_objetivo_segundos' => null,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $pescado = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $principales->id,
            'codigo' => 'PRI-002',
            'nombre' => 'Pescado a la plancha',
            'precio' => 9500,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Comida->value,
            'estacion_id' => $estaciones['cocina']->id,
            'tiempo_objetivo_segundos' => null,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $limonada = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $bebidas->id,
            'codigo' => 'BEB-001',
            'nombre' => 'Limonada',
            'precio' => 2500,
            'unidad' => 'vaso',
            'tipo_linea' => TipoLinea::Bebida->value,
            'estacion_id' => $estaciones['barra']->id,
            'tiempo_objetivo_segundos' => null,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $vino = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $bebidas->id,
            'codigo' => 'BEB-002',
            'nombre' => 'Vino tinto reserva',
            'precio' => 4900,
            'unidad' => 'copa',
            'tipo_linea' => TipoLinea::Bebida->value,
            'estacion_id' => $estaciones['barra']->id,
            'tiempo_objetivo_segundos' => null,
            'acepta_modificadores' => false,
            'activo' => true,
        ]);

        $flan = Producto::query()->create([
            'tenant_id' => $restauranteId,
            'categoria_productos_id' => $postres->id,
            'codigo' => 'POS-001',
            'nombre' => 'Flan con dulce de leche',
            'precio' => 2900,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Postre->value,
            'estacion_id' => $estaciones['postres']->id,
            'tiempo_objetivo_segundos' => null,
            'acepta_modificadores' => false,
            'activo' => true,
        ]);

        // ------------------------------------------------------------------
        // Recetas. Sirven para validar que "sin cebolla" tenga sentido.
        // ------------------------------------------------------------------
        foreach ([
            [$empanadas, ['masa' => 400, 'carne' => 250]],
            [$lomo, ['carne' => 220, 'papas' => 180, 'cebollas' => 80]],
            [$pescado, ['carne' => 0, 'papas' => 150]],
        ] as [$producto, $composicion]) {
            foreach ($composicion as $claveIngrediente => $cantidad) {
                if ($cantidad <= 0) {
                    continue;
                }

                RecetaIngrediente::query()->create([
                    'tenant_id' => $restauranteId,
                    'producto_id' => $producto->id,
                    'ingrediente_id' => $ingredientes[$claveIngrediente]->id,
                    'cantidad' => $cantidad,
                    'unidad' => 'gramo',
                ]);
            }
        }

        // ------------------------------------------------------------------
        // Vinculos producto <-> grupos de modificadores.
        // ------------------------------------------------------------------
        foreach ([$empanadas, $lomo] as $producto) {
            ProductoGrupoModificadores::query()->create([
                'tenant_id' => $restauranteId,
                'producto_id' => $producto->id,
                'grupos_modificadores_id' => $grupos['preferencias']->id,
                'obligatorio' => false,
                'orden' => 1,
            ]);

            ProductoGrupoModificadores::query()->create([
                'tenant_id' => $restauranteId,
                'producto_id' => $producto->id,
                'grupos_modificadores_id' => $grupos['agregados']->id,
                'obligatorio' => false,
                'orden' => 2,
            ]);
        }

        // El termino de la carne es obligatorio SOLO para el lomo. El mismo
        // grupo, con la obligatoriedad resuelta por el vinculo y no duplicando
        // el grupo.
        ProductoGrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'producto_id' => $lomo->id,
            'grupos_modificadores_id' => $grupos['termino']->id,
            'obligatorio' => true,
            'orden' => 0,
        ]);

        ProductoGrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'producto_id' => $pescado->id,
            'grupos_modificadores_id' => $grupos['preferencias']->id,
            'obligatorio' => false,
            'orden' => 1,
        ]);

        ProductoGrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'producto_id' => $limonada->id,
            'grupos_modificadores_id' => $grupos['servicio_bebida']->id,
            'obligatorio' => false,
            'orden' => 1,
        ]);
    }

    /**
     * Los grupos de modificadores del ejemplo.
     *
     * @param  array<string, Ingrediente>  $ingredientes
     * @return array<string, \App\Models\GrupoModificadores>
     */
    private function crearGruposModificadores(string $restauranteId, array $ingredientes): array
    {
        $grupos = [];

        // Eleccion unica y obligatoria: el termino de la carne.
        $grupos['termino'] = \App\Models\GrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Término de la carne',
            'seleccion' => 'unica',
            'obligatorio' => true,
            'orden' => 0,
            'activo' => true,
        ]);

        foreach (['Al punto', 'Tres cuartos', 'Cocido'] as $posicion => $nombre) {
            Modificador::query()->create([
                'tenant_id' => $restauranteId,
                'grupos_modificadores_id' => $grupos['termino']->id,
                'nombre' => $nombre,
                'tipo' => TipoModificador::Cambio->value,
                'precio' => 0,
                'ingrediente_id' => $ingredientes['carne']->id,
                'orden' => $posicion + 1,
                'activo' => true,
            ]);
        }

        // Eleccion multiple: cosas que se quitan, sin costo.
        $grupos['preferencias'] = \App\Models\GrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Sin ingredientes',
            'seleccion' => 'multiple',
            'obligatorio' => false,
            'orden' => 1,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['preferencias']->id,
            'nombre' => 'Sin cebolla',
            'tipo' => TipoModificador::Remocion->value,
            'precio' => 0,
            'ingrediente_id' => $ingredientes['cebollas']->id,
            'orden' => 1,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['preferencias']->id,
            'nombre' => 'Sin papas',
            'tipo' => TipoModificador::Remocion->value,
            'precio' => 0,
            'ingrediente_id' => $ingredientes['papas']->id,
            'orden' => 2,
            'activo' => true,
        ]);

        // Eleccion multiple: cosas que se agregan y suman.
        $grupos['agregados'] = \App\Models\GrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Agregados',
            'seleccion' => 'multiple',
            'obligatorio' => false,
            'orden' => 2,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['agregados']->id,
            'nombre' => 'Extra queso',
            'tipo' => TipoModificador::Agregado->value,
            'precio' => 1500,
            'ingrediente_id' => $ingredientes['queso']->id,
            'orden' => 1,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['agregados']->id,
            'nombre' => 'Porción extra de papas',
            'tipo' => TipoModificador::Agregado->value,
            'precio' => 2500,
            'ingrediente_id' => $ingredientes['papas']->id,
            'orden' => 2,
            'activo' => true,
        ]);

        // De bebida: sin costo, pero kitchen lo tiene que ver igual.
        $grupos['servicio_bebida'] = \App\Models\GrupoModificadores::query()->create([
            'tenant_id' => $restauranteId,
            'nombre' => 'Servido',
            'seleccion' => 'multiple',
            'obligatorio' => false,
            'orden' => 1,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['servicio_bebida']->id,
            'nombre' => 'Sin hielo',
            'tipo' => TipoModificador::SinCosto->value,
            'precio' => 0,
            'ingrediente_id' => null,
            'orden' => 1,
            'activo' => true,
        ]);

        Modificador::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_modificadores_id' => $grupos['servicio_bebida']->id,
            'nombre' => 'Con Antoniuk',
            'tipo' => TipoModificador::SinCosto->value,
            'precio' => 0,
            'ingrediente_id' => null,
            'orden' => 2,
            'activo' => true,
        ]);

        return $grupos;
    }

    /**
     * Comanda abierta en la mesa 3, con cocina trabajando de verdad.
     *
     * Los estados de los items no son todos iguales a proposito: uno entregado,
     * uno en preparacion y uno recien enviado. Con eso la pantalla muestra
     * tiempos distintos en la misma vista, que es exactamente el caso que
     * justifica este sistema.
     */
    private function crearComandaDeEjemplo(string $restauranteId, GrupoMesas $grupo, Usuario $mesero): void
    {
        $comanda = Comanda::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_mesas_id' => $grupo->id,
            'folio' => 1,
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 4,
            'abierta_por_usuario_id' => $mesero->id,
            'notas' => 'Mesa de cuatro. Una persona vegetariana.',
            'abierta_at' => now()->subMinutes(28),
        ]);

        $ids = $grupo->mesas()->pluck('mesas.id');
        Mesa::query()->whereIn('id', $ids)->update(['estado' => EstadoMesa::Ocupada->value]);

        $this->crearItemEjemplo($comanda, 'ENT-001', 'Empanadas de pino', 2, 5900, 'enviado_a_cocina');
        $this->crearItemEjemplo($comanda, 'PRI-001', 'Lomo saltado', 2, 8900, 'en_preparacion');
        $this->crearItemEjemplo($comanda, 'PRI-002', 'Pescado a la plancha', 1, 9500, 'pendiente');
        $this->crearItemEjemplo($comanda, 'BEB-001', 'Limonada', 4, 2500, 'listo');
        $this->crearItemEjemplo($comanda, 'POS-001', 'Flan con dulce de leche', 2, 2900, 'pendiente');
    }

    private function crearItemEjemplo(
        Comanda $comanda,
        string $codigo,
        string $nombre,
        int $cantidad,
        int $precio,
        string $estado,
    ): void {
        $producto = Producto::query()->where('codigo', $codigo)->firstOrFail();

        $item = ComandaItem::query()->create([
            'tenant_id' => $comanda->tenant_id,
            'comanda_id' => $comanda->id,
            'producto_id' => $producto->id,
            'nombre_producto' => $nombre,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'notas_preparacion' => null,
            'estacion_id' => $producto->estacion_id,
            'tipo_linea' => $producto->tipo_linea->value,
            'tiempo_objetivo_segundos' => $producto->objetivoSegundos(),
            'estado' => $estado,
            'enviado_at' => in_array($estado, ['enviado_a_cocina', 'en_preparacion', 'listo'], true)
                ? now()->subMinutes(22)
                : null,
            'listo_at' => $estado === 'listo' ? now()->subMinutes(3) : null,
            'orden' => ComandaItem::query()->where('comanda_id', $comanda->id)->count() + 1,
        ]);
    }
}