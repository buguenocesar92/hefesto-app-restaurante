<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ingrediente de la receta de un producto, con su cantidad.
 *
 * Existe para una sola comprobacion que importa: que "sin cebolla" tenga
 * sentido. Un producto que no lleva cebolla en su receta no puede recibir una
 * remocion de cebolla, y el sistema lo rechaza en vez de aceptar un pedido que
 * la cocina no puede cumplir.
 */
class RecetaIngrediente extends ModeloRestaurante
{
    protected $table = 'receta_ingredientes';

    protected $fillable = ['producto_id', 'ingrediente_id', 'cantidad', 'unidad'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function ingrediente(): BelongsTo
    {
        return $this->belongsTo(Ingrediente::class);
    }
}