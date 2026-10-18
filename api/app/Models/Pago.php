<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormaPago;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de un pago recibido en el salon.
 *
 * `referencia_pos` queda en blanco mientras no exista la integracion con el
 * punto de venta. No se inventa un identificador de un sistema con el que
 * todavia no hablamos: un dato inventado en la columna de referencia es peor
 * que un dato ausente, porque parece confiable.
 */
class Pago extends ModeloRestaurante
{
    protected $table = 'pagos';

    protected $fillable = [
        'cuenta_id',
        'cuenta_divisiones_id',
        'monto',
        'forma_pago',
        'referencia_pos',
        'recibido_por_usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'integer',
            'forma_pago' => FormaPago::class,
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(CuentaDivision::class, 'cuenta_divisiones_id');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'recibido_por_usuario_id');
    }
}