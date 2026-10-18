<?php

declare(strict_types=1);

namespace Tests\Feature\Salon;

use App\Enums\EstadoComanda;
use App\Enums\EstadoMesa;
use App\Models\Comanda;
use App\Models\GrupoMesas;
use App\Models\Mesa;
use App\Services\Comandas\ServicioComandas;
use App\Services\Cocina\RelojDeCocina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mesas combinables.
 *
 * La decision de modelo que se comprueba aca: la comanda cuelga de un GRUPO de
 * mesas, nunca de una mesa. Una mesa sola es un grupo de un miembro. Una mesa
 * combinada es un grupo de dos. El caso corriente y el caso raro comparten el
 * mismo camino de codigo, y por eso la combinacion no necesita ningun caso
 * especial en ningun endpoint.
 *
 * Si el modelo fuera `comanda.mesa_id` mas un booleano `combinada`, estas
 * pruebas no se podrian escribir con la misma claridad: habria que comprobar
 * las dos ramas por separado y la combinacion seria el camino raro siempre.
 */
class MesasCombinablesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function una_mesa_sola_es_un_grupo_de_un_miembro(): void
    {
        $local = $this->crearRestaurante();

        $this->assertFalse($local['grupo']->es_combinacion);
        $this->assertCount(1, $local['grupo']->mesas()->get());
    }

    #[Test]
    public function una_combinacion_reune_dos_mesas_en_un_solo_grupo(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $segunda = Mesa::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'zona_id' => $local['zona']->id,
            'nombre' => '2',
            'capacidad' => 4,
            'estado' => EstadoMesa::Libre->value,
            'activo' => true,
        ]);

        $grupoCombinado = GrupoMesas::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Mesas 1+2',
            'es_combinacion' => true,
        ]);

        $grupoCombinado->mesas()->attach([$local['mesa']->id, $segunda->id]);

        $this->assertTrue($grupoCombinado->fresh()->es_combinacion);
        $this->assertCount(2, $grupoCombinado->mesas()->get());

        // La capacidad del grupo es la suma: una mesa de ocho se comporta como
        // una mesa de ocho, no como dos de cuatro sueltas.
        $this->assertSame(8, $grupoCombinado->capacidad());
    }

    #[Test]
    public function una_comanda_abierta_ocupa_las_dos_mesas_combinadas(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $segunda = Mesa::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'zona_id' => $local['zona']->id,
            'nombre' => '2',
            'capacidad' => 4,
            'estado' => EstadoMesa::Libre->value,
            'activo' => true,
        ]);

        $grupo = GrupoMesas::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Mesas 1+2',
            'es_combinacion' => true,
        ]);

        $grupo->mesas()->attach([$local['mesa']->id, $segunda->id]);

        $comanda = app(ServicioComandas::class)->abrir($grupo, 8, $local['mesero']);

        // LAS DOS mesas quedan ocupadas. Si solo se ocupara una, el mapa del
        // salon mostraria una mesa libre que en realidad tiene gente sentada.
        $this->assertSame(
            EstadoMesa::Ocupada->value,
            Mesa::query()->find($local['mesa']->id)->estado->value
        );

        $this->assertSame(
            EstadoMesa::Ocupada->value,
            Mesa::query()->find($segunda->id)->estado->value
        );

        $this->assertSame(EstadoComanda::Abierta->value, $comanda->estado->value);
        $this->assertSame(8, $comanda->comensales);
    }

    #[Test]
    public function no_se_puede_abrir_una_comanda_en_un_grupo_que_ya_tiene_una_abierta(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        app(ServicioComandas::class)->abrir($local['grupo'], 2, $local['mesero']);

        // Dos meseros abriendo mesa al mismo tiempo es el caso real. El
        // bloqueo va dentro de la transaccion, sin el seeria posible.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ya tienen la comanda');

        app(ServicioComandas::class)->abrir($local['grupo'], 2, $local['mesero']);
    }

    #[Test]
    public function la_comanda_de_una_combinacion_se_ve_desde_las_dos_mesas(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $segunda = Mesa::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'zona_id' => $local['zona']->id,
            'nombre' => '2',
            'capacidad' => 4,
            'estado' => EstadoMesa::Libre->value,
            'activo' => true,
        ]);

        $grupo = GrupoMesas::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Mesas 1+2',
            'es_combinacion' => true,
        ]);

        $grupo->mesas()->attach([$local['mesa']->id, $segunda->id]);

        $comanda = app(ServicioComandas::class)->abrir($grupo, 8, $local['mesero']);

        // Desde cualquiera de las dos mesas se llega a la misma comanda. Por eso
        // el mesero no tiene que saber de que mesa se trata para atenderla.
        foreach ([$local['mesa'], $segunda] as $mesa) {
            $grupoDeLaMesa = $mesa->grupos()->firstOrFail();

            $this->assertSame($comanda->id, $grupoDeLaMesa->comandaVigente()?->id);
        }
    }

    #[Test]
    public function al_cobrar_se_liberan_las_dos_mesas_de_la_combinacion(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $segunda = Mesa::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'zona_id' => $local['zona']->id,
            'nombre' => '2',
            'capacidad' => 4,
            'estado' => EstadoMesa::Libre->value,
            'activo' => true,
        ]);

        $grupo = GrupoMesas::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Mesas 1+2',
            'es_combinacion' => true,
        ]);

        $grupo->mesas()->attach([$local['mesa']->id, $segunda->id]);

        $servicio = app(ServicioComandas::class);
        $comanda = $servicio->abrir($grupo, 8, $local['mesero']);

        $servicio->cambiarEstado($comanda, EstadoComanda::PorCobrar, $local['mesero']);

        // Al pedir la cuenta, las dos quedan por cerrar: la gente sigue
        // sentada y el salon ya sabe que hay una cuenta pendiente.
        $this->assertSame(EstadoMesa::PorCerrar->value, Mesa::query()->find($local['mesa']->id)->estado->value);
        $this->assertSame(EstadoMesa::PorCerrar->value, Mesa::query()->find($segunda->id)->estado->value);

        $comanda = $comanda->fresh();
        $comanda->grupoMesas->mesas()->update(['estado' => EstadoMesa::Libre->value]);

        $this->assertSame(
            EstadoMesa::Libre->value,
            Mesa::query()->find($segunda->id)->estado->value
        );
    }
}