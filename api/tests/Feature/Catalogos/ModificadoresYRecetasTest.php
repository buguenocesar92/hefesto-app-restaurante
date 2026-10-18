<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogos;

use App\Enums\TipoEstacion;
use App\Enums\TipoLinea;
use App\Enums\TipoModificador;
use App\Models\CategoriaProducto;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Estacion;
use App\Models\GrupoModificadores;
use App\Models\Ingrediente;
use App\Models\Modificador;
use App\Models\Producto;
use App\Models\ProductoGrupoModificadores;
use App\Models\RecetaIngrediente;
use App\Services\Comandas\CalculadoraDePrecios;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Modificadores, agregados y recetas.
 *
 * Esta prueba comprueba que "sin cebolla, extra queso" es un DATO y no un
 * texto. Si el modelara como texto libre, estas comprobaciones no se podrian
 * escribir: no habria forma de saber si el extra queso se cobro ni si la
 * cebolla estaba en el plato.
 */
class ModificadoresYRecetasTest extends TestCase
{
    use RefreshDatabase;

    private CalculadoraDePrecios $calculadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = new CalculadoraDePrecios();
    }

    #[Test]
    public function un_agregado_suma_al_precio(): void
    {
        $datos = $this->catalogoDePrueba();
        $extraQueso = $datos['modificadores']['extra_queso'];

        $this->assertSame(
            $datos['lomo']->precio + 1500,
            $this->calculadora->precioUnitario($datos['lomo'], [$extraQueso->id]),
            'El extra queso tiene que sumar al precio del plato.'
        );
    }

    #[Test]
    public function una_remocion_no_suma_precio_pero_sigue_siendo_parte_del_pedido(): void
    {
        $datos = $this->catalogoDePrueba();
        $sinCebolla = $datos['modificadores']['sin_cebolla'];

        $precio = $this->calculadora->precioUnitario($datos['lomo'], [$sinCebolla->id]);

        $this->assertSame($datos['lomo']->precio, $precio);
    }

    #[Test]
    public function la_cantidad_multiplica_el_precio_unitario_con_modificadores(): void
    {
        $datos = $this->catalogoDePrueba();

        $precio = $this->calculadora->precioUnitario(
            $datos['lomo'],
            [$datos['modificadores']['extra_queso']->id],
            3
        );

        $this->assertSame(($datos['lomo']->precio + 1500) * 3, $precio);
    }

    #[Test]
    public function no_se_pede_sin_cebolla_un_plato_que_no_lleva_cebolla(): void
    {
        $datos = $this->catalogoDePrueba();

        // El pescado no lleva cebolla en su receta. "Sin cebolla" no es una
        // peticion valida para el, y el sistema lo dice en vez de aceptarlo en
        // silencio: un modificador que se acepta y no se puede cumplir llega
        // igual a la pantalla de cocina.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no lleva ese ingrediente');

        $this->calculadora->validar($datos['pescado'], [$datos['modificadores']['sin_cebolla']->id]);
    }

    #[Test]
    public function no_se_pede_un_modificador_que_no_aplica_al_producto(): void
    {
        $datos = $this->catalogoDePrueba();

        // El termino de la carne es del lomo, no del pescado.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no aplica al producto');

        $this->calculadora->validar($datos['pescado'], [$datos['modificadores']['al_punto']->id]);
    }

    #[Test]
    public function un_grupo_obligatorio_exige_una_eleccion(): void
    {
        $datos = $this->catalogoDePrueba();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Falta elegir');

        // El termino de la carne es obligatorio para el lomo.
        $this->calculadora->validar($datos['lomo'], [$datos['modificadores']['sin_cebolla']->id]);
    }

    #[Test]
    public function un_grupo_de_eleccion_unica_no_acepta_dos_opciones(): void
    {
        $datos = $this->catalogoDePrueba();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('se elige una sola opci');

        // "Al punto" y "Cocido" juntos son un error de captura, no una
        // preferencia.
        $this->calculadora->validar($datos['lomo'], [
            $datos['modificadores']['al_punto']->id,
            $datos['modificadores']['cocido']->id,
        ]);
    }

    #[Test]
    public function un_producto_que_no_acepta_modificadores_los_rechaza(): void
    {
        $datos = $this->catalogoDePrueba();

        $vino = Producto::query()->find($datos['vino']->id);
        $vino->acepta_modificadores = false;
        $vino->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no acepta modificadores');

        $this->calculadora->validar($vino, [$datos['modificadores']['sin_cebolla']->id]);
    }

    #[Test]
    public function el_precio_del_item_copia_el_del_catalogo_y_el_de_los_modificadores(): void
    {
        $datos = $this->catalogoDePrueba();

        $comanda = Comanda::query()->create([
            'tenant_id' => $datos['lomo']->tenant_id,
            'grupos_mesas_id' => $datos['grupo']->id,
            'folio' => 77,
            'estado' => 'abierta',
            'comensales' => 2,
            'abierta_at' => now(),
        ]);

        $item = ComandaItem::query()->create([
            'tenant_id' => $datos['lomo']->tenant_id,
            'comanda_id' => $comanda->id,
            'producto_id' => $datos['lomo']->id,
            'nombre_producto' => $datos['lomo']->nombre,
            'cantidad' => 2,
            'precio_unitario' => $datos['lomo']->precio,
            'estacion_id' => $datos['lomo']->estacion_id,
            'tipo_linea' => $datos['lomo']->tipo_linea->value,
            'tiempo_objetivo_segundos' => 900,
            'estado' => 'pendiente',
            'orden' => 1,
        ]);

        $extraQueso = $datos['modificadores']['extra_queso'];

        $item->modificadores()->create([
            'tenant_id' => $item->tenant_id,
            'modificadores_id' => $extraQueso->id,
            'nombre' => $extraQueso->nombre,
            'tipo' => $extraQueso->tipo->value,
            'precio' => $extraQueso->sumaAlPrecio(),
            'orden' => 1,
        ]);

        // Dos lomos con extra queso.
        $this->assertSame(($datos['lomo']->precio + 1500) * 2, $item->subtotal());

        // Y ahora la parte que importa para el historico: si manana suben el
        // extra queso, la comanda de hoy tiene que seguir cuadrando.
        $extraQueso->precio = 1800;
        $extraQueso->save();

        $this->assertSame(
            ($datos['lomo']->precio + 1500) * 2,
            $item->fresh()->subtotal(),
            'El precio copiado tiene que sobrevivir a un cambio de catalogo.'
        );
    }

    /**
     * Catalogo minimo pero real: un producto con cebolla en su receta, uno sin
     * cebolla, y los grupos de modificadores.
     *
     * @return array<string, mixed>
     */
    private function catalogoDePrueba(): array
    {
        $tenant = $this->crearRestaurante();
        $this->fijarRestaurante($tenant['restaurante']->id);

        $estacion = Estacion::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Cocina caliente',
            'tipo' => TipoEstacion::Comida->value,
            'tiempo_objetivo_segundos' => 900,
            'activo' => true,
        ]);

        $categoria = CategoriaProducto::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Principales',
            'orden' => 1,
            'activo' => true,
        ]);

        $carne = Ingrediente::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Carne de res',
            'unidad' => 'gramo',
            'activo' => true,
        ]);

        $cebolla = Ingrediente::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Cebolla',
            'unidad' => 'gramo',
            'activo' => true,
        ]);

        $lomo = Producto::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'categoria_productos_id' => $categoria->id,
            'codigo' => 'PRI-001',
            'nombre' => 'Lomo saltado',
            'precio' => 8900,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Comida->value,
            'estacion_id' => $estacion->id,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $pescado = Producto::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'categoria_productos_id' => $categoria->id,
            'codigo' => 'PRI-002',
            'nombre' => 'Pescado a la plancha',
            'precio' => 9500,
            'unidad' => 'porcion',
            'tipo_linea' => TipoLinea::Comida->value,
            'estacion_id' => $estacion->id,
            'acepta_modificadores' => true,
            'activo' => true,
        ]);

        $vino = Producto::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'categoria_productos_id' => $categoria->id,
            'codigo' => 'BEB-002',
            'nombre' => 'Vino tinto',
            'precio' => 4900,
            'unidad' => 'copa',
            'tipo_linea' => TipoLinea::Bebida->value,
            'estacion_id' => $estacion->id,
            'acepta_modificadores' => false,
            'activo' => true,
        ]);

        // El lomo lleva cebolla. El pescado NO. Esa diferencia es la que hace
        // que "sin cebolla" sea validable.
        RecetaIngrediente::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'producto_id' => $lomo->id,
            'ingrediente_id' => $carne->id,
            'cantidad' => 220,
            'unidad' => 'gramo',
        ]);

        RecetaIngrediente::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'producto_id' => $lomo->id,
            'ingrediente_id' => $cebolla->id,
            'cantidad' => 80,
            'unidad' => 'gramo',
        ]);

        // Grupos.
        $preferencias = GrupoModificadores::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Sin ingredientes',
            'seleccion' => 'multiple',
            'obligatorio' => false,
            'orden' => 1,
            'activo' => true,
        ]);

        $agregados = GrupoModificadores::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Agregados',
            'seleccion' => 'multiple',
            'obligatorio' => false,
            'orden' => 2,
            'activo' => true,
        ]);

        $termino = GrupoModificadores::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'nombre' => 'Término de la carne',
            'seleccion' => 'unica',
            'obligatorio' => true,
            'orden' => 0,
            'activo' => true,
        ]);

        $modificadores = [];

        $modificadores['sin_cebolla'] = Modificador::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'grupos_modificadores_id' => $preferencias->id,
            'nombre' => 'Sin cebolla',
            'tipo' => TipoModificador::Remocion->value,
            'precio' => 0,
            'ingrediente_id' => $cebolla->id,
            'orden' => 1,
            'activo' => true,
        ]);

        $modificadores['extra_queso'] = Modificador::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'grupos_modificadores_id' => $agregados->id,
            'nombre' => 'Extra queso',
            'tipo' => TipoModificador::Agregado->value,
            'precio' => 1500,
            'ingrediente_id' => null,
            'orden' => 1,
            'activo' => true,
        ]);

        $modificadores['al_punto'] = Modificador::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'grupos_modificadores_id' => $termino->id,
            'nombre' => 'Al punto',
            'tipo' => TipoModificador::Cambio->value,
            'precio' => 0,
            'ingrediente_id' => $carne->id,
            'orden' => 1,
            'activo' => true,
        ]);

        $modificadores['cocido'] = Modificador::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'grupos_modificadores_id' => $termino->id,
            'nombre' => 'Cocido',
            'tipo' => TipoModificador::Cambio->value,
            'precio' => 0,
            'ingrediente_id' => $carne->id,
            'orden' => 3,
            'activo' => true,
        ]);

        // Vinculos. El termino es obligatorio SOLO para el lomo.
        foreach ([$lomo, $pescado] as $producto) {
            ProductoGrupoModificadores::query()->create([
                'tenant_id' => $tenant['restaurante']->id,
                'producto_id' => $producto->id,
                'grupos_modificadores_id' => $preferencias->id,
                'obligatorio' => false,
                'orden' => 1,
            ]);

            ProductoGrupoModificadores::query()->create([
                'tenant_id' => $tenant['restaurante']->id,
                'producto_id' => $producto->id,
                'grupos_modificadores_id' => $agregados->id,
                'obligatorio' => false,
                'orden' => 2,
            ]);
        }

        ProductoGrupoModificadores::query()->create([
            'tenant_id' => $tenant['restaurante']->id,
            'producto_id' => $lomo->id,
            'grupos_modificadores_id' => $termino->id,
            'obligatorio' => true,
            'orden' => 0,
        ]);

        return [
            'restaurante' => $tenant['restaurante'],
            'grupo' => $tenant['grupo'],
            'estacion' => $estacion,
            'lomo' => $lomo,
            'pescado' => $pescado,
            'vino' => $vino,
            'modificadores' => $modificadores,
        ];
    }
}