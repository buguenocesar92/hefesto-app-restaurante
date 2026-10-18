<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitacora de la comanda.
 *
 * Se escribe DENTRO de la misma transaccion que el cambio de estado. Si el
 * cambio se confirma, el evento existe; si el evento existe, el cambio se
 * confirmo. Nunca hay uno sin el otro.
 *
 * No es un registro decorativo: responde a las tres preguntas del dia a dia
 * ("cuando mando este plato", "quien anulo esto", "este plato quedo listo a las
 * 21:40 y nadie lo llevo hasta las 22:10").
 */
class ComandaEvento extends ModeloRestaurante
{
    protected $table = 'comanda_eventos';

    protected $fillable = [
        'comanda_id',
        'comanda_item_id',
        'usuario_id',
        'tipo',
        'resumen',
        'detalle',
        'ocurrio_en',
    ];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'ocurrio_en' => 'immutable_datetime',
        ];
    }

    public function comanda(): BelongsTo
    {
        return $this->belongsTo(Comanda::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ComandaItem::class, 'comanda_item_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}