<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoComanda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * La comanda: lo que viaja del salon a la cocina.
 *
 * Esta fila responde a la pregunta que el dueño se hace en cinco segundos:
 * "que esta pasando en mi local ahora". Su estado, sus items y su bitacora son
 * el corazon del sistema.
 *
 * Cuelga de un grupo de mesas, no de una mesa, para que la mesa combinada no
 * sea un caso especial. Ver la migracion 2026_10_17_000004.
 */
class Comanda extends ModeloRestaurante
{
    protected $table = 'comandas';

    protected $fillable = [
        'grupos_mesas_id',
        'folio',
        'estado',
        'comensales',
        'abierta_por_usuario_id',
        'abierta_en_turno_id',
        'notas',
        'cerrada_at',
        'cobrada_at',
        'anulada_at',
        'anulada_por_usuario_id',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'folio' => 'integer',
            'estado' => EstadoComanda::class,
            'comensales' => 'integer',
            'abierta_at' => 'immutable_datetime',
            'cerrada_at' => 'immutable_datetime',
            'cobrada_at' => 'immutable_datetime',
            'anulada_at' => 'immutable_datetime',
        ];
    }

    public function grupoMesas(): BelongsTo
    {
        return $this->belongsTo(GrupoMesas::class);
    }

    public function abiertaPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'abierta_por_usuario_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'abierta_en_turno_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComandaItem::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ComandaEvento::class);
    }

    public function cuenta(): HasOne
    {
        return $this->hasOne(Cuenta::class);
    }

    /** Items que siguen vivos: ni entregados ni anulados. */
    public function itemsVivos(): HasMany
    {
        return $this->hasMany(ComandaItem::class)
            ->whereNotIn('estado', ['entregado', 'anulado']);
    }

    /** Items queKitchen todavia tiene que sacar. */
    public function itemsEnCocina(): HasMany
    {
        return $this->hasMany(ComandaItem::class)
            ->whereIn('estado', ['enviado', 'en_preparacion', 'listo']);
    }

    /** Nombre legible: "Comanda 42 (Mesas 5+6)". */
    public function etiqueta(): string
    {
        $mesas = $this->grupoMesas?->nombre ?? 'mesa';

        return 'Comanda ' . $this->folio . ' (' . $mesas . ')';
    }
}