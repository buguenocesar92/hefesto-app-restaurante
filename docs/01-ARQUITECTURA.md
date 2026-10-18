# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## 1. Qué resuelve esta aplicación

Un punto de venta registra una venta. Un restaurante de sala coordina cinco
cosas al mismo tiempo: hay mesa asignada, la comanda viaja a cocina, los platos
se preparan en paralelo, las bebidas se sirven aparte y la cuenta se divide
entre comensales.

El cuello de botella real de un restaurante no es cobrar: es **la coordinación
entre salón y cocina**. Una mesa esperando un plato que salió hace media hora y
que nadie avisó es dinero que se pierde y un cliente que no vuelve.

Esta aplicación existe para hacer visible esa coordinación. Todo lo demás está
al servicio de eso.

## 2. La decisión que define el proyecto

El estado del pedido vive en **el ítem**, no en la comanda.

Si la comanda tuviera un único estado ("en cocina"), habría que declararla
completa cuando uno de tres platos sale, y el salón dejaría de saber qué
falta. Con el estado por ítem:

- La cocina ve tres pedidos con tres tiempos distintos, y ordena por el más
  antiguo.
- El mesero ve que un plato ya salió y otro sigue en preparación.
- Un plato listo que nadie reclama **sigue contando tiempo**, y eso lo hace
  visible. Es el problema de gestión que justifica el sistema.

Los tiempos se calculan **en memoria, contra Redis**, no en la base. Ver la
sección 4.

## 3. Arquitectura BFF

```
                  Internet
                     |
                  Caddy  (único puerto público, TLS)
                   /   \
      /api/*      |     |      (resto)
          \        |     /
        Nuxt (web:3000)         <- el navegador nunca habla con la API
           BFF + sesión + OIDC        |
                    |                 |
                    \_________________/
                              |
                      Laravel API (api:8080)
                         |            |
                    PostgreSQL       Redis
                  (RLS por tenant)  (reloj de cocina)
```

El navegador nunca ve la dirección de la API. Todo pasa por el mismo origen
(este servidor reenvía), y eso resuelve dos problemas de una vez:

- La cookie de sesión es de un solo origen, sin CORS ni credenciales.
- El token CSRF se reenvía como cabecera `X-XSRF-TOKEN` desde el servidor, sin
  exponerlo al JavaScript de la página.

**La API no se expone en producción.** Si se expusiera, alguien podría llamarla
sin pasar por el BFF y saltarse el manejo de sesión entero.

## 4. Por qué los tiempos no se calculan en la base

La pantalla de cocina se consulta cada 3 segundos. Con veinte pedidos en
cola son veinte lecturas por minuto, y cada una trae un tiempo que cambia con
cada recarga.

Si el tiempo se calculara con `now() - enviado_at` en PostgreSQL, cada recarga
sería una consulta a la base cuyo resultado es un número que el servidor ya
sabe calcular: qué hora es.

**Lo que se hace:**

| Dato | Dónde vive | Por qué |
|---|---|---|
| `enviado_at`, `listo_at`, `entregado_at` | PostgreSQL | Son históricos. El reporte de "qué plato tardó más" los necesita, y tienen que sobrevivir a que Redis se caiga. |
| Marcas vivas y cálculo del tiempo | Redis | Es lo que la pantalla consulta varias veces por minuto. |
| Si Redis se pierde | Se reconstruye desde la base | `restaurante:reconstruir-reloj` repuebla el reloj. Perder Redis cuesta una reconstrucción, no datos. |

El reloj usa dos estructuras:

- Un hash por pedido: `cocina:item:{restaurante}:{item}` con las marcas.
- Un conjunto ordenado por estación: `cocina:cola:{restaurante}:{estacion}`,
  con el pedido como miembro y la marca de envío como puntaje. Permite pedir
  "los 20 más antiguos de esta estación" en una operación.

El puntaje es la marca de **envío**, no la de la transición actual. Un plato que
estuvo 18 minutos en preparación y acaba de quedar listo sigue siendo viejo, y
por eso sube en la pantalla.

## 5. Las bebidas no son platos

Un modelo con una sola "cocina" obliga a que una cerveza espere en la misma
cola que un pastel de choclo. En el mostrador real no es así: la barra sirve en
dos minutos lo que la cocina tarda quince.

Si el modelo no distingue estaciones, el tiempo de la bebida se infla
artificialmente y el del plato se ve más rápido de lo que es. **Los dos números
mienten**, y el jefe de cocina pierde la confianza en la pantalla.

Por eso existe `estaciones` con tipo `comida`, `bebida` y `postura`, y cada
producto apunta a **su** estación, con su tiempo objetivo. La pantalla se filtra
por estación.

## 6. Mesas combinables

La comanda cuelga de un **grupo de mesas**, nunca de una mesa. Una mesa sola es
un grupo de un miembro; una mesa combinada es un grupo de dos.

Así el caso corriente y el caso raro comparten el mismo camino de código y la
combinación no necesita tratamiento especial en ningún endpoint. El detalle
está en [02](02-MODELO-DE-DATOS.md), sección 4.

## 7. Lo que esta aplicación NO hace

- **No cobra de verdad.** Registra pagos en el salón, pero la boleta la emite el
  punto de venta que el restaurante ya tiene. La integración **no está
  implementada** y está documentada como guía en [09](09-API.md).
- **No revierte un cobro.** Una comanda cobrada no se anula desde aquí, para
  ningún rol, incluido el administrador.
- **No manda notificaciones.** No hay correos ni mensajes automáticos.
- **No controla inventario.** Hay recetas, pero no se descontan ingredientes al
  vender.
- **No lleva contabilidad ni facturación.** El POS del cliente es la autoridad.

## 8. Componentes

| Componente | Tecnología | Rol |
|---|---|---|
| API | Laravel 12 / PHP 8.4 | Reglas de negocio, autorización, bitácora |
| Interfaz | Nuxt 4 SSR | Salón, comanda, cocina, cuentas, administración |
| Datos | PostgreSQL 18 | Estado durable, con Row Level Security por tenant |
| Memoria | Redis 8 | Reloj de cocina, caché, colas |
| Identidad | Authentik 2025.10 | Inicio de sesión único (OIDC + PKCE) |

## 9. Estado

**Código escrito, sin ejecutar.** En el entorno donde se construyó esta
aplicación no había Docker, ni PHP, ni Composer. Ver
[10](10-PRUEBAS-Y-ESTADO.md) para qué se comprobó y qué no.