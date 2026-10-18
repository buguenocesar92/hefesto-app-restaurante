# 09 - API

> **Estado: no se ha ejecutado.** Ninguna ruta se probó en un servidor real. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).

## 1. Convenciones

- **Sesión con cookie**, no token. Por eso hay CSRF también en la API.
- **Español** en los mensajes de error, escritos para quien los va a leer.
- **404, no 403**, cuando el recurso pertenece a otro restaurante: un 403
  confirmaría que existe.
- **422** para reglas de negocio (`DomainException`): no es un error del sistema.
- Las rutas se versionan bajo `/api`.

El acceso real es `/api/**` en el servidor web, que reenvía a Laravel
(arquitectura BFF). Ver [01](01-ARQUITECTURA.md).

## 2. Rutas

### Salud

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/salud-interno` | No | Ninguno |

Se usa para el orquestador. No requiere sesión: tiene que responder cuando el
resto todavía no está listo.

### Salón

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/salon` | No | Todos |

Devuelve zonas, mesas y el resumen de la comanda viva de cada una, en una
consulta. El mapa del salón se recarga cada 10 segundos en la interfaz.

### Comandas

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/comandas` | No | Todos |
| POST | `/api/comandas` | **Sí** | mesero, cajero, administrador |
| GET | `/api/comandas/{comanda}` | No | Todos menos cocinero |
| GET | `/api/comandas/{comanda}/eventos` | No | Todos menos cocinero |
| POST | `/api/comandas/{comanda}/items` | **Sí** | mesero, cajero, administrador |
| POST | `/api/comandas/{comanda}/enviar-a-cocina` | **Sí** | mesero, cajero, administrador |
| POST | `/api/comandas/{comanda}/items/{item}/anular` | **Sí** | mesero, cajero, jefe de cocina, administrador |
| POST | `/api/comandas/{comanda}/pedir-cuenta` | **Sí** | mesero, cajero, administrador |
| POST | `/api/comandas/{comanda}/reabrir` | **Sí** | mesero, cajero, administrador |
| POST | `/api/comandas/{comanda}/anular` | **Sí** | mesero, cajero, jefe de cocina, administrador |

### Cocina

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/cocina/panel` | **No** | cocinero, jefe de cocina, administrador |
| POST | `/api/cocina/items/{item}/estado` | **No** | cocinero, jefe de cocina, administrador |

**Sin turno a propósito.** Al cerrar el local quedan pedidos en el pasillo y
alguien tiene que poder marcarlos y registrarlos.

### Cuentas

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/cuentas/{cuenta}` | No | mesero, cajero, administrador |
| POST | `/api/comandas/{comanda}/cuenta` | **Sí** | mesero, cajero, administrador |
| POST | `/api/cuentas/{cuenta}/dividir` | **Sí** | mesero, cajero, administrador |
| POST | `/api/cuentas/{cuenta}/cobrar` | **Sí** | cajero, administrador |

### Administración

| Método | Ruta | Turno | Rol |
|---|---|---|---|
| GET | `/api/admin/productos` | No | administrador |
| GET | `/api/admin/productos/{producto}` | No | administrador |
| POST | `/api/admin/productos` | No | administrador |
| PATCH | `/api/admin/productos/{producto}/precio` | No | administrador |

## 3. Formato

### Abrir comanda

```http
POST /api/comandas
Content-Type: application/json

{
  "grupo_mesas_id": 3,
  "comensales": 4,
  "notas": "Mesa de cuatro. Una persona vegetariana."
}
```

### Agregar un ítem

```http
POST /api/comandas/12/items

{
  "producto_id": 45,
  "cantidad": 2,
  "modificadores": [8, 11],
  "notas_preparacion": "Bien pasado",
  "enviar_a_cocina": true
}
```

`modificadores` son **identificadores, no texto**. El servidor valida que
apliquen a ese producto, que la remoción tenga sentido según la receta y que un
grupo de elección única no reciba dos opciones.

Respuesta si el modificador no corresponde:

```json
{
  "mensaje": "No se puede quitar \"Sin cebolla\" porque \"Pescado a la plancha\" no lleva ese ingrediente en su receta."
}
```

### Panel de cocina

```http
GET /api/cocina/panel
```

```json
{
  "generado_en": "2026-10-17T21:40:00-03:00",
  "estaciones": [
    {
      "estacion": { "id": 1, "nombre": "Cocina caliente", "tipo": "comida", "objetivo_segundos": 900 },
      "pedidos": [
        {
          "item_id": 88,
          "comanda_id": 12,
          "estado": "en_preparacion",
          "nombre": "Lomo saltado",
          "cantidad": 2,
          "comensales": 4,
          "notas": null,
          "modificadores": ["Sin cebolla", "Extra queso"],
          "segundos": 1180,
          "objetivo_segundos": 900,
          "atrasado": true
        }
      ]
    }
  ]
}
```

`segundos` llega **ya calculado** contra Redis. El navegador no lo recalcula: si
lo hiciera, el teléfono del cocinero con la hora corrida haría que la pantalla
dijera una cosa y el servidor otra.

`atrasado` se compara contra el objetivo **del ítem**, no contra un promedio de
la semana. Es un umbral de gestión interna.

### Cambiar el estado de un pedido

```http
POST /api/cocina/items/88/estado

{ "estado": "listo" }
```

Transición no permitida:

```json
{
  "mensaje": "Un item listo ya no se anula: se cobra. Si el comensal devuelve el plato, es un problema de caja y se resuelve en el punto de venta."
}
```

### Anular

```http
POST /api/comandas/12/items/88/anular

{ "motivo": "El comensal se arrepintio de este plato" }
```

`motivo` es obligatorio con largo mínimo: "sin cebolla" y "el comensal se
arrepentió" son motivos distintos y el restaurante los distingue, porque afectan
a la preparación que se descartó.

## 4. Integración con el punto de venta: guía, no implementación

**La integración NO está implementada.** No hay cliente, no hay rutas, no hay
pruebas. Esta sección describe qué habría que hacer y qué hay que decidir antes.

### Por qué no está

1. **No se conoce el contrato.** El POS del cliente es un producto concreto con
   su documentación propia. Inventar rutas y campos sería escribir código contra
   una suposición, y en caja una suposición es un descuadre.
2. **La autoridad del cobro es del POS.** Si esta aplicación emitiera o
   anulara documentos, habría dos sistemas con autoridad sobre el mismo hecho.
3. **El camino feliz ya funciona sin ella.** El salón registra la cuenta y los
   pagos; el POS cobra. La integración es una mejora, no un requisito para
   operar.

### Lo que ya está listo

| Pieza | Para qué |
|---|---|
| `pagos.referencia_pos` | Guarda el identificador del cobro en el POS. Hoy queda **vacía**. |
| `pagos.forma_pago` | Efectivo, débito, crédito, transferencia. Los códigos siguen la convención del POS del cliente; **el mapeo exacto está pendiente de confirmar**. |
| `productos.codigo` | El código con el que el POS conoce cada producto. |
| Bitácora completa | Para conciliar ambos registros. |

La columna de referencia existe ahora para que el día de la integración el dato
esté disponible sin cambiar el historial. **Nunca se inventó un valor** en ella:
un dato inventado en la columna de referencia es peor que un dato ausente,
porque parece confiable.

### Lo que habría que decidir

| Decisión | Por qué importa |
|---|---|
| ¿Qué POS usa el cliente? | Sin la documentación oficial no se puede escribir una línea. |
| ¿Quién es la autoridad del precio? | Hoy el catálogo de esta aplicación y el del POS son independientes. Si divergen, el salón cobra una cosa y el POS otra. |
| ¿Cómo se concilia al cierre? | Un corte de caja que no calza necesita un procedimiento, no una herramienta. |
| ¿Cómo se anula una venta? | Hoy no se puede desde aquí, a propósito. Con integración, la reversión sería una operación del POS con su propia autorización. |
| ¿Y la boleta? | La emite el POS. Esta aplicación no debería emitir nunca. |

### Dirección sugerida

```
POS  ──(consulta de productos)──>  esta aplicación
POS  ──(cobro)──────────────────>  esta aplicación   [NO IMPLEMENTADO]
```

En ese sentido, esta aplicación es **slave** del POS para el cobro y **master**
para la coordinación de salón y cocina, que es lo que hace bien.

## 5. Códigos de error

| Código | Significado |
|---|---|
| 401 | Sin sesión, o sesión vencida |
| 403 | El rol no permite esta acción |
| 404 | No existe, **o** es de otro restaurante |
| 419 | Token CSRF ausente o vencido |
| 422 | Regla de negocio: datos inválidos, transición prohibida, modificador que no aplica |
| 500 | Error del sistema. El detalle va al registro del servidor, nunca al navegador |

Los mensajes de 4xx están escritos para quien los ve, no para un desarrollador:
"Una comanda cobrada no se anula desde este sistema: la reversión se hace en el
punto de venta que emitió el cobro." dice qué hacer.