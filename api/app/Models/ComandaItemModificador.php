<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modificador aplicado a un item, con los datos copiados del catalogo.
 *
 * Las copias son el motivo de que esta tabla exista: si manana suben el extra
 * queso de 1.500 a 1.800, la comanda de hoy tiene que seguir cuadrando con los
 * 1.500 que se cobraron, y llamarse igual que ayer.
 */
class ComandaItemModificador extends ModeloRestaurante
{
    protected $table = 'comanda_item_modificadores';

    protected $fillable = [
        'comanda_item_id',
        'modificadores_id',
        'nombre',
        'tipo',
        'precio',
        'ingrediente',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => \App\Enums\TipoModificador::class,
            'precio' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ComandaItem::class, 'comanda_item_id');
    }

    public function modificador(): BelongsTo
    {
        return $this->belongsTo(Modificador::class, 'modificadores_id');
    }
}