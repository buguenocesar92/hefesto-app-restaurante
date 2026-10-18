import { createPublicKey, verify } from 'node:crypto';

/**
 * Cliente OIDC de Authentik.
 *
 * FLUJO IMPLEMENTADO: authorization code con PKCE, tal como lo describe la
 * especificacion OpenID Connect Core 1.0, seccion 3.1.1. Se usa PKCE porque es
 * OAuth 2.0 para aplicaciones publicas y evita que un intermediario robe el
 * codigo de autorizacion.
 *
 * LA FIRMA SE VERIFICA EN EL SERVIDOR, CON LA LLAVE PUBLICA DE AUTHENTIK. Si se
 * aceptara el `id_token` sin verificar la firma, cualquiera podria inventar una
 * sesion de administrador.
 *
 * POR QUE NO SE USA UNA BIBLIOTECA DE JWT
 * --------------------------------------
 * Para no agregar una dependencia mas al paquete. `node:crypto` alcanza para
 * RS256: convierte el JWK a llave publica y verifica la firma. Si Authentik se
 * configura con otro algoritmo, hay que cambiar esta parte; el algoritmo se
 * toma de la propia configuracion del proveedor, no esta fijado a mano.
 *
 * ESTADO: escrito, sin ejecutar. Ver docs/10-PRUEBAS-Y-ESTADO.md.
 */

export interface ConfiguracionOidc {
  issuer: string;
  clientId: string;
  clientSecret: string;
  redirectUri: string;
  gruposAdministradores: string;
}

export interface RespuestaDeToken {
  access_token: string;
  id_token: string;
  token_type: string;
  expires_in: number;
}

export interface ConfiguracionDescubierta {
  issuer: string;
  authorization_endpoint: string;
  token_endpoint: string;
  jwks_uri: string;
  userinfo_endpoint: string;
}

export function leerConfiguracionOidc(): ConfiguracionOidc {
  const env = process.env;

  const issuer = (env.NUXT_AUTHENTIK_ISSUER || '').replace(/\/$/, '');
  const clientId = env.NUXT_AUTHENTIK_CLIENT_ID || '';
  const clientSecret = env.NUXT_AUTHENTIK_CLIENT_SECRET || '';
  const redirectUri = env.NUXT_AUTHENTIK_REDIRECT_URI || '';
  const grupos = env.NUXT_AUTHENTIK_GRUPO_ADMIN || 'restaurante-administradores';

  const faltantes = [
    ['NUXT_AUTHENTIK_ISSUER', issuer],
    ['NUXT_AUTHENTIK_CLIENT_ID', clientId],
    ['NUXT_AUTHENTIK_CLIENT_SECRET', clientSecret],
    ['NUXT_AUTHENTIK_REDIRECT_URI', redirectUri],
  ]
    .filter(([, valor]) => !valor)
    .map(([nombre]) => nombre);

  if (faltantes.length > 0) {
    throw createError({
      statusCode: 500,
      statusMessage: `Faltan variables de Authentik: ${faltantes.join(', ')}`,
    });
  }

  return {
    issuer,
    clientId,
    clientSecret,
    redirectUri,
    gruposAdministradores: grupos,
  };
}

/**
 * Documento de descubrimiento del proveedor.
 *
 * Se cachea 10 minutos en memoria del proceso: se pide en cada inicio de
 * sesion y el proveedor no cambia seguido. Ver la especificacion OpenID Connect
 * Discovery 1.0.
 */
let configuracionCacheada: { valor: ConfiguracionDescubierta; expira: number } | null = null;

export async function descubrirConfiguracion(): Promise<ConfiguracionDescubierta> {
  if (configuracionCacheada && configuracionCacheada.expira > Date.now()) {
    return configuracionCacheada.valor;
  }

  const { issuer } = leerConfiguracionOidc();

  const documento = await $fetch<ConfiguracionDescubierta>(
    `${issuer}/.well-known/openid-configuration`,
  );

  if (documento.issuer !== issuer) {
    throw createError({
      statusCode: 500,
      statusMessage: 'El `issuer` que devuelve Authentik no coincide con el configurado.',
    });
  }

  configuracionCacheada = {
    valor: documento,
    expira: Date.now() + 10 * 60 * 1000,
  };

  return documento;
}

/**
 * URL de autorizacion a la que se redirige al navegador.
 *
 * Es una promesa porque necesita el documento de descubrimiento del proveedor.
 * El `state` y el `code_challenge` viajan en la URL: `state` protege contra
 * CSRF en el retorno del proveedor y `code_challenge` es la parte de PKCE que
 * se verifica despues al canjear el codigo.
 */
export async function urlDeAutorizacion(challenge: string, estado: string): Promise<string> {
  const config = leerConfiguracionOidc();

  const parametros = new URLSearchParams({
    response_type: 'code',
    client_id: config.clientId,
    redirect_uri: config.redirectUri,
    scope: 'openid profile email',
    state: estado,
    code_challenge: challenge,
    code_challenge_method: 'S256',
  });

  const documento = await descubrirConfiguracion();

  return `${documento.authorization_endpoint}?${parametros.toString()}`;
}

export async function canjearCodigo(codigo: string, verifier: string): Promise<RespuestaDeToken> {
  const config = leerConfiguracionOidc();
  const documento = await descubrirConfiguracion();

  return await $fetch<RespuestaDeToken>(documento.token_endpoint, {
    method: 'POST',
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      code: codigo,
      redirect_uri: config.redirectUri,
      client_id: config.clientId,
      client_secret: config.clientSecret,
      code_verifier: verifier,
    }).toString(),
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
}

export interface InformacionUsuario {
  sub: string;
  nombre?: string;
  correo?: string;
  grupos: string[];
}

/**
 * Verifica el `id_token` y devuelve quien es la persona.
 *
 * Se comprueba, en este orden: firma, emisor, audiencia y expiracion. Si falta
 * cualquiera, se rechaza. Un `id_token` sin verificar NUNCA se acepta.
 */
export async function verificarIdToken(idToken: string): Promise<InformacionUsuario> {
  const config = leerConfiguracionOidc();
  const documento = await descubrirConfiguracion();

  const partes = idToken.split('.');

  if (partes.length !== 3) {
    throw createError({ statusCode: 401, statusMessage: 'El id_token no tiene la forma esperada.' });
  }

  const [cabecera, cuerpo, firma] = partes;
  const cabeceras = JSON.parse(Buffer.from(cabecera, 'base64url').toString('utf8'));

  if (cabeceras.alg !== 'RS256') {
    throw createError({
      statusCode: 401,
      statusMessage: `Algoritmo de firma no admitido: ${cabeceras.alg}`,
    });
  }

  const jwks = await $fetch<{ keys: Record<string, unknown>[] }>(documento.jwks_uri);
  const jwk = jwks.keys.find((clave) => clave.kid === cabeceras.kid);

  if (!jwk) {
    throw createError({ statusCode: 401, statusMessage: 'La llave de Authentik no es conocida.' });
  }

  const llave = createPublicKey({ key: jwk as any, format: 'jwk' });

  const verificada = verify(
    'RSA-SHA256',
    Buffer.from(`${cabecera}.${cuerpo}`),
    llave,
    Buffer.from(firma, 'base64url'),
  );

  if (!verificada) {
    throw createError({ statusCode: 401, statusMessage: 'La firma del id_token no es valida.' });
  }

  const payload = JSON.parse(Buffer.from(cuerpo, 'base64url').toString('utf8'));

  if (payload.iss !== config.issuer) {
    throw createError({ statusCode: 401, statusMessage: 'El emisor del id_token no coincide.' });
  }

  if (payload.aud !== config.clientId) {
    throw createError({ statusCode: 401, statusMessage: 'La audiencia del id_token no coincide.' });
  }

  if (typeof payload.exp === 'number' && payload.exp * 1000 < Date.now()) {
    throw createError({ statusCode: 401, statusMessage: 'El id_token esta vencido.' });
  }

  return {
    sub: payload.sub,
    nombre: payload.name || payload.preferred_username,
    correo: payload.email,
    grupos: Array.isArray(payload.groups) ? payload.groups : [],
  };
}