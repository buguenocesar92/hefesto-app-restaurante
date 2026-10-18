# 06 - Primer turno

Esta guía es para la persona de Hefesto que acompaña al mesero el primer día. No
es documentación técnica.

## 1. Antes del turno (30 minutos antes)

### 1.1 Cargar el turno

Sin un turno activo **nadie puede abrir una comanda ni cobrar**. El sistema lo
rechaza con un mensaje claro, pero conviene no depender de que el mesero lo
descubra en pleno servicio.

En administración, turnos:

| Turno | Desde | Hasta | Cruza medianoche |
|---|---|---|---|
| Almuerzo | 12:00 | 17:00 | No |
| Cena | 19:00 | 23:59 | No |
| Noche tardía (opcional) | 20:00 | 02:30 | **Sí** |

El turno de noche tiene `cruza_medianoche` activado porque, sin eso, a la una de
la mañana el sistema creería que el local está cerrado.

### 1.2 Cargar el catálogo

El orden importa:

1. **Estaciones**: cocina caliente, barra, postres, cada una con su tiempo
   objetivo.
2. **Ingredientes** y las recetas.
3. **Grupos de modificadores**: término de la carne, sin ingredientes,
   agregados.
4. **Productos**, cada uno con su estación.
5. **Vínculos** entre productos y grupos.

**Sobre los tiempos objetivo:** se pone el promedio real de cada estación y se
separan comida de bebida. Con el mismo objetivo para todo, el sistema no aporta
nada: una bebida que tarda 6 minutos aparece con la misma alarma que un plato que
tarda 14. Una semana de datos reales sirve para calibrar.

### 1.3 Cargar el salón

Zonas y mesas. Probar que dos mesas se pueden combinar en una sola comanda.

## 2. El turno

### 2.1 Abrir una mesa

1. Tocar la mesa libre.
2. Poner el número de comensales.

La mesa queda `ocupada` y aparece su comanda con folio.

### 2.2 Cargar un pedido

Productos → agregar. Cada ítem se puede dejar **pendiente** (todavía en el
salón) o **enviar a cocina** de inmediato.

En la práctica son dos gestos distintos y el sistema los mantiene distintos:

- **Pendiente**: el mesero está todavía corrigiendo el pedido.
- **Enviar a cocina**: la cocina tiene que verlo ya.

Si el cliente cambió de opinión después de enviar, el ítem ya salió de la
comanda del salón. Por eso conviene **enviar una sola vez, con el pedido
completo**.

### 2.3 La pantalla de cocina

La pantalla está junto a la estación y se actualiza sola cada 3 segundos.

Ordena por **antigüedad**, no por orden de llegada. El primer botón es el que
más se va a usar:

| Estado | Botón |
|---|---|
| Enviado | **Tomar** |
| En preparación | **Listo** |
| Listo | **Entregado** |

Un pedido **listo que nadie reclama sigue contando tiempo**, y sube en la
lista. Eso es intencional: es exactamente el problema que el sistema existe para
hacer visible.

**Regla de la casa:** marcar "Listo" es una promesa con nombre. Si el plato no
sale en dos minutos, alguien lo lleva. No se acepta "lo dejé listo y se me
olvidó".

### 2.4 Pedir la cuenta y cobrar

1. El mesero pide la cuenta: la comanda pasa a `por_cobrar` y la mesa a
   `por_cerrar`. La gente sigue sentada y el salón ya sabe que hay una cuenta
   pendiente.
2. Si el comensal pide algo más, se reabre la comanda.
3. El cajero divide la cuenta entre comensales y cobra.

**División:** el sistema reparte en partes lo más iguales posible y **el total
siempre calza exacto**. Si un comensal paga otra parte, se ajusta el monto a mano
y el sistema avisa si la suma no cuadra.

**Cobro:** se registra la forma de pago. La boleta la emite el punto de venta del
cliente; esta aplicación no la emite.

### 2.5 Anulaciones

Un mesero puede anular un ítem **mientras la comanda no esté cobrada**, y tiene
que escribir el motivo. La bitácora guarda quién y cuándo.

Lo que **no** se puede anular desde aquí:

- Una comanda **cobrada**, para ningún rol. La reversión se hace en el punto de
  venta, que es donde se emitió.
- Un plato que ya está **listo**. Ya está emplatado y en el pasillo: se cobra.

Si el comensal devuelve un plato, es un problema de caja, no de salón.

## 3. Cierre del turno

Antes de cerrar, el jefe de cocina revisa:

| Revisión | Por qué |
|---|---|
| **Pedidos en pantalla** | Alguien tiene que haberlos guardado. Un pedido `listo` a las 23:50 es una cena perdida. |
| **Comandas abiertas** | Una comanda abierta de la mañana es una mesa que se olvidó de pedir la cuenta. |
| **Corte de caja** | Lo que registra el punto de venta contra lo que registra esta aplicación. |

### El corte de caja es la comprobación que importa

Si los dos registros no coinciden, **no se ajusta a mano**: se anota la
diferencia y se investiga. Ajustar a mano destruye la evidencia de un problema
real y lo convierte en un problema crónico que nadie nota.

Las tres diferencias que hay que buscar:

1. **Cobrado y no entregado**: algo se cobró que nunca llegó a la mesa.
2. **Entregado y no cobrado**: la diferencia más común y la más fácil de
   arreglar.
3. **Anulado después de cobrar**: si aparece, hay que revisar la bitácora. Con
   esta aplicación no debería poder pasar.

## 4. Qué mirar la primera semana

| Indicador | Dónde | Para qué |
|---|---|---|
| Tiempo promedio por estación | Bitácora de la comanda | Ajustar los tiempos objetivo, que al principio son una estimación |
| Pedidos que pasaron más de 10 minutos en `listo` | Pantalla de cocina | Si son muchos, el problema es de personal, no de software |
| Anulaciones por motivo | Bitácora | "Sin cebolla" mal tipeados son dos cosas distintas que se corrigen distinto |
| Diferencias de caja diarias | Corte de caja | La señal más temprana de un problema real |

La tercera fila importa más de lo que parece: si aparecen muchas anulaciones con
el mismo motivo, el problema es que el menú no explica la opción, y eso se
arregla en el catálogo, no con el mesero.

## 5. Después del primer turno

Al día siguiente, en persona y sin apuro:

1. ¿Qué pantalla miraron primero? ¿La de cocina o el mapa del salón?
2. ¿Algún mensaje de error no se entendió?
3. ¿Se perdieron comandas o pedidos?
4. ¿El corte de caja calzó?

Estas cuatro preguntas valen más que cualquier prueba automática de las que
están escritas en el código y nunca se han corrido.