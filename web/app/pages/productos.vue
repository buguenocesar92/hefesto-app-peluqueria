<script setup lang="ts">
/**
 * Catálogo de productos.
 *
 * POR QUÉ EL PRECIO SE COPIA A LA VENTA
 * -------------------------------------
 * Un producto que sube de precio no puede reescribir lo que se cobró antes. Por
 * eso la venta guarda su propio precio unitario por línea, y la comisión se
 * calcula sobre ese número. Ver App\Services\Ventas\ServicioVentas.
 *
 * POR QUÉ `stock` PUEDE SER NULO
 * ------------------------------
 * Porque "no se controla stock" es una respuesta honesta de un salón chico, y
 * es distinta de "hay cero". El sistema no inventa un inventario que nadie
 * lleva: lo escribe quien lo lleva.
 */
import type { Producto } from '~/tipos/api'

definePageMeta({ middleware: 'auth' })

useHead({ title: 'Productos · Peluquería' })

const { data, refresh } = await useAsyncData('productos', () =>
    $fetch<{ datos: Producto[] }>('/api/administracion/productos'),
)

const productos = computed(() => data.value?.datos ?? [])

const formulario = reactive({
    nombre: '',
    descripcion: '',
    marca: '',
    precio_venta: 0,
    stock: null as number | null,
    unidad: 'unidad',
})

const editando = ref<string | null>(null)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupada = ref(false)

function pesos(valor: number): string {
    return valor.toLocaleString('es-CL')
}

function limpiar(): void {
    formulario.nombre = ''
    formulario.descripcion = ''
    formulario.marca = ''
    formulario.precio_venta = 0
    formulario.stock = null
    formulario.unidad = 'unidad'
    editando.value = null
}

async function guardar(): Promise<void> {
    error.value = null
    mensaje.value = null
    ocupada.value = true

    const cuerpo = {
        nombre: formulario.nombre,
        descripcion: formulario.descripcion === '' ? undefined : formulario.descripcion,
        marca: formulario.marca === '' ? undefined : formulario.marca,
        precio_venta: Number(formulario.precio_venta),
        stock: formulario.stock === null ? undefined : Number(formulario.stock),
        unidad: formulario.unidad === '' ? undefined : formulario.unidad,
    }

    try {
        if (editando.value === null) {
            await $fetch('/api/administracion/productos', { method: 'POST', body: cuerpo })

            mensaje.value = 'Producto guardado.'
        } else {
            await $fetch(`/api/administracion/productos/${editando.value}`, {
                method: 'PUT',
                body: cuerpo,
            })

            mensaje.value = 'Producto actualizado. Las ventas ya cobradas conservan su precio.'
        }

        limpiar()

        await refresh()
    } catch (fallo) {
        error.value = mensajeDeFallo(fallo)
    } finally {
        ocupada.value = false
    }
}

function editar(producto: Producto): void {
    formulario.nombre = producto.nombre
    formulario.descripcion = producto.descripcion ?? ''
    formulario.marca = producto.marca ?? ''
    formulario.precio_venta = producto.precio_venta
    formulario.stock = producto.stock
    formulario.unidad = producto.unidad
    editando.value = producto.id
}

function mensajeDeFallo(fallo: unknown): string {
    if (fallo && typeof fallo === 'object' && 'data' in fallo) {
        const cuerpo = (fallo as { data?: Record<string, unknown> }).data

        if (typeof cuerpo?.mensaje === 'string') {
            return cuerpo.mensaje
        }

        const errores = cuerpo?.errores

        if (errores && typeof errores === 'object') {
            for (const valor of Object.values(errores as Record<string, unknown>)) {
                if (Array.isArray(valor) && typeof valor[0] === 'string') {
                    return valor[0]
                }
            }
        }
    }

    return 'No se pudo guardar el producto.'
}
</script>

<template>
    <section>
        <h1>Productos</h1>

        <p class="ayuda">
            Lo que el salón vende además del servicio. La venta copia el precio del momento:
            cambiarlo acá no altera las ventas anteriores.
        </p>

        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje" class="aviso aviso--exito">{{ mensaje }}</p>

        <div class="tabla-envoltura">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Marca</th>
                        <th class="numero">Precio</th>
                        <th class="numero">Stock</th>
                        <th>Unidad</th>
                        <th>Estado</th>
                        <th>Edición</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="producto in productos" :key="producto.id">
                        <td>{{ producto.nombre }}</td>
                        <td>{{ producto.marca ?? '—' }}</td>
                        <td class="numero">{{ pesos(producto.precio_venta) }}</td>
                        <td class="numero">{{ producto.stock === null ? 'Sin control' : producto.stock }}</td>
                        <td>{{ producto.unidad }}</td>
                        <td>
                            <span
                                class="etiqueta"
                                :class="producto.activo ? 'etiqueta--exito' : 'etiqueta--alerta'"
                            >
                                {{ producto.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td>
                            <button class="boton boton--suave" type="button" @click="editar(producto)">
                                Editar
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2>{{ editando === null ? 'Nuevo producto' : 'Editando producto' }}</h2>

        <form class="formulario" @submit.prevent="guardar">
            <div class="fila">
                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input id="nombre" v-model="formulario.nombre" type="text" required maxlength="160" />
                </div>

                <div class="campo">
                    <label for="marca">Marca</label>
                    <input id="marca" v-model="formulario.marca" type="text" maxlength="120" />
                </div>
            </div>

            <div class="fila">
                <div class="campo">
                    <label for="precio">Precio de venta (pesos)</label>
                    <input
                        id="precio"
                        v-model="formulario.precio_venta"
                        type="number"
                        min="0"
                        step="100"
                        required
                    />
                </div>

                <div class="campo">
                    <label for="stock">Stock</label>
                    <input id="stock" v-model="formulario.stock" type="number" min="0" />
                </div>

                <div class="campo">
                    <label for="unidad">Unidad</label>
                    <input id="unidad" v-model="formulario.unidad" type="text" maxlength="24" />
                </div>
            </div>

            <div class="campo">
                <label for="descripcion">Descripción</label>
                <input id="descripcion" v-model="formulario.descripcion" type="text" maxlength="480" />
            </div>

            <p class="ayuda">Dejar el stock vacío significa que el salón no lo lleva.</p>

            <div class="fila">
                <button class="boton" type="submit" :disabled="ocupada">
                    {{ ocupada ? 'Guardando...' : 'Guardar' }}
                </button>

                <button
                    v-if="editando !== null"
                    class="boton boton--suave"
                    type="button"
                    @click="limpiar"
                >
                    Cancelar
                </button>
            </div>
        </form>
    </section>
</template>