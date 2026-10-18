<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoModificador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opcion de un grupo de modificadores.
 *
 * "Sin cebolla" y "extra queso" son filas de esta tabla, no texto. Cada una
 * tiene tipo, precio y, cuando corresponde, el ingrediente que afecta.
 */
class Modificador extends ModeloRestaurante
{
    protected $table = 'modificadores';

    protected $fillable = [
        'grupos_modificadores_id',
        'nombre',
        'tipo',
        'precio',
        'ingrediente_id',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoModificador::class,
            'precio' => 'integer',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoModificadores::class, 'grupos_modificadores_id');
    }

    public function ingrediente(): BelongsTo
    {
        return $this->belongsTo(Ingrediente::class);
    }

    /** Un agrupado suma al precio; el resto no. */
    public function sumaAlPrecio(): int
    {
        return $this->tipo === TipoModificador::Agregado ? $this->precio : 0;
    }
}