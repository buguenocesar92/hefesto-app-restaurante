<?php

declare(strict_types=1);

namespace Tests\Feature\Cuentas;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Enums\EstadoMesa;
use App\Enums\FormaPago;
use App\Enums\TipoEstacion;
use App\Enums\TipoLinea;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Cuenta;
use App\Models\Estacion;
use App\Models\Mesa;
use App\Models\Producto;
use App\Services\Comandas\ServicioComandas;
use App\Services\Cuentas\ServicioCuentas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Division de la cuenta entre comensales.
 *
 * El caso que importa es el redondeo. Con precios en pesos enteros, dividir
 * 10.000 entre 3 da 3.333,33 y el total no calza. El reparto tiene que dar
 * exactamente el total, con la diferencia de un peso por parte, porque ese
 * peso si no lo pierde el mesero de su bolsillo.
 */
class DivisionDeCuentaTest extends TestCase
{
    use RefreshDatabase;

    private ServicioCuentas $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioCuentas::class);
    }

    #[Test]
    public function la_suma_de_las_partes_es_exactamente_el_total(): void
    {
        $cuenta = $this->cuentaConTotal(10000);

        $this->servicio->dividir($cuenta, 3);

        $this->assertSame(10000, (int) $cuenta->divisiones()->sum('monto'));
        $this->assertCount(3, $cuenta->divisiones()->get());
    }

    #[Test]
    public function el_peso_sobrante_reparte_una_unidad_y_no_fraccion(): void
    {
        $cuenta = $this->cuentaConTotal(10000);

        $this->servicio->dividir($cuenta, 3);

        $montos = $cuenta->divisiones()->orderBy('parte')->pluck('monto')->all();

        $this->assertSame([3334, 3333, 3333], array_map('intval', $montos));
    }

    #[Test]
    public function un_total_no_divisible_se_reparte_sin_perder_pesos(): void
    {
        foreach ([[100, 3], [100, 7], [9901, 4], [1, 4]] as [$total, $partes]) {
            $cuenta = $this->cuentaConTotal($total);

            $this->servicio->dividir($cuenta, $partes);

            $this->assertSame(
                $total,
                (int) $cuenta->divisiones()->sum('monto'),
                'Un total de ' . $total . ' en ' . $partes . ' partes tiene que calzar exacto.'
            );
        }
    }

    #[Test]
    public function los_montos_personalizados_se_aceptan_si_calzan(): void
    {
        $cuenta = $this->cuentaConTotal(10000);

        $this->servicio->dividir($cuenta, 3, [5000, 3000, 2000]);

        $this->assertSame(10000, (int) $cuenta->divisiones()->sum('monto'));
    }

    #[Test]
    public function la_propina_se_suma_al_total_antes_de_repartir(): void
    {
        $cuenta = $this->cuentaConTotal(10000);

        $this->servicio->dividir($cuenta, 4, null, 2000);

        $cuenta->refresh();

        $this->assertSame(12000, $cuenta->total);
        $this->assertSame(12000, (int) $cuenta->divisiones()->sum('monto'));
    }

    #[Test]
    public function un_item_anulado_no_se_cobra(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $comanda = $this->comandaConItems($local, [
            ['nombre' => 'Lomo saltado', 'precio' => 8900, 'estado' => EstadoItemComanda::Entregado],
            ['nombre' => 'Empanadas', 'precio' => 5900, 'estado' => EstadoItemComanda::Anulado],
        ]);

        $cuenta = $this->servicio->abrirPara($comanda);

        // Solo cuenta lo que no se anulo.
        $this->assertSame(8900, $cuenta->subtotal);
    }

    #[Test]
    public function un_item_pendiente_tambien_se_cobra_porque_el_comensal_lo_pidio(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $comanda = $this->comandaConItems($local, [
            ['nombre' => 'Lomo saltado', 'precio' => 8900, 'estado' => EstadoItemComanda::Pendiente],
        ]);

        $cuenta = $this->servicio->abrirPara($comanda);

        $this->assertSame(8900, $cuenta->subtotal);
    }

    #[Test]
    public function cobrar_todas_las_partes_cierra_la_cuenta_y_libera_las_mesas(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $comanda = $this->comandaConItems($local, [
            ['nombre' => 'Lomo saltado', 'precio' => 8900, 'estado' => EstadoItemComanda::Entregado],
        ]);

        $this->servicio->dividir($this->servicio->abrirPara($comanda), 2);

        // La comanda tiene que estar por cobrar para que el cobro sea valido.
        app(ServicioComandas::class)->cambiarEstado($comanda, EstadoComanda::PorCobrar, $local['mesero']);

        $cuenta = $comanda->cuenta()->firstOrFail();

        $this->servicio->cobrar($cuenta, [], FormaPago::Efectivo, $local['cajero']);
        $this->servicio->cerrarCuenta($cuenta);

        $this->assertSame('cobrada', $cuenta->fresh()->estado);
        $this->assertSame(0, $cuenta->fresh()->saldo());

        // La mesa vuelve a estar libre. Este es el paso que, si falla, deja
        // el salon con una mesa ocupada que nadie ocupa.
        app(ServicioComandas::class)->cambiarEstado($comanda, EstadoComanda::Cobrada, $local['cajero']);

        $this->assertSame(
            EstadoMesa::Libre->value,
            Mesa::query()->find($local['mesa']->id)->estado->value
        );
    }

    #[Test]
    public function la_forma_de_pago_se_registra_y_la_referencia_del_pos_queda_vacia(): void
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        $comanda = $this->comandaConItems($local, [
            ['nombre' => 'Lomo saltado', 'precio' => 8900, 'estado' => EstadoItemComanda::Entregado],
        ]);

        $cuenta = $this->servicio->abrirPara($comanda);
        $this->servicio->dividir($cuenta, 1);

        $pagos = $this->servicio->cobrar($cuenta, [], FormaPago::TarjetaDebito, $local['cajero']);

        $this->assertCount(1, $pagos);
        $this->assertSame(FormaPago::TarjetaDebito, $pagos[0]->forma_pago);

        // La referencia del POS NO se inventa. Se llena cuando exista la
        // integracion y mientras tanto queda en blanco.
        $this->assertNull($pagos[0]->referencia_pos);
    }

    private function cuentaConTotal(int $total): Cuenta
    {
        $local = $this->crearRestaurante();
        $this->fijarRestaurante($local['restaurante']->id);

        return Cuenta::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'comanda_id' => $this->comandaConItems($local, [
                ['nombre' => 'Prueba', 'precio' => $total, 'estado' => EstadoItemComanda::Entregado],
            ])->id,
            'estado' => 'abierta',
            'subtotal' => $total,
            'propina' => 0,
            'total' => $total,
            'abierta_at' => now(),
        ]);
    }

    /**
     * @param  list<array{nombre: string, precio: int, estado: EstadoItemComanda}>  $items
     */
    private function comandaConItems(array $local, array $items): Comanda
    {
        $comanda = Comanda::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'grupos_mesas_id' => $local['grupo']->id,
            'folio' => random_int(1000, 99999),
            'estado' => EstadoComanda::Abierta->value,
            'comensales' => 2,
            'abierta_por_usuario_id' => $local['mesero']->id,
            'abierta_at' => now(),
        ]);

        $estacion = Estacion::query()->create([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Cocina caliente',
            'tipo' => TipoEstacion::Comida->value,
            'tiempo_objetivo_segundos' => 900,
            'activo' => true,
        ]);

        $categoriaId = DB::table('categorias_productos')->insertGetId([
            'tenant_id' => $local['restaurante']->id,
            'nombre' => 'Prueba',
            'orden' => 1,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orden = 0;

        foreach ($items as $item) {
            $orden++;

            $producto = Producto::query()->create([
                'tenant_id' => $local['restaurante']->id,
                'categoria_productos_id' => $categoriaId,
                'codigo' => 'PRUEBA-' . $orden . '-' . random_int(1000, 9999),
                'nombre' => $item['nombre'],
                'precio' => $item['precio'],
                'unidad' => 'porcion',
                'tipo_linea' => TipoLinea::Comida->value,
                'estacion_id' => $estacion->id,
                'acepta_modificadores' => false,
                'activo' => true,
            ]);

            ComandaItem::query()->create([
                'tenant_id' => $local['restaurante']->id,
                'comanda_id' => $comanda->id,
                'producto_id' => $producto->id,
                'nombre_producto' => $item['nombre'],
                'cantidad' => 1,
                'precio_unitario' => $item['precio'],
                'estacion_id' => $estacion->id,
                'tipo_linea' => TipoLinea::Comida->value,
                'tiempo_objetivo_segundos' => 900,
                'estado' => $item['estado']->value,
                'orden' => $orden,
            ]);
        }

        return $comanda;
    }
}