# Aplicación de restaurante — Salón y Cocina

Software propio de **Hefesto Studio**. Multiinquilino (un restaurante por tenant),
en español de Chile.

**Estado: código escrito, sin ejecutar.** No se ha arrancado ni una vez. Ver
[docs/10](docs/10-PRUEBAS-Y-ESTADO.md) para qué se comprobó y qué no.

---

## El problema que resuelve

Un punto de venta registra una venta. Un restaurante de sala coordina cinco cosas
al mismo tiempo: hay mesa asignada, la comanda viaja a cocina, los platos se
preparan en paralelo, las bebidas se sirven aparte y la cuenta se divide entre
comensales.

El cuello de botella real **no es cobrar**. Es **la coordinación entre salón y
cocina**: una mesa esperando un plato que salió hace media hora y que nadie
avisó. Ahí se pierde dinero y se pierde un cliente.

Esta aplicación hace visible esa coordinación.

## Las cuatro decisiones que definen el proyecto

### 1. El estado del pedido vive en el ítem, no en la comanda

Si la comanda tuviera un único estado ("en cocina"), habría que declararla
completa cuando uno de tres platos sale, y el salón dejaría de saber qué
falta. Con el estado por ítem, cada pedido tiene su propio tiempo, y la pantalla
ordena por antigüedad.

Un plato **listo que nadie reclama sigue contando tiempo** y sube en la lista.
Ese es el problema de gestión que justifica el sistema entero.

### 2. Los tiempos se calculan en memoria, contra Redis

La pantalla de cocina consulta cada 3 segundos. Calcular el tiempo con
`now() - enviado_at` en la base sería una consulta a PostgreSQL para obtener un
número que el servidor ya sabe calcular: qué hora es.

Las marcas de tiempo **sí** quedan en la base, porque son históricas. Si Redis
se pierde, `restaurante:reconstruir-reloj` repuebla el reloj desde la base.

### 3. Las bebidas no son platos

Un modelo con una sola "cocina" hace que una cerveza espere en la misma cola que
un pastel de choclo. Si no se distinguen estaciones, el tiempo de la bebida se
infla y el del plato se ve más rápido de lo que es: **los dos números mienten**.

Hay estaciones de tipo `comida`, `bebida` y `postura`, cada una con su tiempo
objetivo, y cada producto apunta a **su** estación.

### 4. La comanda cuelga de un grupo de mesas

Las mesas combinables son la excepción que rompe el modelo de "una mesa, una
comanda". En vez de agregar un caso especial, **una mesa sola es un grupo de un
miembro** y una mesa combinada es un grupo de dos. El caso corriente y el raro
comparten el mismo camino de código.

## Qué modela de verdad

| Detalle | Cómo |
|---|---|
| **Modificadores** | Datos, no texto. `agregado`, `sin_costo`, `remocion`, `cambio`, con precio, ingrediente y grupo de elección única o múltiple |
| **"Sin cebolla"** | Se valida contra la **receta**: si el plato no lleva cebolla, la remoción se rechaza en vez de aceptarse en silencio |
| **Precios** | Se **copian** al ítem. Si mañana suben el extra queso, la comanda de hoy sigue cuadrando |
| **Mesas combinables** | Grupos de mesas; la combinación no necesita ningún caso especial |
| **Turnos** | Ventanas de apertura del local, con soporte de turno que cruza la medianoche |
| **Roles** | Cinco, con "quien abre caja no es quien edita recetas" |
| **Bitácora** | Qué pasó, cuándo y quién, dentro de la misma transacción del cambio |

## La regla que protege el cobro

**Una comanda cobrada no se anula desde este sistema. Para ningún rol, incluido
el administrador.**

Un cobro realizado se revierte en el punto de venta que lo emitió, con su propia
autorización y su propia bitácora. Permitirlo aquí sería inventar una devolución
que nadie registró.

Está comprobado en `tests/Feature/Comandas/NoSeAnulaComandaCobradaTest.php`,
rol por rol, **con una contraprueba**: una comanda abierta sí se anula.

## Arquitectura

```
Internet → Caddy (único puerto público)
              ├── /api/*  → Nuxt (BFF: sesión, CSRF, OIDC)
              └── resto   → Nuxt SSR
                              └── reenvía a Laravel API
                                     ├── PostgreSQL 18 (RLS por tenant)
                                     └── Redis 8      (reloj de cocina)
```

El navegador nunca habla con la API. Eso mantiene la cookie de sesión en un solo
origen y evita CORS con credenciales, que duplica el riesgo de CSRF.

**Aislamiento:** columna `tenant_id` + Row Level Security de PostgreSQL, el mismo
patrón de `apps/portal-autoservicio`. Dos capas: las Policies filtran por rol, y
la base impide devolver filas ajenas aunque alguien olvide un `where`.

## Estructura

```
apps/restaurante/
├── README.md
├── .env.example
├── docs/         01 arquitectura · 02 modelo · 03 multitenancy · 04 instalación
│                 05 configuración · 06 primer turno · 07 seguridad
│                 08 respaldos · 09 API · 10 pruebas y estado
├── api/          Laravel 12 (14 migraciones, modelos, policies, servicios, pruebas)
├── web/          Nuxt 4 SSR (salón, comanda, cocina, administración)
└── deploy/       compose desarrollo y producción, Dockerfiles, Caddyfile
```

## Empezar

```bash
cp .env.example .env
# generar APP_KEY y NUXT_SESSION_PASSWORD
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d --build
```

Guía completa en [docs/04](docs/04-INSTALACION.md).

## Lo que NO hace

| No | Por qué |
|---|---|
| **Integración con el POS** | **No implementada.** Documentada como guía en [docs/09](docs/09-API.md). No se conoce el contrato del POS del cliente y en caja una suposición es un descuadre |
| **Anular un cobro** | Se hace en el POS, que es donde se emitió |
| **Emitir boletas** | Las emite el POS |
| **Mandar notificaciones** | No hay correos ni mensajes automáticos |
| **Controlar inventario** | Hay recetas, pero no se descuentan ingredientes al vender |
| **Contabilidad** | El POS es la autoridad |

## Seguridad

| Barrera | Qué impide |
|---|---|
| Row Level Security | Fuga entre restaurantes |
| `restaurante:verificar-aislamiento` | Que el rol de la base gane `BYPASSRLS` (correo al arrancar y a diario) |
| Policies | Un mesero que cobre, un cocinero que vea la caja |
| Máquina de estados | Anular lo que ya no se puede anular |
| CSRF + cookie sellada | Escrituras desde sitios ajenos, sesiones fabricadas |

**Riesgos abiertos**, todos declarados en [docs/10](docs/10-PRUEBAS-Y-ESTADO.md):
el SQL de las migraciones nunca se validó, la RLS no está probada, la CSP está en
`report-only` (no protege), no hay rate limiting, las imágenes no están fijadas por
digest y no hay revisión de seguridad externa.

## Advertencia que no es técnica

**Esto no está listo para abrir un restaurante.**

La rotación de turnos y el manejo de caja tienen implicaciones **fiscales y
laborales** que ninguna aplicación resuelve sola. En Chile, la jornada y la
rotación del personal tienen reglas propias, y el registro de caja es un documento
con valor tributario.

Un sistema de gestión de turnos y comandas **no es** un sistema de nóminas, ni
un libro de caja, ni un cumplimiento de normativa laboral. Entregar esto como
"sistema para abrir un restaurante" sería vender algo distinto de lo que es.

Lo que sí hace, bien construido: **coordinar salón y cocina y dejar registro de
lo que pasó.** Eso es un problema real, y lo ataca en el punto correcto. Lo demás
hay que resolverlo con las herramientas que existen para eso y con la asesoría que
corresponda.