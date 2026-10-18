# 07 - Seguridad

## 1. Amenaza principal

Que un mesero vea las comandas de otro restaurante, o que alguien anule un cobro
ya realizado.

Todo lo demás es menos importante que estas dos cosas.

## 2. Barreras

| Barrera | Qué impide | Dónde |
|---|---|---|
| Row Level Security | Fuga entre restaurantes | PostgreSQL |
| Verificación en el despliegue | Que el rol de la base gane `BYPASSRLS` | `restaurante:verificar-aislamiento` |
| Policies | Un mesero que cobre, un cocinero que vea la caja | `app/Policies/` |
| Máquina de estados | Anular algo que ya no se puede anular | `MaquinaDeEstadosComanda` |
| CSRF | Que una página ajeno escriba en la API | `ValidateCsrfToken` |
| Cookie sellada | Que alguien fabrique su propia sesión | `NUXT_SESSION_PASSWORD` |

La idea de fondo: **el aislamiento no depende del código de la aplicación**.
PostgreSQL no devuelve filas ajenas aunque alguien olvide un `where`.

## 3. Aislamiento entre restaurantes

Detalle completo en [03](03-MULTITENANCY-Y-ROLES.md). Lo esencial:

```sql
ALTER TABLE public.comandas FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_comandas ON public.comandas
    FOR ALL TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Y el contexto se fija con **alcance de transacción**, para que el worker de colas
no arrastre el restaurante de otro trabajo.

### El punto ciego declarado

`restaurantes` no tiene RLS porque se consulta sin contexto de restaurante: antes
de iniciar sesión no hay `app.tenant_id` que poner. Se compensa con que el rol de la
base no sea superusuario.

La vista `vw_restaurantes_publicos` (tres columnas: `slug`, `nombre`, `activo`)
**existe pero queda sin uso**: el código no la consulta. En la práctica esta API no
tiene selector de restaurante antes de autenticarse, y la única lectura sin contexto
es la tarea `restaurante:reconstruir-reloj`, que trae solo el `id` de cada
restaurante activo. Ver [03](03-MULTITENANCY-Y-ROLES.md) para el detalle.

**Riesgo residual:** el `slug` es público por diseño y aparece en la URL de entrada,
así que se puede enumerar que el restaurante existe. Con el código actual no se
devuelve antes de autenticarse, porque no hay selector que lo liste.

## 4. Autorización

### Dos capas, a propósito

| Capa | Responde |
|---|---|
| `ExigirRol` (middleware) | "¿Puede entrar a esta área?" |
| `Policies` | "¿Puede hacer esto, aquí, ahora?" |

El middleware solo evita que un cocinero alcance la lista de cajas. La Policy
decide si puede cobrar **esta** cuenta **de este** restaurante.

### Reglas que no se pueden cambiar por configuración

- Una comanda **cobrada** no se anula, para ningún rol.
- Un ítem **listo** no se anula.
- El rol de la base no puede ser superusuario.

No están en `.env` porque no son preferencias de instalación. Son las tres
condiciones sin las cuales el sistema no sirve para lo que existe.

## 5. Sesión y CSRF

### Por qué BFF

El navegador nunca habla con la API. Si lo hiciera, la cookie de sesión no
viajaría a otro origen y habría que resolverlo con CORS y credenciales, que
duplica el riesgo de CSRF.

Todo pasa por el mismo origen: el navegador llama a `/api/**` en el servidor web,
y ese servidor reenvía a Laravel junto con la cookie y el token CSRF.

### Lo que hace el proxy (`web/server/api/[...ruta].ts`)

1. Reenvía la cookie de sesión.
2. Reenvía el token CSRF como `X-XSRF-TOKEN`, leyéndolo de la sesión del
   servidor. **El navegador nunca lo ve.**
3. `redirect: 'manual'`: una redirección 3xx rompería el origen único.

Laravel aplica `ValidateCsrfToken` también al grupo `api`, porque la API usa
sesión con cookie y no token de API.

## 6. Identidad

Authentik, OIDC authorization code con PKCE.

### Lo que se verifica, en orden

1. Vienen `code` y `state`.
2. El `state` coincide con el guardado. Sin esto, un sitio podría iniciar
   sesión con la cuenta de la víctima.
3. Se canjea el código enviando el `code_verifier`, que nunca salió del servidor.
4. **Se verifica la firma del `id_token`** contra la llave pública de Authentik.
5. Se comprueban emisor, audiencia y expiración.

El paso 4 no es opcional. Sin verificar la firma, cualquiera que conozca el
`client_id` puede fabricar un `id_token` de administrador.

### Fallo conocido

**Si Authentik se cae, nadie entra.** El acceso es único para toda la
organización y un corte de Authentik deja al restaurante sin poder cobrar.

Mitigación obligatoria: un procedimiento de recuperación escrito y probado, o un
acceso local de emergencia. Está declarado como pendiente en
[10](10-PRUEBAS-Y-ESTADO.md).

## 7. Cabeceras

En `deploy/caddy/Caddyfile`:

| Cabecera | Valor |
|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `DENY` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | sin geolocalización, micrófono ni cámara |
| `Content-Security-Policy` | **en modo `report-only`** |

### Por qué la CSP está en report-only

Con la CSP activa, si falta un recurso **el local queda en blanco** y el mesero
no puede cobrar. El modo `report-only` deja observar qué reporta el navegador
antes de activar.

Consecuencia honesta: **mientras esté en report-only, la CSP no protege nada.**
Es un riesgo abierto y declarado. Activarla es trabajo de una sesión con
navegador real, y hasta entonces no hay que presentar esto como cerrado.

## 8. Datos que no se exponen

| Dato | Por qué no se expone |
|---|---|
| Token de acceso | Va en la sesión del servidor, nunca al navegador |
| Token CSRF | Sale del servidor en una cabecera, nunca al JavaScript de la página |
| Contraseñas de la base | Solo en el `.env` del servidor |
| `.env` | `root` de nginx apunta a `public/`; el archivo no es servible |

## 9. Lo que falta y hay que hacer antes de producción

| Falta | Por qué importa |
|---|---|
| **Fijar imágenes por digest SHA256** | Una etiqueta puede cambiar de contenido. Hoy están fijadas por tag exacto. |
| **Fijar la imagen `composer`** | Va sin versión en el Dockerfile. |
| **Revisión de seguridad externa** | No hay ninguna. |
| **Pruebas de penetración** | No hay ninguna. |
| **Activar la CSP** | Está en observación, no protegiendo. |
| **Rotación de credenciales** | No hay procedimiento escrito. |
| **Cerrar el límite de estación del cocinero** | Un cocinero autenticado puede marcar ítems de otra estación. |
| **Límite de peticiones (rate limiting)** | No hay ninguno en la API. En un local con IP propia el riesgo es menor, pero no es cero. |

## 10. Datos personales

Esta aplicación guarda el nombre, el correo y el identificador de Authentik de
cada persona, y el nombre que el comensal da al pagar su parte.

Relevante bajo la **Ley 19.628** de protección de datos personales. Si el
restaurante guarda identificaciones de comensales, hay que Informar sobre el
tratamiento y definir cuánto tiempo se conservan. **No está implementado** y hay
que decidirlo con el cliente antes de usarlo en un local real.