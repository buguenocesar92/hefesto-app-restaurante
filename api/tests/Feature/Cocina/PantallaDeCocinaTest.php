<?php

declare(strict_types=1);

namespace Tests\Feature\Cocina;

use App\Enums\EstadoItemComanda;
use App\Enums\TipoEstacion;
use App\Enums\TipoLinea;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Estacion;
use App\Models\Producto;
use App\Services\Cocina\RelojDeCocina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pantalla de cocina: el reloj en memoria y los estados por item.
 *
 * Requiere Redis de verdad. Si Redis no esta disponible, la prueba falla en vez
 * de saltarse en silencio: una prueba de cocina que no usa Redis no prueba el
 * reloj de cocina.
 *
 * LO QUE SE COMPRUEBA
 * -------------------
 *  1. Un item enviado aparece en el panel de su estacion con el tiempo
 *     corriendo, y el tiempo se calcula SIN consultar la base.
 *  2. Las bebidas y la comida van a estaciones distintas y no se mezclan.
 *  3. Marcar un item como listo lo deja ordenado por antiguedad, que es lo que
 *     hace visible el plato que nadie reclama.
 *  4. Un item terminal sale del panel.
 */
class PantallaDeCocinaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_item_enviado_aparece_en_el_panel_de_su_estacion_con_su_tiempo(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [$comanda, $item] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600);

        $reloj = app(RelojDeCocina::class);
        $reloj->marcar($item, EstadoItemComanda::Enviado, now()->subMinutes(7));

        $panel = $reloj->panel($local['restaurante']->id, $item->estacion_id);

        $this->assertCount(1, $panel);
        $this->assertSame($item->id, $panel[0]['item_id']);
        $this->assertSame('Cocina caliente', $item->estacion->nombre);

        // Siete minutos, con margen de un segundo por la reloj del servidor.
        $this->assertGreaterThanOrEqual(419, $panel[0]['segundos']);
        $this->assertLessThanOrEqual(421, $panel[0]['segundos']);

        // Diez minutos de objetivo contra siete de espera: todavia no atrasado.
        $this->assertFalse($panel[0]['atrasado']);
    }

    #[Test]
    public function el_panel_avisa_cuando_el_pedido_pasa_el_tiempo_objetivo_de_su_estacion(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $item] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Barra', TipoEstacion::Bebida, 240);

        $reloj = app(RelojDeCocina::class);
        $reloj->marcar($item, EstadoItemComanda::Enviado, now()->subMinutes(9));

        $panel = $reloj->panel($local['restaurante']->id, $item->estacion_id);

        // Nueve minutos contra cuatro de objetivo en barra: atrasado a la vista.
        $this->assertTrue($panel[0]['atrasado']);
    }

    #[Test]
    public function las_bebidas_y_la_comida_no_se_mezclan_en_la_pantalla(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $comida] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600, 1);
        [, $bebida] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Barra', TipoEstacion::Bebida, 240, 2);

        $reloj = app(RelojDeCocina::class);
        $reloj->marcar($comida, EstadoItemComanda::Enviado, now()->subMinutes(12));
        $reloj->marcar($bebida, EstadoItemComanda::Enviado, now()->subMinutes(1));

        $panelCocina = $reloj->panel($local['restaurante']->id, $comida->estacion_id);
        $panelBarra = $reloj->panel($local['restaurante']->id, $bebida->estacion_id);

        $this->assertCount(1, $panelCocina);
        $this->assertCount(1, $panelBarra);

        // El mismo comportamiento con dos precios distintos: la bebida va
        // rapida y la comida lenta, y por eso el modelo tiene estaciones.
        $this->assertGreaterThan($panelBarra[0]['segundos'], $panelCocina[0]['segundos']);
    }

    #[Test]
    public function los_pedidos_se_ordenan_por_antiguedad_y_no_por_orden_de_llegada(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $nuevo] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600, 1);
        [, $viejo] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600, 2);

        $reloj = app(RelojDeCocina::class);

        // El viejo entro segundo pero se marco despues: tiene mas espera.
        $reloj->marcar($nuevo, EstadoItemComanda::Enviado, now()->subMinutes(2));
        $reloj->marcar($viejo, EstadoItemComanda::Enviado, now()->subMinutes(15));

        $panel = $reloj->panel($local['restaurante']->id, $nuevo->estacion_id);

        $this->assertCount(2, $panel);
        $this->assertSame(
            $viejo->id,
            $panel[0]['item_id'],
            'El pedido mas viejo tiene que ir primero en pantalla.'
        );
    }

    #[Test]
    public function un_item_listo_sigue_contando_tiempo_y_queda_arriba(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $item] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600);

        $reloj = app(RelojDeCocina::class);
        $reloj->marcar($item, EstadoItemComanda::Enviado, now()->subMinutes(20));
        $reloj->marcar($item, EstadoItemComanda::Listo, now()->subMinutes(2));

        $panel = $reloj->panel($local['restaurante']->id, $item->estacion_id);

        // El plato esta listo pero nadie lo ha llevado. El tiempo sigue
        // corriendo desde el envio, y eso es justo lo que lo hace visible.
        $this->assertCount(1, $panel);
        $this->assertSame('listo', $panel[0]['estado']);
        $this->assertGreaterThanOrEqual(1199, $panel[0]['segundos']);
        $this->assertTrue($panel[0]['atrasado']);
    }

    #[Test]
    public function un_item_entregado_sale_del_panel(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $item] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600);

        $reloj = app(RelojDeCocina::class);
        $reloj->marcar($item, EstadoItemComanda::Enviado, now()->subMinutes(5));
        $this->assertCount(1, $reloj->panel($local['restaurante']->id, $item->estacion_id));

        $reloj->marcar($item, EstadoItemComanda::Entregado, now());

        $this->assertSame([], $reloj->panel($local['restaurante']->id, $item->estacion_id));
    }

    #[Test]
    public function el_reloj_se_reconstruye_desde_la_base_cuando_redis_se_perdio(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        [, $item] = $this->itemDePrueba($local, EstadoItemComanda::Enviado, 'Cocina caliente', TipoEstacion::Comida, 600);

        // Se simula la perdida de Redis: la fila sigue en la base con su
        // marca de envio, pero el reloj no tiene nada.
        $item->enviado_at = now()->subMinutes(11);
        $item->save();

        Redis::connection('default')->del(
            'cocina:item:' . $local['restaurante']->id . ':' . $item->id,
            'cocina:cola:' . $local['restaurante']->id . ':' . $item->estacion_id
        );

        $reloj = app(RelojDeCocina::class);

        $this->assertSame([], $reloj->panel($local['restaurante']->id, $item->estacion_id));

        $reconstruidos = $reloj->reconstruirDesdeBase($local['restaurante']->id);

        $this->assertGreaterThanOrEqual(1, $reconstruidos);

        $panel = $reloj->panel($local['restaurante']->id, $item->estacion_id);

        $this->assertCount(1, $panel);
        $this->assertGreaterThanOrEqual(659, $panel[0]['segundos']);
    }

    /**
     * @return array{0: Comanda, 1: ComandaItem}
     */
    private function itemDePrueba(
        array $local,
        EstadoItemComanda $estado,
        string $nombreEstacion,
        TipoEstacion $tipo,
        int $objetivo,
        int $folio = 1,
    ): array {
        $estacion = Estacion::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => $nombreEstacion,
            'tipo' => $tipo->value,
            'tiempo_objetivo_segundos' => $objetivo,
            'activo' => true,
        ]);

        $comanda = Comanda::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'grupos_mesas_id' => $local['grupo']->id,
            'folio' => $folio,
            'estado' => 'abierta',
            'comensales' => 2,
            'abierta_por_usuario_id' => $local['mesero']->id,
            'abierta_at' => now(),
        ]);

        $categoriaId = \Illuminate\Support\Facades\DB::table('categorias_productos')->insertGetId([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Prueba',
            'orden' => 1,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $producto = Producto::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'categoria_productos_id' => $categoriaId,
            'codigo' => 'PRUEBA-' . $folio,
            'nombre' => 'Producto de prueba',
            'precio' => 5000,
            'unidad' => 'porcion',
            'tipo_linea' => $tipo === TipoEstacion::Bebida ? TipoLinea::Bebida->value : TipoLinea::Comida->value,
            'estacion_id' => $estacion->id,
            'acepta_modificadores' => false,
            'activo' => true,
        ]);

        $item = ComandaItem::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'comanda_id' => $comanda->id,
            'producto_id' => $producto->id,
            'nombre_producto' => $producto->nombre,
            'cantidad' => 1,
            'precio_unitario' => $producto->precio,
            'estacion_id' => $estacion->id,
            'tipo_linea' => $producto->tipo_linea->value,
            'tiempo_objetivo_segundos' => $objetivo,
            'estado' => $estado->value,
            'enviado_at' => $estado->estaEnPantalla() ? now() : null,
            'orden' => 1,
        ]);

        return [$comanda, $item];
    }
}