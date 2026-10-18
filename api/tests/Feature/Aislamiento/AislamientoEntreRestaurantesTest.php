<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Enums\EstadoComanda;
use App\Enums\Rol;
use App\Models\Comanda;
use App\Models\Producto;
use App\Services\Tenancy\ContextoRestaurante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AISLAMIENTO ENTRE RESTAURANTES.
 *
 * La pregunta que responde esta suite es la que el dueño de un restaurante se
 * haria si oyera que Hefesto instala esto en mas de un local: "el mesero de
 * La Vertiente puede ver las comandas del otro restaurante?".
 *
 * La respuesta tiene que ser no, y no porque el codigo que se escribio bien
 * filtro la condicion, sino porque hay una barrera que actua aunque alguien
 * olvide el filtro.
 *
 * HAY TRES BARRERAS Y SE COMPRUEBAN LAS TRES
 * ------------------------------------------
 *  1. La Row Level Security de PostgreSQL: se comprueba con SQL directo,
 *     saltandose el codigo de la aplicacion por completo. Es la prueba fuerte.
 *  2. El alcance global: un endpoint con un id de otro restaurante responde 404
 *     y no 403, porque un 403 confirmaria que el recurso existe.
 *  3. La escritura: no se puede crear una fila en nombre de otro restaurante.
 *     Sin `WITH CHECK`, un `INSERT` con el tenant equivocado pasaria la lectura
 *     y dejaria una fila invisible que nadie podria ver ni borrar.
 *
 * Requiere PostgreSQL de verdad. Ver la nota de `Tests\TestCase`.
 */
class AislamientoEntreRestaurantesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function la_base_no_devuelve_comandas_de_otro_restaurante_aunque_se_pregunte_en_sql(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $comandaDeA = $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);

        // Ahora el mismo consulta desde el contexto del restaurante B.
        $b = $this->crearRestaurante('El Muelle');

        $vistas = DB::table('comandas')->where('id', $comandaDeA->id)->count();

        $this->assertSame(
            0,
            $vistas,
            'PostgreSQL devolvio una comanda de otro restaurante. La RLS no esta actingiando.'
        );

        // Y desde el punto de vista de B, su propia lista esta vacia, no
        // mezclada.
        $this->assertSame(0, Comanda::query()->count());
    }

    #[Test]
    public function un_usuario_no_abre_una_comanda_de_otro_restaurante(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $comandaDeA = $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);

        $b = $this->crearRestaurante('El Muelle');

        $this->comoUsuario($b['mesero'])
            ->getJson('/api/comandas/' . $comandaDeA->id)
            ->assertNotFound();
    }

    #[Test]
    public function la_lista_de_comandas_de_un_restaurante_no_contiene_las_de_otro(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);

        $b = $this->crearRestaurante('El Muelle');
        $comandaDeB = $this->comandaDeEjemplo($b['grupo']->id, $b['restaurante']->id, $b['mesero']->id);

        $respuesta = $this->comoUsuario($b['mesero'])
            ->getJson('/api/comandas')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($comandaDeB->id, $respuesta->json('data.0.id'));
        $this->assertSame($b['restaurante']->id, $respuesta->json('data.0.tenant_id'));
    }

    #[Test]
    public function no_se_puede_escribir_una_fila_en_nombre_de_otro_restaurante(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $comandaDeA = $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);

        $b = $this->crearRestaurante('El Muelle');

        // Se intenta crear una comanda con el restaurante A escribiendo desde el
        // contexto de B. La politica lo tiene que impedir con WITH CHECK.
        $this->expectException(\Throwable::class);

        DB::table('comandas')->insert([
            'tenant_id' => $a['restaurante']->id,
            'grupos_mesas_id' => $a['grupo']->id,
            'folio' => 999,
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 2,
            'abierta_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function un_mesero_del_otro_restaurante_no_anula_un_item(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $comandaDeA = $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);
        $itemDeA = $comandaDeA->items()->firstOrFail();

        $b = $this->crearRestaurante('El Muelle');

        $this->comoUsuario($b['mesero'])
            ->postJson('/api/comandas/' . $comandaDeA->id . '/items/' . $itemDeA->id . '/anular', [
                'motivo' => 'El comensal se arrepintio de este plato',
            ])
            ->assertNotFound();

        // El item sigue vivo en su restaurante.
        $this->fijarRestaurante($a['restaurante']->id);
        $this->assertNotSame('anulado', $itemDeA->fresh()->estado->value);
    }

    #[Test]
    public function una_consulta_sin_contexto_de_restaurante_falla_en_lugar_de_devolver_cero_filas(): void
    {
        $a = $this->crearRestaurante('La Vertiente');
        $this->comandaDeEjemplo($a['grupo']->id, $a['restaurante']->id, $a['mesero']->id);

        // Se limpia el contexto a proposito, como si una consulta se escapara
        // del middleware.
        DB::select('SELECT set_config(?, ?, false)', [ContextoRestaurante::VARIABLE, '']);

        $this->expectException(\Throwable::class);

        DB::table('comandas')->count();
    }

    /**
     * @return Comanda
     */
    private function comandaDeEjemplo(int $grupoId, string $restauranteId, int $meseroId): Comanda
    {
        return Comanda::query()->create([
            'tenant_id' => $restauranteId,
            'grupos_mesas_id' => $grupoId,
            'folio' => 1,
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 2,
            'abierta_por_usuario_id' => $meseroId,
            'abierta_at' => now(),
        ]);
    }
}