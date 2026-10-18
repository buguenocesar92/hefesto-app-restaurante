<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoItemComanda;
use App\Enums\TipoLinea;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un pedido que viaja a cocina.
 *
 * El estado vive aqui y no en la comanda porque los pedidos se preparan en
 * paralelo y terminan en momentos distintos. La comanda con tres platos
 * puede tener uno entregado, uno en preparacion y uno recien enviado: si el
 * estado fuera unico, la cocina tendria que declarar la comanda entera cuando
 * uno sale y el salon dejaria de saber que falta algo.
 *
 * COPIAS DEL CATALOGO
 * -------------------
 * `nombre_producto`, `precio_unitario`, `estacion_id` y
 * `tiempo_objetivo_segundos` se copian al item aunque ya existieran en el
 * producto. Un cambio de precio, de nombre o de estacion no puede reescribir
 * la historia de lo que se pidio y se cobro.
 */
class ComandaItem extends ModeloRestaurante
{
    protected $table = 'comanda_items';

    protected $fillable = [
        'comanda_id',
        'producto_id',
        'nombre_producto',
        'cantidad',
        'precio_unitario',
        'notas_preparacion',
        'estacion_id',
        'tipo_linea',
        'tiempo_objetivo_segundos',
        'estado',
        'enviado_at',
        'listo_at',
        'entregado_at',
        'anulado_at',
        'anulado_por_usuario_id',
        'motivo_anulacion',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'integer',
            'tiempo_objetivo_segundos' => 'integer',
            'estado' => EstadoItemComanda::class,
            'tipo_linea' => TipoLinea::class,
            'enviado_at' => 'immutable_datetime',
            'listo_at' => 'immutable_datetime',
            'entregado_at' => 'immutable_datetime',
            'anulado_at' => 'immutable_datetime',
            'orden' => 'integer',
        ];
    }

    public function comanda(): BelongsTo
    {
        return $this->belongsTo(Comanda::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function modificadores(): HasMany
    {
        return $this->hasMany(ComandaItemModificador::class)->orderBy('orden');
    }

    /** Precio del item ya con modificadores, sin multiplicar por cantidad. */
    public function subtotal(): int
    {
        $modificadores = $this->modificadores->sum('precio');

        return ($this->precio_unitario + $modificadores) * $this->cantidad;
    }

    /**
     * Texto que ve la cocina: producto, cantidad, modificadores y nota.
     *
     * Vive en el item y no se arma en el frontend para que la pantalla de
     * cocina y la comanda del salon nunca puedan mostrar cosas distintas.
     */
    public function lineaParaCocina(): string
    {
        $texto = $this->cantidad . ' x ' . $this->nombre_producto;

        $nombres = $this->modificadores->pluck('nombre')->all();

        if ($nombres !== []) {
            $texto .= ' (' . implode(', ', $nombres) . ')';
        }

        if ($this->notas_preparacion !== null && trim($this->notas_preparacion) !== '') {
            $texto .= ' - ' . trim($this->notas_preparacion);
        }

        return $texto;
    }
}