<?php

declare(strict_types=1);

/*
| Configuracion del reloj de cocina.
|
| El reloj vive en Redis y los tiempos se calculan en memoria. Estos son los
| unicos parametros que se ajustan por instalacion.
*/

return [
    /*
     * Conexion de Redis que usa el reloj.
     *
     * Se separa de la general a proposito: el reloj guarda datos VOLATILES que
     * se pueden perder (se reconstruyen desde la base), mientras que la sesion
     * NO se puede perder porque si. Si comparten volumen y alguien hace un
     * FLUSHALL para "limpiar", se lleva la sesion de todos los meseros.
     */
    'conexion' => env('COCINA_REDIS_CONEXION', 'default'),

    /*
     * Horas que se conserva la linea de tiempo de un item ya terminal.
     *
     * Es tiempo de diagnostico, no de historico: el historico esta en
     * `comanda_items.enviado_at` y en `comanda_eventos`, que no se borran.
     */
    'retencion_horas' => (int) env('COCINA_REDIS_RETENCION_HORAS', 72),

    /*
     * Cuantos pedidos trae el panel por estacion.
     *
     * Un local con dos pantallas y 20 pedidos en cola ya esta saturado. Traer
     * mas solo agrega latencia a una pantalla que se recarga cada poco.
     */
    'limite_pedidos' => (int) env('COCINA_LIMITE_PEDIDOS', 30),

    /*
     * Cada cuanto, en milisegundos, la pantalla de cocina consulta.
     *
     * NO se deja en 1000 como maximo por-precision: la pantalla esta al lado
     * de la cocina y una recarga por segundo con veinte pedidos en pantalla no
     * le aporta nada al que cocina y lo hace mas lento.
     */
    'intervalo_refresco_ms' => (int) env('COCINA_INTERVALO_REFRESCO_MS', 3000),
];