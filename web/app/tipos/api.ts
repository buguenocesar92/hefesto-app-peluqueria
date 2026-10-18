/**
 * Tipos de la API de Peluquería.
 *
 * ESTOS TIPOS NO SON UNA FUENTE DE PERMISOS
 * ------------------------------------------
 * Describen lo que la API devuelve. Lo que una persona puede hacer lo decide el
 * servidor, en `App\Policies`, y el campo `capacidades` solo existe para que la
 * interfaz no muestre botones que van a ser rechazados.
 *
 * Ocultar un botón NO es una medida de seguridad: alguien puede llamar a la API
 * directamente. Ver docs/03.
 *
 * TODOS LOS CAMPOS VIENEN EN ESPAÑOL Y CON NUDOS
 * ----------------------------------------------
 * Es la convención de la API (`snake_case`, `etiqueta`, `valor`). Se mantiene
 * porque traducir en el camino inverso es donde aparecen los errores: un `estado`
 * que en la base es `'no_asistio'` y en la interfaz es un color equivocado
 * termina pareciendo una asistencia.
 *
 * LOS MONTOS SON ENTEROS
 * ---------------------
 * La API no devuelve decimales. Un salón en Chile no cobra fracciones de peso, y
 * una comisión que se calcula en coma flotante acumula error. Ver
 * App\Services\Comisiones\CalculadoraDeComision.
 */

export interface Capacidades {
    ver_agenda: boolean
    agendar_citas: boolean
    anular_citas: boolean
    cerrar_ventas: boolean
    cobrar_ventas: boolean
    gestionar_clientes: boolean
    ver_antecedentes: boolean
    registrar_antecedentes: boolean
    marcar_bloqueos: boolean
    cortar_caja: boolean
    ver_comisiones: boolean
    liquidar_comisiones: boolean
    administra_peluqueria: boolean
    gestionar_servicios: boolean
    gestionar_productos: boolean
    gestionar_profesionales: boolean
    gestionar_politicas_comision: boolean
    gestionar_usuarios: boolean
    ver_auditoria: boolean
    ver_reportes: boolean
}

export interface UsuarioSesion {
    id: string
    nombre: string
    correo: string
    rol: 'recepcion' | 'profesional' | 'administrador'
    rol_etiqueta: string
    /** En `null` si esta cuenta no es una profesional del salón. */
    profesional_id: string | null
    salon: {
        id: string | null
        nombre: string | null
        nombre_corto: string | null
    } | null
    capacidades: Capacidades
}

/** Lo único que se lee de un salón antes del ingreso. */
export interface SalonPublico {
    slug: string
    nombre: string
}

export interface Profesional {
    id: string
    nombre: string
    especialidad: string | null
    silla: string | null
    correo?: string | null
    telefono?: string | null
    registro_profesional: string | null
    registro_verificado: boolean
    color_agenda: string
    activo: boolean
    servicios?: ServicioDeProfesional[]
}

export interface ServicioDeProfesional {
    id: string
    nombre: string
    /** Duración efectiva para esa profesional: la suya si la tiene, si no la del catálogo. */
    duracion_minutos: number
    precio: number
}

export interface Servicio {
    id: string
    nombre: string
    descripcion: string | null
    duracion_minutos: number
    precio_base: number
    categoria: string | null
    es_reposicion: boolean
    activo: boolean
}

export interface Producto {
    id: string
    nombre: string
    descripcion: string | null
    marca: string | null
    precio_venta: number
    stock: number | null
    unidad: string
    activo: boolean
}

/** Una clienta en el listado. */
export interface Clienta {
    id: string
    nombre: string
    apellido: string | null
    nombre_completo: string
    rut: string | null
    telefono: string | null
    correo: string | null
    activo: boolean
}

/** Una cita dentro del historial de la ficha. Es un resumen, no la cita entera. */
export interface CitaEnFicha {
    id: string
    fecha_inicio: string
    servicio: { id: string; nombre: string } | null
    estado: EstadoCita
    estado_etiqueta: string
    minutos_retraso: number | null
}

/** Una venta dentro del historial de la ficha. */
export interface VentaEnFicha {
    id: string
    fecha: string
    profesional: { id: string; nombre: string } | null
    total: number
    total_servicios: number
    total_productos: number
    estado: 'borrador' | 'cobrada' | 'anulada'
    estado_etiqueta: string
}

/**
 * La ficha completa de una clienta.
 *
 * El historial (citas y ventas) viene con la ficha. Los antecedentes de salud NO:
 * van en otra ruta, con otro permiso y con auditoría. Ver docs/07.
 */
export interface FichaClienta extends Clienta {
    fecha_nacimiento: string | null
    notas: string | null
    citas: CitaEnFicha[]
    ventas: VentaEnFicha[]
}

/** Lo que devuelve la ruta de antecedentes, con su aviso de lo que no se comprueba. */
export interface RespuestaAntecedentes {
    datos: AntecedenteSalud[]
    /** Siempre `false`. Ver docs/02. */
    espera_entre_procesos_comprobada: boolean
    aviso_espera: string
}

/** Un antecedente de salud. Es DATO SENSIBLE: ver docs/07. */
export interface AntecedenteSalud {
    id: string
    tipo: 'alergia' | 'contraindicacion' | 'condicion' | 'sensibilidad'
    tipo_etiqueta: string
    descripcion: string
    severidad: 'leve' | 'moderada' | 'grave'
    severidad_etiqueta: string
    bloquea_servicios: boolean
    servicios_afectados: string[] | null
    activo: boolean
}

export interface AvisoSalud {
    id: string
    tipo: string
    tipo_etiqueta: string
    descripcion: string
    severidad: string
    severidad_etiqueta: string
    bloquea_servicios: boolean
}

export type EstadoCita = 'agendada' | 'confirmada' | 'en_curso' | 'atendida' | 'no_asistio' | 'anulada'

export interface Cita {
    id: string
    fecha_inicio: string
    fecha_fin: string
    duracion_minutos: number
    estado: EstadoCita
    estado_etiqueta: string
    silla: string | null
    hora_inicio_real: string | null
    hora_fin_real: string | null
    minutos_retraso: number | null
    profesional: { id: string; nombre: string; color_agenda: string; silla: string | null } | null
    servicio: { id: string; nombre: string; duracion_minutos: number } | null
    clienta: { id: string; nombre: string; apellido: string | null; rut: string | null } | null
    avisos_salud?: AvisoSalud[]
    requiere_revision?: boolean
}

export interface BloqueoAgenda {
    id: string
    fecha_inicio: string
    fecha_fin: string
    motivo: string
    todo_el_salon: boolean
    profesional: { id: string; nombre: string } | null
}

export interface RespuestaAgenda {
    fecha: string
    total: number
    con_avisos: number
    citas: Cita[]
    bloqueos: BloqueoAgenda[]
    /** Siempre `false`: la espera entre procesos químicos no se comprueba. Ver docs/02. */
    espera_entre_procesos_comprobada: boolean
}

export interface RespuestaDisponibilidad {
    puede_agendar: boolean
    conflictos: string[]
    duracion_minutos: number
    fecha_fin_estimada: string
    espera_entre_procesos_comprobada: boolean
}

export type TipoLineaVenta = 'servicio' | 'producto'

export interface LineaVenta {
    id: string
    tipo: TipoLineaVenta
    tipo_etiqueta: string
    descripcion: string
    cantidad: number
    precio_unitario: number
    subtotal: number
}

export interface Venta {
    id: string
    fecha: string
    estado: 'borrador' | 'cobrada' | 'anulada'
    estado_etiqueta: string
    forma_pago: string | null
    total_servicios: number
    total_productos: number
    total: number
    profesional: { id: string; nombre: string } | null
    clienta: { id: string; nombre: string; apellido: string | null } | null
    lineas: LineaVenta[]
}

/* ------------------------------------------------------------------
 | Comisiones
 | ------------------------------------------------------------------ */

export type BaseCalculo = 'servicio' | 'producto' | 'ambos'

export interface PoliticaComision {
    id: string
    profesional_id: string | null
    profesional: { id: string; nombre: string } | null
    es_general: boolean
    /** Porcentaje como texto con dos decimales: "35.50", no 0.3550. */
    porcentaje_servicios: string
    porcentaje_productos: string
    incluye_productos: boolean
    base_calculo: BaseCalculo
    base_calculo_etiqueta: string
    vigente_desde: string
    vigente_hasta: string | null
    notas: string | null
}

/**
 * Una línea del desglose de comisión.
 *
 * ESTOS CUATRO DATOS SON LOS QUE HACEN LA COMISIÓN AUDITABLE: de qué venta
 * salió, sobre qué base, con qué porcentaje y cuánto dio. Con eso la persona que
 * recibe la cuenta puede rehacerla con una calculadora.
 */
export interface LineaComision {
    venta_id: string
    venta_item_id: string
    tipo: TipoLineaVenta
    descripcion: string
    base_aplicada: number
    porcentaje_aplicado: string
    comision_calculada: number
}

export interface DesgloseComision {
    base_calculo: BaseCalculo
    base_calculo_etiqueta: string
    porcentaje_servicios: string
    porcentaje_productos: string
    incluye_productos: boolean
    total_servicios: number
    total_productos: number
    total_comision: number
    lineas: LineaComision[]
}

export interface LiquidacionComision {
    id: string
    profesional: { id: string; nombre: string } | null
    periodo_desde: string
    periodo_hasta: string
    porcentaje_servicios: string
    porcentaje_productos: string
    incluye_productos: boolean
    base_servicios: number
    base_productos: number
    comision_servicios: number
    comision_productos: number
    total_comision: number
    estado: 'borrador' | 'cerrada' | 'anulada'
    estado_etiqueta: string
    lineas?: LineaComision[]
}

/* ------------------------------------------------------------------
 | Cortes de caja
 | ------------------------------------------------------------------ */

export interface CorteCaja {
    id: string
    profesional: { id: string; nombre: string } | null
    total_esperado: number
    total_declarado: number
    diferencia: number
    ventas_incluidas: number
    total_efectivo: number
    total_tarjeta: number
    estado: 'abierto' | 'cerrado'
    observaciones: string | null
    cerrado_en: string | null
}

/* ------------------------------------------------------------------
 | Panel y reportes
 | ------------------------------------------------------------------ */

export interface TarjetaPanel {
    total: number
    requiere_revision: boolean
    mensaje: string
}

export interface Panel {
    salon: { id: string; nombre: string; nombre_corto: string } | null
    fecha: string
    rol_etiqueta: string
    tarjetas: {
        citas_hoy: TarjetaPanel
        citas_con_retraso: TarjetaPanel
        avisos_de_salud: TarjetaPanel
        cortes_con_diferencia: TarjetaPanel
        accesos_denegados: TarjetaPanel
    }
    profesionales_activas: number
    capacidades: Capacidades
    espera_entre_procesos_comprobada: boolean
}