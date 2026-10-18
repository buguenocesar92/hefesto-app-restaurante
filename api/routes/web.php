<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| La interfaz la sirve Nuxt. La API solo responde JSON, asi que esta ruta no
| tiene contenido: existe para que una peticion a la raiz no devuelva un 404
| confuso cuando alguien abre la direccion de la API en el navegador.
*/

Route::get('/', fn () => response()->json([
    'servicio' => 'api-restaurante',
    'documentacion' => 'docs/09-API.md',
    'estado' => 'ok',
]));