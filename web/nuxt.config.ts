// Configuracion de Nuxt (capa web) de la aplicacion de restaurante.
//
// ARQUITECTURA BFF
// ----------------
// Esta aplicacion es un "backend for frontend": el navegador nunca habla
// directo con la API de Laravel.
//
// El motivo es concreto. La sesion vive en una cookie HttpOnly + Secure +
// SameSite=Lax. Si el navegador llamara a otra origen (la API), la cookie no
// viajaria y habria que resolver el problema con CORS y credenciales, que
// duplica el riesgo de CSRF. Al pasar todo por el mismo origen (este servidor
// reenvia a la API), la cookie se mantiene en una sola origen y el token CSRF
// se reenvia como cabecera `X-XSRF-TOKEN`.
//
// Ver docs/01-ARQUITECTURA.md, seccion "Arquitectura BFF".
//
// LA PANTALLA DE COCINA ES UN CASO ESPECIAL
// -----------------------------------------
// Es la unica pantalla que sondea al servidor. Lo hace cada 3 segundos, que es
// el valor de `cocina.intervalo_refresco_ms`. No se puso a 1 segundo porque la
// pantalla esta junto a la cocina y recargar veinte pedidos una vez por segundo
// no le aporta nada al que cocina y hace mas lento el servidor.
// El tiempo que muestra llega YA calculado desde la API contra Redis: el
// navegador no calcula tiempos.

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: true,

  devtools: { enabled: false },

  runtimeConfig: {
    // URL de la API de Laravel. Es config PRIVADA: solo la usa el servidor.
    // Nunca debe exponerse al navegador.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',

    // Clave para sellar la cookie de sesion del lado de Nuxt. Sin ella la
    // sesion no se puede firmar y el inicio de sesion no funciona.
    sessionPassword: process.env.NUXT_SESSION_PASSWORD || '',

    public: {
      basePublico: process.env.NUXT_PUBLIC_BASE || 'http://localhost:3000',
      // Cada cuanto la pantalla de cocina pide el panel, en milisegundos.
      intervaloCocina: Number(process.env.NUXT_INTERVALO_COCINA_MS || 3000),
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Salón y Cocina',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content: 'Aplicacion de salon y cocina de Hefesto Studio.',
        },
      ],
    },
  },

  nitro: {
    routeRules: {
      '/api/**': { cors: false },
    },
  },
});