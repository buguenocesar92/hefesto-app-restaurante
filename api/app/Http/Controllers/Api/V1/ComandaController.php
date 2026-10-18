<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoComanda;
use App\Enums\EstadoItemComanda;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularItemRequest;
use App\Http\Requests\StoreComandaRequest;
use App\Http\Requests\StoreItemComandaRequest;
use App\Http\Resources\ComandaItemResource;
use App\Http\Resources\ComandaResource;
use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\GrupoMesas;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Turno;
use App\Services\Comandas\CalculadoraDePrecios;
use App\Services\Comandas\ServicioComandas;
use App\Services\Cocina\RelojDeCocina;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de la comanda.
 *
 * Este es el controlador con el que se pierde o se gana dinero: cualquier
 * error aqui significa un plato mal cobrado o una comanda cobrada dos veces.
 * Tres decisiones que se notan en el codigo:
 *
 *  1. TODAS las rutas van por `ServicioComandas` o por la maquina de estados.
 *     No hay ningun endpoint que escriba `estado` a mano.
 *  2. El envio a cocina y la inclusion del item ocurren en la MISMA
 *     transaccion que el alta, para que no exista un item en la comanda que
 *     kitchen nunca vio.
 *  3. La autorizacion la resuelve la Policy, no un `if` en el metodo.
 */
class ComandaController extends Controller
{
    public function __construct(
        private readonly ServicioComandas $servicio,
        private readonly CalculadoraDePrecios $calculadora,
        private readonly RelojDeCocina $reloj,
    ) {}

    public function index()
    {
        $comandas = Comanda::query()
            ->with(['grupoMesas.mesas', 'items'])
            ->whereIn('estado', [EstadoComanda::Abierta->value, EstadoComanda::PorCobrar->value])
            ->orderByDesc('abierta_at')
            ->limit(60)
            ->get();

        return ComandaResource::collection($comandas);
    }

    public function store(StoreComandaRequest $peticion)
    {
        $this->authorize('crear', Comanda::class);

        $grupo = GrupoMesas::query()->findOrFail($peticion->integer('grupo_mesas_id'));

        // Un grupo sin mesas no puede tener comanda: seria una comanda
        // huerfana que nadie puede ver en el mapa.
        if ($grupo->mesas()->doesntExist()) {
            abort(422, 'Ese grupo de mesas no tiene mesas asignadas.');
        }

        $turno = app()->bound('turno.actual') ? app('turno.actual') : Turno::query()
            ->where('activo', true)
            ->get()
            ->first(fn (Turno $turno): bool => $turno->contiene(now()));

        $comanda = $this->servicio->abrir(
            $grupo,
            $peticion->integer('comensales'),
            $peticion->user(),
            $peticion->input('notas'),
            $turno,
        );

        return (new ComandaResource($comanda->load(['grupoMesas.mesas', 'items'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Comanda $comanda)
    {
        $this->authorize('view', $comanda);

        return new ComandaResource(
            $comanda->load(['grupoMesas.mesas', 'items.modificadores', 'eventos.usuario', 'cuenta.divisiones'])
        );
    }

    /**
     * Agrega un item.
     *
     * Si el request pide `enviar_a_cocina`, el item nace en `enviado` y se
     * marca en el reloj de cocina. En caso contrario nace en `pendiente` y no
     * es visible para cocina hasta que el mesero lo envie.
     */
    public function agregarItem(StoreItemComandaRequest $peticion, Comanda $comanda)
    {
        $this->authorize('agregarItem', $comanda);

        $producto = Producto::query()
            ->with('estacion')
            ->findOrFail($peticion->integer('producto_id'));

        if (! $producto->activo) {
            abort(422, 'Ese producto no esta disponible.');
        }

        try {
            $this->calculadora->validar($producto, $peticion->identificadoresModificadores());
        } catch (DomainException $error) {
            abort(422, $error->getMessage());
        }

        $item = DB::transaction(function () use ($peticion, $comanda, $producto): ComandaItem {
            $siguienteOrden = (int) ComandaItem::query()->where('comanda_id', $comanda->id)->max('orden');

            $item = ComandaItem::query()->create([
                'comanda_id' => $comanda->id,
                'producto_id' => $producto->id,
                'nombre_producto' => $producto->nombre,
                'cantidad' => $peticion->integer('cantidad'),
                'precio_unitario' => $producto->precio,
                'notas_preparacion' => $peticion->input('notas_preparacion'),
                'estacion_id' => $producto->estacion_id,
                'tipo_linea' => $producto->tipo_linea->value,
                'tiempo_objetivo_segundos' => $producto->objetivoSegundos(),
                'estado' => $peticion->enviarACocina()
                    ? EstadoItemComanda::Enviado->value
                    : EstadoItemComanda::Pendiente->value,
                'enviado_at' => $peticion->enviarACocina() ? now() : null,
                'orden' => $siguienteOrden + 1,
            ]);

            // Los modificadores se COPIAN con nombre, tipo y precio. Es lo que
            // hace que el historico siga siendo fiel si manana suben los
            // precios del catalogo. Se guardan solo los que el mesero eligio, en
            // el orden en que los eligio la peticion.
            foreach ($peticion->identificadoresModificadores() as $posicion => $idModificador) {
                $modificador = \App\Models\Modificador::query()->find($idModificador);

                if ($modificador === null) {
                    continue;
                }

                $item->modificadores()->create([
                    'modificadores_id' => $modificador->id,
                    'nombre' => $modificador->nombre,
                    'tipo' => $modificador->tipo->value,
                    'precio' => $modificador->tipo->sumaAlPrecio(),
                    'ingrediente' => $modificador->ingrediente?->nombre,
                    'orden' => $posicion + 1,
                ]);
            }

            $this->servicio->eventoDeItem(
                $comanda,
                $peticion->user(),
                'item_agregado',
                $item,
                ['producto' => $producto->nombre, 'cantidad' => $item->cantidad],
            );

            return $item;
        });

        if ($item->estado === EstadoItemComanda::Enviado) {
            $this->reloj->marcar($item, EstadoItemComanda::Enviado);
        }

        return (new ComandaItemResource($item->load('modificadores')))
            ->response()
            ->setStatusCode(201);
    }

    /** Envia a cocina todos los items que el mesero aun no habia enviado. */
    public function enviarACocina(Comanda $comanda)
    {
        $this->authorize('editarItem', $comanda);

        $enviados = DB::transaction(function () use ($comanda): array {
            $items = ComandaItem::query()
                ->where('comanda_id', $comanda->id)
                ->where('estado', EstadoItemComanda::Pendiente->value)
                ->orderBy('orden')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $this->servicio->maquina()->moverItem($item->estado, EstadoItemComanda::Enviado);

                $item->estado = EstadoItemComanda::Enviado;
                $item->enviado_at = now();
                $item->save();

                $this->servicio->eventoDeItem(
                    $comanda,
                    request()->user(),
                    'enviado_a_cocina',
                    $item,
                );
            }

            return $items->all();
        });

        foreach ($enviados as $item) {
            $this->reloj->marcar($item, EstadoItemComanda::Enviado);
        }

        return ComandaItemResource::collect($enviados);
    }

    /**
     * Anula un item.
     *
     * La Policy bloquea si la comanda esta cobrada y la maquina de estados
     * bloquea si el plato ya esta listo. Los dos son mensajes distintos y los
     * dos se necesitan: "la comanda esta cobrada" y "el plato ya salio" son
     * problemas que el mesero resuelve de forma distinta.
     */
    public function anularItem(AnularItemRequest $peticion, Comanda $comanda, ComandaItem $item)
    {
        $this->authorize('anular', $item);

        if ($item->comanda_id !== $comanda->id) {
            abort(404);
        }

        try {
            $this->servicio->maquina()->moverItem($item->estado, EstadoItemComanda::Anulado);
        } catch (DomainException $error) {
            abort(422, $error->getMessage());
        }

        DB::transaction(function () use ($item, $comanda, $peticion): void {
            $item->estado = EstadoItemComanda::Anulado;
            $item->anulado_at = now();
            $item->anulado_por_usuario_id = $peticion->user()->id;
            $item->motivo_anulacion = $peticion->string('motivo')->toString();
            $item->save();

            $this->servicio->eventoDeItem($comanda, $peticion->user(), 'item_anulado', $item, [
                'motivo' => $item->motivo_anulacion,
            ]);
        });

        $this->reloj->marcar($item, EstadoItemComanda::Anulado);

        return new ComandaItemResource($item->fresh());
    }

    /** Pide la cuenta: la comanda pasa a por_cobrar. */
    public function pedirCuenta(Comanda $comanda)
    {
        $this->authorize('pedirCuenta', $comanda);

        $comanda = $this->servicio->cambiarEstado($comanda, EstadoComanda::PorCobrar, request()->user());

        return new ComandaResource($comanda->fresh()->load('cuenta'));
    }

    /** Reabre una comanda por cobrar porque el comensal pidio algo mas. */
    public function reabrir(Comanda $comanda)
    {
        $this->authorize('reabrir', $comanda);

        $comanda = $this->servicio->cambiarEstado($comanda, EstadoComanda::Abierta, request()->user());

        if ($comanda->grupoMesas !== null) {
            $ids = $comanda->grupoMesas->mesas()->pluck('mesas.id');

            if ($ids->isNotEmpty()) {
                Mesa::query()->whereIn('id', $ids)->update(['estado' => 'ocupada']);
            }
        }

        return new ComandaResource($comanda->fresh());
    }

    /**
     * Anula la comanda entera.
     *
     * Una comanda cobrada no llega aca: la Policy devuelve falso y el usuario
     * recibe 403 con el motivo del cobro. Ver App\Policies\ComandaPolicy.
     */
    public function anular(Comanda $comanda)
    {
        $this->authorize('anular', $comanda);

        $motivo = request()->string('motivo')->toString();

        if (mb_strlen(trim($motivo)) < 5) {
            abort(422, 'Escribe el motivo de la anulacion.');
        }

        $comanda = $this->servicio->cambiarEstado($comanda, EstadoComanda::Anulada, request()->user(), $motivo);

        if ($comanda->grupoMesas !== null) {
            $ids = $comanda->grupoMesas->mesas()->pluck('mesas.id');

            if ($ids->isNotEmpty()) {
                Mesa::query()->whereIn('id', $ids)->update(['estado' => 'libre']);
            }
        }

        return new ComandaResource($comanda->fresh());
    }

    public function eventos(Comanda $comanda)
    {
        $this->authorize('view', $comanda);

        return response()->json([
            'eventos' => $comanda->eventos()
                ->with('usuario')
                ->orderByDesc('ocurrio_en')
                ->limit(100)
                ->get()
                ->map(fn ($evento): array => [
                    'id' => $evento->id,
                    'tipo' => $evento->tipo,
                    'resumen' => $evento->resumen,
                    'detalle' => $evento->detalle,
                    'usuario' => $evento->usuario?->nombreCompleto(),
                    'ocurrio_en' => $evento->ocurrio_en?->toIso8601String(),
                ]),
        ]);
    }
}