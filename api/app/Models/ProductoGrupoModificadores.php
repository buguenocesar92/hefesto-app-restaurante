<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vinculo entre un producto y un grupo de modificadores.
 *
 * `obligatorio` puede sobrescribir el valor del grupo: el mismo grupo puede ser
 * obligatorio para el lomo (hay que elegir termino) y opcional para una
 * entrada, sin duplicar el grupo ni sus opciones.
 */
class ProductoGrupoModificadores extends ModeloRestaurante
{
    protected $table = 'producto_grupos_modificadores';

    protected $fillable = ['producto_id', 'grupos_modificadores_id', 'obligatorio', 'orden'];

    protected function casts(): array
    {
        return [
            'obligatorio' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoModificadores::class, 'grupos_modificadores_id');
    }
}