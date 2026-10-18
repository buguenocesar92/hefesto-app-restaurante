<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuenta de una comanda.
 *
 * NO HAY INTEGRACION CON EL POS. Esta tabla guarda lo que el salon necesita
 * para dividir: cuantas partes, quien pago y cuanto. La boleta la emite el POS
 * del cliente, y la reversion de un cobro tambien. Ver docs/09-API.md.
 */
class Cuenta extends ModeloRestaurante
{
    protected $table = 'cuentas';

    protected $fillable = [
        'comanda_id',
        'estado',
        'subtotal',
        'propina',
        'total',
        'cobrada_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'propina' => 'integer',
            'total' => 'integer',
            'abierta_at' => 'immutable_datetime',
            'cobrada_at' => 'immutable_datetime',
        ];
    }

    public function comanda(): BelongsTo
    {
        return $this->belongsTo(Comanda::class);
    }

    public function divisiones(): HasMany
    {
        return $this->hasMany(CuentaDivision::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    /** Reparte el total en partes lo mas iguales posible, difiriendo en un peso. */
    public function repartir(int $partes): void
    {
        if ($partes < 1) {
            return;
        }

        $base = intdiv($this->total, $partes);
        $resto = $this->total % $partes;

        $this->divisiones()->delete();

        for ($indice = 1; $indice <= $partes; $indice++) {
            // Las primeras partes reciben el peso sobrante. Es la unica forma
            // de que la suma de las partes sea exactamente el total, y con
            // precios en pesos enteros eso no es un detalle: es una diferencia
            // que alguien tendria que pagar de su bolsillo.
            $monto = $base + ($indice <= $resto ? 1 : 0);

            $this->divisiones()->create(['parte' => $indice, 'monto' => $monto]);
        }
    }

    public function totalPagado(): int
    {
        return (int) $this->divisiones()->where('estado', 'pagada')->sum('monto');
    }

    public function saldo(): int
    {
        return $this->total - $this->totalPagado();
    }
}