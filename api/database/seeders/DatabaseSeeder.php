<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de las semillas.
 *
 * corre con `php artisan db:seed`.
 *
 * La semilla crea UN restaurante de ejemplo, con su carta, sus mesas, sus
 * turnos y una comanda abierta. No crea un segundo restaurante a proposito:
 * el aislamiento entre restaurantes se prueba en `tests/Feature/Aislamiento`,
 * donde crear el segundo tenant durante las pruebas es parte de lo que se
 * verifica.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RestauranteDemoSeeder::class);
    }
}