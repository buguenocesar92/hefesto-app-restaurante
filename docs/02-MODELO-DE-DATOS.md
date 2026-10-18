# 02 - Modelo de datos

Todas las tablas de negocio llevan `tenant_id`. El tenant de esta aplicación es
un **restaurante**. Ver [03](03-MULTITENANCY-Y-ROLES.md) para el aislamiento.

## 1. Mapa de tablas

| Grupo | Tablas |
|---|---|
| Identidad | `restaurantes`, `usuarios` |
| Turnos | `turnos`, `turno_asignaciones` |
| Salón | `zonas`, `mesas`, `grupos_mesas`, `grupo_mesas_mesas` |
| Preparación | `estaciones` |
| Catálogo | `categorias_productos`, `productos` |
| Modificadores | `ingredientes`, `receta_ingredientes`, `grupos_modificadores`, `modificadores`, `producto_grupos_modificadores` |
| Comanda | `comandas`, `comanda_items`, `comanda_item_modificadores`, `comanda_eventos`, `contadores_folio` |
| Cuenta | `cuentas`, `cuenta_divisiones`, `pagos` |

## 2. La comanda

```
comandas
  ├── comanda_items          (uno por pedido; tiene su propio estado)
  │     └── comanda_item_modificadores
  ├── comanda_eventos        (bitácora)
  └── cuentas
        ├── cuenta_divisiones
        └── pagos
```

### Estados de la comanda

| Estado | Significa | Admite items | Admite anulación |
|---|---|---|---|
| `abierta` | En servicio | Sí | Sí |
| `por_cobrar` | Llegó la cuenta | No | Sí |
| `cobrada` | Cobro hecho en el POS | No | **No** |
| `anulada` | Anulada | No | No |

`por_cobrar` es un estado propio y no un detalle de `abierta`: cuando el mesero
pide la cuenta, la mesa deja de aceptar pedidos. Mezclarlo en un solo estado
obligaría a guardar la misma verdad en un campo aparte, y los dos se pueden
desincronizar.

**`cobrada` es terminal y no tiene salida.** Ver sección 6.

### Estados del ítem

| Estado | Qué significa | En pantalla de cocina |
|---|---|---|
| `pendiente` | En la comanda del salón, cocina aún no lo ve | No |
| `enviado` | Visible en cocina. Empieza a contar el tiempo | Sí |
| `en_preparacion` | Alguien tomó el pedido | Sí |
| `listo` | En el pasillo, esperando que alguien lo lleve | Sí |
| `entregado` | Llegó al comensal | No |
| `anulado` | Eliminado antes de la cuenta | No |

Dos transiciones parecen arbitrarias y no lo son:

- **`enviado → listo` está permitido sin pasar por `en_preparacion`.** En un
  local real el cocinero marca el terminado y el sistema marca "en
  preparación" cuando alguien toma el pedido. Entre las dos cosas hay un botón,
  no dos. Exigir las dos hace que la gente apriete mal y se pierda el dato.

- **`listo → anulado` NO está permitido.** Un plato que ya está emplatado no se
  cancela: se cobra. Permitirlo dejaría el pasillo con un plato que alguien
  cobró y otro devolvió.

## 3. Modificadores: datos, no texto

"Sin cebolla, extra queso" no es un texto con el precio pegado. Un texto libre
no permite cobrar el extra, ni saber cuántas porciones se pidieron, ni
consultar qué platos se pueden preparar sin algo.

Hay cuatro piezas:

| Pieza | Para qué |
|---|---|
| `ingredientes` | Inventario. Lleva el marcado de alergenos (Ley 20.606). |
| `receta_ingredientes` | Qué lleva cada producto. Permite **validar** una remoción. |
| `grupos_modificadores` | La caja donde el comensal elige. |
| `modificadores` | Las opciones, con precio y tipo. |

### Tipos de modificador

| Tipo | Ejemplo | Precio | Ingrediente |
|---|---|---|---|
| `agregado` | Extra queso | Suma | No obligatorio |
| `sin_costo` | Sin hielo | No suma | No |
| `remocion` | Sin cebolla | No suma | **Sí, obligatorio** |
| `cambio` | Al punto, tres cuartos | No suma | Sí |

La distinción entre `remocion` y `sin_costo` no es académica: una remoción tiene
que apuntar a un ingrediente que **esté en la receta** del producto. Si el plato
no lleva cebolla, "sin cebolla" no es una petición válida y el sistema lo
rechaza en vez de aceptarlo en silencio.

Un modificador que se acepta y no se puede cumplir llega igual a la pantalla de
cocina. Peor que rechazarlo.

### Elección única y múltiple

`grupos_modificadores.seleccion` distingue lo que un menú corriente mezcla:

- **Única**: término de la carne (al punto / tres cuartos / cocido). Marcar dos
  es un error de captura, y el sistema lo rechaza.
- **Múltiple**: preferencias (sin cebolla, sin tomate).

La obligatoriedad se puede resolver por producto:
`producto_grupos_modificadores.obligatorio` sobrescribe el valor del grupo. El
mismo grupo puede ser obligatorio para el lomo y opcional para una entrada, sin
duplicarlo.

### Los precios se copian

`comanda_items.precio_unitario`, `estacion_id`, `nombre_producto`,
`tiempo_objetivo_segundos` y todos los campos de
`comanda_item_modificadores` son **copias** del catálogo.

Si mañana suben el extra queso de 1.500 a 1.800, la comanda de hoy tiene que
seguir cuadrando con los 1.500 que se cobraron. Un catálogo que se puede
reescribir es un catálogo que rompe la historia.

## 4. Mesas combinables

```
grupos_mesas
  └── grupo_mesas_mesas ──> mesas
```

**La comanda apunta a `grupos_mesas`, nunca a `mesas`.**

- Una mesa sola: un grupo con un miembro.
- Una mesa combinada: un grupo con dos o más.

El motivo de no usar `comanda.mesa_id` más un booleano `combinada`: el caso
normal pagaría un modelo complicado para siempre, y la combinación sería siempre
el camino raro, con sus propios casos en cada endpoint.

`mesas.estado` está desnormalizado a propósito: el mapa del salón dibuja muchas
mesas y no puede pagar una consulta al grupo por cada una. La fuente de verdad
es la existencia de una comanda viva en el grupo.

| `mesas.estado` | Cuándo |
|---|---|
| `libre` | Sin comanda viva |
| `ocupada` | Comanda `abierta` |
| `por_cerrar` | Comanda `por_cobrar`: la gente sigue sentada y el salón ya sabe que hay cuenta |

## 5. Bitácora

`comanda_eventos` registra qué pasó, cuándo y quién lo hizo. Se escribe **dentro
de la misma transacción** que el cambio de estado: si el cambio se confirma, el
evento existe; nunca hay uno sin el otro.

Responde a las tres preguntas del día a día:

- "¿Cuándo mandé este plato?"
- "¿Quién lo anuló y por qué?"
- "Este plato quedó listo a las 21:40 y nadie lo llevó hasta las 22:10: ¿estaba
  en el pasillo o nunca se marcó?"

## 6. La regla del cobro

`EstadoComanda::admiteAnulacion()` devuelve **falso** para `cobrada`, y
`MaquinaDeEstadosComanda` no tiene transición desde `cobrada` a ninguna parte.

Un cobro realizado se revierte en el punto de venta que lo emitió, con su propia
autorización y su propia bitácora. Permitir la anulación aquí sería inventar una
devolución que nadie registró.

Está comprobado en `tests/Feature/Comandas/NoSeAnulaComandaCobradaTest.php`,
rol por rol, incluido el administrador.

## 7. Cuentas

- `cuentas`: el total de la comanda.
- `cuenta_divisiones`: las partes. Guardan **su monto**, no solo el número de
  comensal: una mesa de cuatro donde el niño no paga se expresa ajustando
  montos.
- `pagos`: forma de pago y referencia.

**El reparto tiene que calzar exacto.** Con precios en pesos enteros, dividir
10.000 entre 3 da 3.333,33. El reparto da 3.334 / 3.333 / 3.333. La diferencia
de un peso si no se reparte la absorbe alguien, normalmente el mesero, de su
bolsillo.

`pagos.referencia_pos` queda **vacía** mientras no exista la integración con el
punto de venta. Un dato inventado en la columna de referencia es peor que un
dato ausente, porque parece confiable.

## 8. Turnos

Un turno no es el turno de una persona: es la franja en que el local abre
(almuerzo, cena, noche). Las personas se asignan en `turno_asignaciones`.

`cruza_medianoche` existe porque una noche de 20:00 a 03:00 rompe la
comparación ingenua de horas. A la una de la mañana, un mesero de madrugada
tenía que poder cobrar.

## 9. Folio

`contadores_folio` usa `INSERT ... ON CONFLICT DO UPDATE ... RETURNING`, que
bloquea la fila en PostgreSQL. La alternativa (leer, sumar, guardar) tiene una
ventana en la que dos meseros sacan el mismo folio, y dos comandas con el mismo
número en un local es un problema de servicio que nadie sabe después si se cobró
bien.