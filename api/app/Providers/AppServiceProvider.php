<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Comanda;
use App\Models\ComandaItem;
use App\Models\Cuenta;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\Turno;
use App\Policies\ComandaItemPolicy;
use App\Policies\ComandaPolicy;
use App\Policies\CuentaPolicy;
use App\Policies\MesaPolicy;
use App\Policies\ProductoPolicy;
use App\Policies\TurnoPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registro de policies.
 *
 * El nombre del metodo `boot` es el que exige el contenedor de Laravel: es el
 * gancho de arranque. No hay logica de dominio aca.
 */
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Comanda::class, ComandaPolicy::class);
        Gate::policy(ComandaItem::class, ComandaItemPolicy::class);
        Gate::policy(Cuenta::class, CuentaPolicy::class);
        Gate::policy(Mesa::class, MesaPolicy::class);
        Gate::policy(Producto::class, ProductoPolicy::class);
        Gate::policy(Turno::class, TurnoPolicy::class);
    }
}