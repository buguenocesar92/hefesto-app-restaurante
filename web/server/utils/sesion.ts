import { createHash, randomBytes } from 'node:crypto';

/**
 * Sesion sellada del lado del servidor Nuxt.
 *
 * POR QUE HAY UNA SESION EN NUXT Y NO SOLO LA DE LARAVEL
 * ---------------------------------------------------
 * El BFF necesita guardar el token CSRF y algunos datos de identidad para
 * reenviarlos a Laravel, y no puede leerlos de la cookie de Laravel sin
 * descifrar su formato. Se guarda una sesion propia, sellada con una clave.
 *
 * `useSession` de h3 sella la cookie con AES-256-GCM cuando se le pasa
 * `password`. La cookie va HttpOnly, Secure en produccion y SameSite=Lax.
 *
 * LA CLAVE (`NUXT_SESSION_PASSWORD`) ES LO UNICO QUE IMPIDE QUE UNO FABRIQUE
 * SU PROPIA SESION. Se genera con `openssl rand -base64 32` y no se comparte.
 * Sin ella, el sistema no arranca: se prefiere fallar a arrancar con una clave
 * debil o vacia.
 */
export function useSessionSesion(event: any) {
  const config = useRuntimeConfig(event);

  const clave = config.sessionPassword as string;

  if (!clave || clave.length < 32) {
    throw createError({
      statusCode: 500,
      statusMessage:
        'NUXT_SESSION_PASSWORD no esta configurado o es demasiado corto. '
        + 'Generar con: openssl rand -base64 32',
    });
  }

  return useSession(event, {
    name: 'restaurante_sesion',
    password: clave,
    maxAge: 60 * 60 * 12, // Doce horas: cubre un turno largo y una cena.
    cookie: {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      path: '/',
    },
  });
}

export interface DatosDeSesion {
  accessToken?: string;
  csrfToken?: string;
  nombre?: string;
  correo?: string;
  // El grupo de Authentik decide el rol inicial. La API es la autoridad: este
  // dato sirve para mostrar el nombre, no para autorizar.
  grupos?: string[];
}

/** challenge y verifier de PKCE, en base64url. */
export function generarPkce() {
  const verifier = randomBytes(32).toString('base64url');
  const challenge = createHash('sha256').update(verifier).digest('base64url');

  return { verifier, challenge };
}

export function generarTokenCsrf(): string {
  return randomBytes(32).toString('base64url');
}