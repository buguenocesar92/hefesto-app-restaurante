<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductoResource;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administracion del catalogo: productos, precios, recetas y modificadores.
 *
 * Todo lo que hay aca es de la Policy `administrar`, o sea, solo rol
 * administrador. Quien abre caja ve el menu (lo necesita para cobrar) pero no
 * lo edita. Ver App\Policies\ProductoPolicy.
 */
class ProductoController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Producto::class);

        $productos = Producto::query()
            ->with(['categoria', 'estacion', 'gruposModificadores.grupo.modificadores'])
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return ProductoResource::collection($productos);
    }

    public function show(Producto $producto)
    {
        $this->authorize('view', $producto);

        return new ProductoResource($producto->load(['categoria', 'estacion', 'gruposModificadores.grupo.modificadores']));
    }

    public function store(Request $peticion)
    {
        $this->authorize('administrar', Producto::class);

        $datos = $peticion->validate([
            'categoria_productos_id' => ['required', 'integer', 'exists:categorias_productos,id'],
            'codigo' => ['required', 'string', 'max:40', Rule::unique('productos', 'codigo')],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'precio' => ['required', 'integer', 'min:0', 'max:1000000'],
            'tipo_linea' => ['required', 'string', Rule::in(['comida', 'bebida', 'postre'])],
            'estacion_id' => ['required', 'integer', 'exists:estaciones,id'],
            'tiempo_objetivo_segundos' => ['nullable', 'integer', 'min:30', 'max:7200'],
            'acepta_modificadores' => ['nullable', 'boolean'],
        ], [], [
            'precio' => 'precio',
            'estacion_id' => 'estacion',
        ]);

        $producto = Producto::query()->create($datos);

        return new ProductoResource($producto->load(['categoria', 'estacion']));
    }

    /**
     * Cambia el precio.
     *
     * El precio que se guarda aca es el precio de CATALOGO, que es una cosa
     * distinta del precio que cobra el POS. La sincronizacion entre ambos no
     * esta implementada: ver docs/09-API.md.
     */
    public function actualizarPrecio(Request $peticion, Producto $producto)
    {
        $this->authorize('cambiarPrecio', $producto);

        $datos = $peticion->validate([
            'precio' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [], ['precio' => 'precio']);

        $anterior = $producto->precio;
        $producto->precio = $datos['precio'];
        $producto->save();

        // El cambio de precio queda en la respuesta porque quien lo pide
        // necesita confirmarlo. La bitacora de precios se deberia guardar en
        // `comanda_eventos` cuando exista el historico de catalogo; hoy no hay
        // tabla para eso y no se inventa una a medio hacer.
        return response()->json([
            'id' => $producto->id,
            'precio_anterior' => $anterior,
            'precio_nuevo' => $producto->precio,
        ]);
    }
}