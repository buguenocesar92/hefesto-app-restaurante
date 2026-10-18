<script setup lang="ts">
// Composables de acceso a la API.
//
// TODAS las llamadas pasan por `/api/**`, que es el BFF de este mismo
// servidor. El navegador nunca ve la direccion de la API de Laravel: la cookie
// de sesion y el token CSRF viajan en el mismo origen y por eso no hace falta
// CORS ni credenciales.
//
// La respuesta de error se normaliza a un objeto con `mensaje`, porque la API
// devuelve 403 con un texto que hay que mostrarle a la persona y no un
// `error.statusCode` que nadie entiende.

export interface ErrorDeApi {
  mensaje: string;
  codigo: number;
}

export function useApi() {
  async function pedir<T>(ruta: string, opciones: Record<string, unknown> = {}): Promise<T> {
    try {
      return await $fetch<T>(`/api${ruta}`, {
        ...opciones,
        credentials: 'include',
      });
    } catch (error) {
      throw normalizar(error);
    }
  }

  function normalizar(error: unknown): ErrorDeApi {
    const cualquiera = error as { data?: { mensaje?: string }; statusCode?: number; message?: string };

    return {
      mensaje: cualquiera?.data?.mensaje || 'No se pudo completar la operacion.',
      codigo: cualquiera?.statusCode || 500,
    };
  }

  return {
    pedir,
    salon: () => pedir<{ zonas: ZonaSalon[] }>('/salon'),
    comandas: () => pedir<{ data: Comanda[] }>('/comandas'),
    comanda: (id: number) => pedir<{ data: Comanda }>(`/comandas/${id}`),
    panelCocina: (estacion?: number) =>
      pedir<{ estaciones: PanelEstacion[] }>(`/cocina/panel${estacion ? `?estacion_id=${estacion}` : ''}`),
  };
}

// Tipos minimos de lo que la interfaz necesita saber. Duplicar el contrato
// completo del backend aqui no aporta nada y obliga a mantenerlo dos veces.

export interface MesaSalon {
  id: number;
  nombre: string;
  capacidad: number;
  estado: 'libre' | 'ocupada' | 'por_cerrar';
  grupo_id: number | null;
  comanda: ResumenComanda | null;
}

export interface ZonaSalon {
  id: number;
  nombre: string;
  orden: number;
  mesas: MesaSalon[];
}

export interface ResumenComanda {
  id: number;
  folio: number;
  estado: 'abierta' | 'por_cobrar' | 'cobrada' | 'anulada';
  comensales: number;
  items_vivos: number;
  pendientes: number;
  en_cocina: number;
}

export interface ModificadorItem {
  id: number;
  nombre: string;
  tipo: string;
  precio: number;
}

export interface ItemComanda {
  id: number;
  nombre: string;
  cantidad: number;
  precio_unitario: number;
  subtotal: number;
  estado: string;
  estado_label: string;
  notas_preparacion: string | null;
  modificadores: ModificadorItem[];
  motivo_anulacion: string | null;
}

export interface Comanda {
  id: number;
  folio: number;
  etiqueta: string;
  estado: string;
  comensales: number;
  notas: string | null;
  admite_items: boolean;
  admite_anulacion: boolean;
  mesas: { id: number; nombre: string; capacidad: number }[];
  combinacion: boolean;
  items: ItemComanda[];
}

export interface PedidoCocina {
  item_id: number;
  comanda_id: number;
  estado: string;
  nombre: string;
  cantidad: number;
  comensales: number;
  notas: string | null;
  modificadores: string[];
  segundos: number;
  objetivo_segundos: number;
  atrasado: boolean;
}

export interface PanelEstacion {
  estacion: {
    id: number;
    nombre: string;
    tipo: 'comida' | 'bebida' | 'postura';
    objetivo_segundos: number;
  };
  pedidos: PedidoCocina[];
}