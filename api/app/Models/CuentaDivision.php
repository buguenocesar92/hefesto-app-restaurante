<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una de las partes en que se divide la cuenta.
 *
 * Guarda SU monto y no solo el numero de comensal. Una mesa de cuatro donde
 * el nino no paga, o donde una parte incluye el plato de otro, se expresa
 * ajustando montos y no con encajes raros.
 */
class CuentaDivision extends ModeloRestaurante
{
    protected $table = 'cuenta_divisiones';

    protected $fillable = [
        'cuenta_id',
        'parte',
        'monto',
        'propina',
        'estado',
        'identificacion_pagador',
    ];

    protected function casts(): array
    {
        return [
            'parte' => 'integer',
            'monto' => 'integer',
            'propina' => 'integer',
        ];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function estaPagada(): bool
    {
        return $this->estado === 'pagada';
    }
}