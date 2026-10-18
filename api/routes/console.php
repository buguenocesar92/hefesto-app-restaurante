<?php

declare(strict_types=1);

use App\Services\Cocina\RelojDeCocina;
use Illuminate\Support\Facades\Schedule;

/*
| Tareas programadas.
|
| La unica que hace falta hoy es la reconstruccion del reloj de cocina: si
| Redis se perdio o se reinicio, la pantalla de cocina volveria a mostrar
| pedidos sin tiempo hasta que alguien los marcara otra vez.
|
| Verificar el aislamiento tambien es una tarea: corre a diario y falla si el
| rol de la base gano superusuario o BYPASSRLS, que es la forma tipica en que
| el aislamiento se rompe sin que nadie lo note.
*/

Schedule::command('restaurante:reconstruir-reloj')->hourly()->withoutOverlapping();

Schedule::command('restaurante:verificar-aislamiento')->dailyAt('06:00');