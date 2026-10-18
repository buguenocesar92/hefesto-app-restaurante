<?php

declare(strict_types=1);

namespace Tests\Feature\Comandas;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Enums\Rol;
use App\Enums\TipoEstacion;
use App\Enums\TipoLinea;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Estacion;
use App\Models\Producto;
use App\Models\Usuario;
use App\Policies\ComandaPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UN MESERO NO PUEDE ANULAR UNA COMANDA YA COBRADA.
 *
 * Esta es la segunda prueba prioritaria, y es la que el dueño del restaurante
 * entiende sin necesidad de explicacion: si alguien puede borrar el registro
 * de una venta ya cobrada, el sistema no sirve para cobrar.
 *
 * POR QUE LA REGLA ES "PARA NINGUN ROL", INCLUIDO EL ADMINISTRADOR
 * --------------------------------------------------------------
 * Un cobro ya realizado se revierte en el punto de venta que lo emitio, con su
 * propia autorizacion y su propia bitacora. Si esta aplicacion permitiera
 * anular una comanda cobrada, estariamos inventando una devolucion que nadie
 * registro y que no dejaria rastro en el sistema que emitio la boleta. Es
 * peor que no permitir nada.
 *
 * El mensaje de error es el mismo para todos, a proposito: que el mensaje
 * cambie segun quien pregunta convierte un 403 en un oraculo para Averiguar
 * que comandas estan cobradas.
 *
 * LA MAQUINA DE ESTADOS TAMBIEN CIERRA
 * ------------------------------------
 * Aun con permiso, la transicion `cobrada -> anulada` no existe en
 * MaquinaDeEstadosComanda. Las dos barreras estan porque cada una falla de un
 * modo distinto y una sola no basta.
 */
class NoSeAnulaComandaCobradaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_mesero_no_puede_anular_una_comanda_cobrada(): void
    {
        $local = $this->crearRestaurante();
        $comanda = $this->comandaCobrada($local);

        $this->comoUsuario($local['mesero'])
            ->postJson('/api/comandas/' . $comanda->id . '/anular', [
                'motivo' => 'El comensal dice que no Pedio este plato',
            ])
            ->assertForbidden();

        $this->fijarRestaurante($local['restaurante']->id);
        $this->assertSame(
            EstadoComanda::Cobrada->value,
            $comanda->fresh()->estado->value,
            'La comanda quedo anulada: el cobro se perdio.'
        );
    }

    #[Test]
    public function ni_siquiera_el_administrador_puede_anular_una_comanda_cobrada(): void
    {
        $local = $this->crearRestaurante();
        $comanda = $this->comandaCobrada($local);

        $this->comoUsuario($local['administrador'])
            ->postJson('/api/comandas/' . $comanda->id . '/anular', [
                'motivo' => 'Correccion administrativa interna',
            ])
            ->assertForbidden();

        $this->fijarRestaurante($local['restaurante']->id);
        $this->assertSame(EstadoComanda::Cobrada->value, $comanda->fresh()->estado->value);
    }

    #[Test]
    public function la_policy_rechaza_la_anulacion_antes_de_mirar_el_rol(): void
    {
        $local = $this->crearRestaurante();
        $comanda = $this->comandaCobrada($local);

        $policy = new ComandaPolicy();

        // Se comprueba rol por rol, con una persona real de cada rol. La regla
        // no tiene excepciones, y eso es lo que la hace segura: no depende de
        // que alguien recuerde excluir un rol.
        foreach ([Rol::Mesero, Rol::Cajero, Rol::Cocinero, Rol::JefeCocina, Rol::Administrador] as $rol) {
            $persona = Usuario::factory()->de($local['restaurante'])->create([
                'rol' => $rol->value,
            ]);

            $this->assertFalse(
                $policy->anular($persona, $comanda),
                'El rol ' . $rol->value . ' no deberia poder anular una comanda cobrada.'
            );
        }
    }

    #[Test]
    public function un_mesero_no_puede_anular_un_item_de_una_comanda_cobrada(): void
    {
        $local = $this->crearRestaurante();
        $comanda = $this->comandaCobrada($local, conItemPendiente: true);

        $item = $comanda->items()->firstOrFail();

        $this->comoUsuario($local['mesero'])
            ->postJson('/api/comandas/' . $comanda->id . '/items/' . $item->id . '/anular', [
                'motivo' => 'Se pidio anular despues de cobrar',
            ])
            ->assertForbidden();

        $this->fijarRestaurante($local['restaurante']->id);
        $this->assertSame(EstadoItemComanda::Pendiente->value, $item->fresh()->estado->value);
    }

    #[Test]
    public function una_comanda_abierta_si_se_puede_anular(): void
    {
        $local = $this->crearRestaurante();

        $comanda = Comanda::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'grupos_mesas_id' => $local['grupo']->id,
            'folio' => 50,
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 2,
            'abierta_por_usuario_id' => $local['mesero']->id,
            'abierta_at' => now(),
        ]);

        // La contraprueba: si la regla fuera "nunca se anula", esta prueba
        // pasaria por la razon equivocada. Una comanda abierta se anula sin
        // problema, y el caso de la cobrada es el que se bloquea.
        $this->comoUsuario($local['mesero'])
            ->postJson('/api/comandas/' . $comanda->id . '/anular', [
                'motivo' => 'El cliente se retiro antes de pedir',
            ])
            ->assertOk();

        $this->fijarRestaurante($local['restaurante']->id);
        $this->assertSame(EstadoComanda::Anulada->value, $comanda->fresh()->estado->value);
    }

    #[Test]
    public function una_comanda_por_cobrar_todavia_se_puede_anular(): void
    {
        $local = $this->crearRestaurante();

        $comanda = Comanda::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'grupos_mesas_id' => $local['grupo']->id,
            'folio' => 51,
            'estado' => EstadoComanda::PorCobrar->value,
            'comensales' => 2,
            'abierta_por_usuario_id' => $local['mesero']->id,
            'abierta_at' => now(),
            'cerrada_at' => now(),
        ]);

        $this->comoUsuario($local['mesero'])
            ->postJson('/api/comandas/' . $comanda->id . '/anular', [
                'motivo' => 'Se determino que la comanda fue de otra mesa',
            ])
            ->assertOk();
    }

    /**
     * Comanda ya cobrada, que es el estado que hay que proteger.
     *
     * @return Comanda
     */
    private function comandaCobrada(array $local, bool $conItemPendiente = false): Comanda
    {
        $comanda = Comanda::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'grupos_mesas_id' => $local['grupo']->id,
            'folio' => 100,
            'estado' => EstadoComanda::Cobrada->value,
            'comensales' => 2,
            'abierta_por_usuario_id' => $local['mesero']->id,
            'abierta_at' => now()->subHour(),
            'cerrada_at' => now()->subMinutes(10),
            'cobrada_at' => now()->subMinutes(8),
        ]);

        if ($conItemPendiente) {
            $estacion = Estacion::query()->create([
                'tenant_id' => $local['restaurante']->id,
                'nombre' => 'Cocina caliente',
                'tipo' => TipoEstacion::Comida->value,
                'tiempo_objetivo_segundos' => 900,
                'activo' => true,
            ]);

            $categoriaId = \DB::table('categorias_productos')->insertGetId([
                'tenant_id' => $local['restaurante']->id,
                'nombre' => 'Entradas',
                'orden' => 1,
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $producto = Producto::query()->create([
                'tenant_id' => $local['restaurante']->id,
                'categoria_productos_id' => $categoriaId,
                'codigo' => 'ENT-TEST',
                'nombre' => 'Empanadas de pino',
                'precio' => 5900,
                'unidad' => 'porcion',
                'tipo_linea' => TipoLinea::Comida->value,
                'estacion_id' => $estacion->id,
                'acepta_modificadores' => true,
                'activo' => true,
            ]);

            ComandaItem::query()->create([
                'tenant_id' => $local['restaurante']->id,
                'comanda_id' => $comanda->id,
                'producto_id' => $producto->id,
                'nombre_producto' => $producto->nombre,
                'cantidad' => 1,
                'precio_unitario' => $producto->precio,
                'estacion_id' => $estacion->id,
                'tipo_linea' => $producto->tipo_linea->value,
                'tiempo_objetivo_segundos' => 900,
                'estado' => EstadoItemComanda::Pendiente->value,
                'orden' => 1,
            ]);
        }

        return $comanda;
    }
}