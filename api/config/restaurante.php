<?php

declare(strict_types=1);

/*
| Configuracion de la aplicacion de restaurante.
|
| Los valores de negocio (tiempos objetivo, estados) NO viven en la
| configuracion: viven en la base, en `estaciones` y en `turnos`, porque son
| cosas que cada local ajusta segun su carta y su horario. Ponerlos en el
| `.env` obligaria a un despliegue para cambiar un tiempo de coccion.
*/

return [
    /*
     | Rol que puede anular una comanda entera.
     |
     | Existe para que el cambio se haga sin desplegar. Lo que NO se puede
     | cambiar aca es la regla de que una comanda cobrada no se anula: esa no es
     | una preferencia de instalacion, es una proteccion del cobro.
     */
    'rol_que_anula_comanda' => env('RESTAURANTE_ROL_QUE_ANULA', 'administrador'),

    /*
     | Si se permite anular un item que ya esta `listo` en pantalla de cocina.
     |
     | Por defecto NO, y la recomendacion es dejarlo asi. Un plato listo esta
     | emplatado: anularlo significa que alguien va a cobrar y otro va a
     | devolver. La excepcion real (el comensal devuelve el plato) se resuelve
     | en el punto de venta.
     */
    'permitir_anular_item_listo' => (bool) env('RESTAURANTE_PERMITIR_ANULAR_LISTO', false),

    /*
     | Minutos sin marcar un item en preparacion antes de avisar.
     |
     | Es un umbral de gestion interna para que el jefe de cocina mire la
     | pantalla. No genera notificaciones: esta aplicacion no manda mensajes.
     */
    'aviso_item_sin_movimiento_minutos' => (int) env('RESTAURANTE_AVISO_SIN_MOVIMIENTO_MIN', 25),

    /*
     | Comandas que se listan por pantalla en el indice de comandas.
     |
     | Es un tope de lectura, no un filtro: si el local tiene mas comandas
     | abiertas que este numero, hay que mirarlo. En un local normal no pasa.
     */
    'limite_comandas_activas' => (int) env('RESTAURANTE_LIMITE_COMANDAS', 60),
];