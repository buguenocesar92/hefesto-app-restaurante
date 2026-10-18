<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\EstadoMesa;
use App\Enums\Rol;
use App\Models\GrupoMesas;
use App\Models\Mesa;
use App\Models\Restaurante;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Zona;
use App\Services\Tenancy\ContextoRestaurante;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas de la aplicacion de restaurante.
 *
 * POR QUE ESTAS PRUEBAS CORREN CONTRA POSTGRESQL Y NO CONTRA SQLITE
 * ---------------------------------------------------------------
 * La mitad de lo que hay que comprobar no existe en SQLite. La Row Level
 * Security es una funcion de PostgreSQL; una prueba de aislamiento corrida en
 * SQLite estaría probando que el WHERE existe, que no es lo mismo que probar
 * que la base no entrega filas ajenas. Por eso `phpunit.xml` fija `pgsql`, y
 * por eso la suite se salta con honestidad si no hay base disponible, en vez
 * de pasar en verde probando otra cosa.
 *
 * EL TRAMPA DEL CONTEXTO DE TENANT
 * --------------------------------
 * Autenticar con `actingAs()` NO fija el contexto de tenant en la base, porque
 * el middleware ResolverRestaurante corre dentro del grupo `web` y las pruebas
 * no ejecutan ese grupo. Si se olvidara, la RLS no tendria contra que filtrar y
 * las pruebas de aislamiento darían un falso positivo. Por eso `comoUsuario()`
 * autentica Y fija el contexto.
 *
 * Alcance de sesion contra alcance de transaccion: en la aplicacion real el
 * contexto se fija con alcance de TRANSACCION para que el worker de colas no
 * arrastre el tenant de otro restaurante. En las pruebas se usa alcance de
 * sesion, porque cada caso abre su propia conexion y porque las aserciones
 * corren FUERA de la transaccion del middleware: con alcance de transaccion,
 * el valor ya se habria perdido cuando llega la asercion.
 */
abstract class TestCase extends BaseTestCase
{
    /** Restaurante fijado para la prueba en curso. */
    protected ?string $restauranteDePrueba = null;

    /**
     * Autentica a una persona y fija su contexto de restaurante en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarRestaurante($usuario->tenant_id);
    }

    /** Emula lo que hace App\Http\Middleware\ResolverRestaurante. */
    protected function fijarRestaurante(?string $restauranteId): static
    {
        if ($restauranteId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoRestaurante::VARIABLE, $restauranteId]);

        $this->restauranteDePrueba = $restauranteId;

        return $this;
    }

    /**
     * Monta un restaurante completo y utilizable: zona, mesa, grupo, turno y
     * las cuatro personas que aparecen en las pruebas.
     *
     * @return array{
     *     restaurante: Restaurante,
     *     zona: Zona,
     *     mesa: Mesa,
     *     grupo: GrupoMesas,
     *     turno: Turno,
     *     administrador: Usuario,
     *     cajero: Usuario,
     *     mesero: Usuario,
     *     cocinero: Usuario
     * }
     */
    protected function crearRestaurante(string $nombre = 'La Vertiente'): array
    {
        $restaurante = Restaurante::factory()->create([
            'nombre' => $nombre,
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
        ]);

        $this->fijarRestaurante($restaurante->id);

        $zona = Zona::query()->create([
            'tenant_id' => $restaurante->id,
            'nombre' => 'Salón',
            'orden' => 1,
            'activo' => true,
        ]);

        $mesa = Mesa::query()->create([
            'tenant_id' => $restaurante->id,
            'zona_id' => $zona->id,
            'nombre' => '1',
            'capacidad' => 4,
            'estado' => EstadoMesa::Libre->value,
            'activo' => true,
        ]);

        $grupo = GrupoMesas::query()->create([
            'tenant_id' => $restaurante->id,
            'nombre' => 'Mesa 1',
            'es_combinacion' => false,
        ]);

        $grupo->mesas()->attach($mesa->id);

        // Turno que cubre todo el dia para que las pruebas de escritura no
        // choquen con el middleware de turno por la hora a la que corren.
        $turno = Turno::query()->create([
            'tenant_id' => $restaurante->id,
            'nombre' => 'Servicio',
            'hora_inicio' => '00:00:00',
            'hora_fin' => '23:59:59',
            'cruza_medianoche' => false,
            'activo' => true,
        ]);

        return [
            'restaurante' => $restaurante,
            'zona' => $zona,
            'mesa' => $mesa,
            'grupo' => $grupo,
            'turno' => $turno,
            'administrador' => $this->crearUsuario($restaurante, Rol::Administrador),
            'cajero' => $this->crearUsuario($restaurante, Rol::Cajero),
            'mesero' => $this->crearUsuario($restaurante, Rol::Mesero),
            'cocinero' => $this->crearUsuario($restaurante, Rol::Cocinero),
        ];
    }

    private function crearUsuario(Restaurante $restaurante, Rol $rol): Usuario
    {
        return Usuario::factory()->de($restaurante)->create([
            'rol' => $rol->value,
        ]);
    }
}