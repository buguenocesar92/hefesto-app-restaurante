<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ingrediente del inventario.
 *
 * `alergenos` se guarda como lista porque la normativa chilena (Ley 20.606
 * sobre información alimentaria) no define una tabla cerrada que se pueda
 * copiar aca sin verificarla. No se implementa rotulacion nutricional: solo
 * queda el dato.
 */
class Ingrediente extends ModeloRestaurante
{
    protected $table = 'ingredientes';

    protected $fillable = ['nombre', 'unidad', 'alergenos', 'activo'];

    protected function casts(): array
    {
        return [
            'alergenos' => 'array',
            'activo' => 'boolean',
        ];
    }

    public function recetas(): HasMany
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    /** Modificadores que quitan este ingrediente. */
    public function modificadores(): HasMany
    {
        return $this->hasMany(Modificador::class);
    }
}