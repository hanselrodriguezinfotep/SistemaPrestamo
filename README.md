# GestionPrestamo — Manual de Referencia del Proyecto

> **Leer este archivo antes de tocar cualquier cosa.**
> Aquí está todo lo que necesitas saber para mantener, extender o debuggear el sistema.

---

## 📁 Árbol de carpetas

```
GestionPrestamo/
├── index.php               ← Punto de entrada único
├── login.php               ← Página de login
├── router.php              ← Despachador central de vistas
├── .htaccess               ← URLs limpias + seguridad Apache
│
├── views/                  ← VISTAS (páginas del sistema)
│   ├── dashboard.php       →  /GestionPrestamo/
│   ├── personas.php        →  /GestionPrestamo/personas
│   ├── usuarios.php        →  /GestionPrestamo/usuarios
│   ├── prestamos.php       →  /GestionPrestamo/prestamos
│   ├── planes.php          →  /GestionPrestamo/planes
│   ├── mora.php            →  /GestionPrestamo/mora
│   ├── simulador.php       →  /GestionPrestamo/simulador
│   ├── configuracion.php   →  /GestionPrestamo/configuracion
│   ├── notificaciones.php  →  /GestionPrestamo/notificaciones
│   └── perfil.php          →  /GestionPrestamo/perfil
│
├── api/                    ← ENDPOINTS JSON (solo fetch/AJAX)
│   ├── auth.php            →  login | logout | registro | reset
│   ├── personas.php        →  GET listar/obtener | POST crear/editar/eliminar
│   ├── usuarios.php        →  GET listar/roles | POST crear/editar/toggle/eliminar
│   ├── prestamos.php       →  GET listar/obtener/resumen | POST crear/cambiar_estado
│   ├── planes.php          →  GET listar/obtener | POST guardar/eliminar
│   ├── pagos.php           →  GET listar | POST registrar/anular
│   ├── cuotas.php          →  GET listar/proximas/mora | POST calcular_mora
│   ├── configuracion.php   →  GET/POST configuración de empresa
│   ├── perfil.php          →  GET/POST perfil del usuario actual
│   ├── notif_prefs.php     →  preferencias de notificación por persona
│   └── notificaciones.php  →  GET listar | POST marcar_leida
│
├── php/                    ← LÓGICA INTERNA (no accesibles por browser)
│   ├── helpers.php         ← Funciones utilitarias globales
│   ├── audit_actions.php   ← Catálogo de acciones de auditoría
│   ├── notificaciones.php  ← Lógica de envío de notificaciones
│   └── partials/
│       ├── head.php        ← <head> HTML común (carga CSS/JS globales)
│       └── sidebar.php     ← Sidebar de navegación
│
├── config/
│   ├── db.php              ← Conexión PDO a MySQL
│   └── session.php         ← Manejo seguro de sesiones + verificarSesion()
│
├── css/
│   ├── dashboard.css           ← Estilos principales
│   ├── login.css
│   └── searchable-select.css   ← Estilos combobox con búsqueda
│
├── js/
│   ├── dashboard.js
│   ├── login.js
│   └── searchable-select.js    ← Componente global de selects buscables
│
└── uploads/
    ├── fotos/              ← Fotos de personas
    └── logos/              ← Logos de empresa
```

---

## 🏛️ Regla fundamental: `id_centro`

**TODO** en este sistema se filtra por `id_centro`. Sin excepción. **Ningún registro puede quedar con `id_centro = NULL`.**

- Cada tabla tiene columna `id_centro` (o referencia indirecta vía FK).
- Toda query de lectura y escritura debe incluir `WHERE id_centro = ?`.
- El `id_centro` **nunca viene del input del usuario** en APIs autenticadas — siempre de `$_SESSION`.
- El **superadmin** siempre opera sobre `id_centro = 1`. Se fija en el login:
  ```php
  // api/auth.php
  $_SESSION['id_centro'] = $usuario['id_centro']
      ? (int)$usuario['id_centro']
      : ($usuario['rol'] === 'superadmin' ? 1 : null);
  ```
- En las APIs: `$id_centro = (int)($sesion['id_centro'] ?? 0);` — si es 0, rechazar con 400.
- **Sin excepciones de NULL**: personas, usuarios, préstamos, cuotas, pagos — todos deben tener `id_centro` asignado. Un registro sin `id_centro` es inaccesible para cualquier rol y queda huérfano.

### Endpoint `api/auth.php?action=registro` (registro público)

El formulario de registro **siempre debe incluir `id_centro`** en el payload — sin él, el registro es rechazado porque no puede haber persona ni usuario sin empresa asignada.

```javascript
// js/login.js — al llamar la API de registro
const { body } = await api("registro", {
  nombre,
  apellido,
  username,
  email,
  password,
  confirm,
  tipo_persona: tipo,
  fecha_nacimiento: fnac,
  genero,
  id_centro: idEmpresaDelFormulario, // ← OBLIGATORIO
});
```

En la API (`api/auth.php`), el `id_centro` recibido se valida contra `empresas` (debe existir y estar `Activo`) antes de usarse. Si no viene o la empresa es inválida, el registro falla con error 400.

---

## 👥 Roles del sistema

| Rol          | Acceso                                        |
| ------------ | --------------------------------------------- |
| `superadmin` | Todo, opera siempre sobre `id_centro = 1`    |
| `admin`      | Todo dentro de su empresa                     |
| `gerente`    | Vistas operativas, sin configuración avanzada |
| `supervisor` | Supervisión de operaciones                    |
| `cajero`     | Registro de pagos y consulta de préstamos     |
| `asesor`     | Consulta de préstamos asignados               |
| `auditor`    | Solo lectura — log de auditoría y reportes    |
| `cliente`    | Solo visualización de sus propios préstamos   |

---

## 💰 Estructura de préstamos

```
Empresa  →  Persona (Cliente)  →  Préstamo  →  Cuotas
                                      ↓
                                   Pagos  →  Cuota (aplicación automática)
                                      ↓
                               Mora (cálculo periódico)
                                      ↓
                              mora_registro (histórico)
```

### Tipos de amortización

| Tipo        | Descripción                                             |
| ----------- | ------------------------------------------------------- |
| `frances`   | Cuota fija. Capital crece, interés decrece cada período |
| `aleman`    | Capital fijo. Cuota decrece. Total interés menor        |
| `americano` | Solo interés durante el plazo. Capital al vencimiento   |

### Tablas clave

| Tabla                   | Descripción                                                |
| ----------------------- | ---------------------------------------------------------- |
| `empresas`              | Entidades del sistema                                      |
| `personas`              | Clientes, empleados, garantes — tienen `id_centro`        |
| `contactos_persona`     | Emails, teléfonos, WhatsApp vinculados a una persona       |
| `prestamos`             | Cabecera del préstamo — tiene `id_centro`, `id_persona`   |
| `cuotas`                | Tabla de amortización — tienen `id_centro`                |
| `pagos`                 | Pagos registrados — tienen `id_centro`                    |
| `mora_registro`         | Histórico de mora calculada por cuota                      |
| `planes_prestamo`       | Plantillas de condiciones (tasa, plazo, monto) por empresa |
| `garantias`             | Garantías vinculadas a un préstamo                         |
| `solicitudes_prestamo`  | Solicitudes previas a la aprobación                        |
| `configuracion_empresa` | Parámetros por empresa (tasa mora, moneda, SMTP, etc.)     |
| `callmebot_numeros`     | Números WhatsApp para notificaciones                       |
| `audit_log`             | Log de auditoría — tiene `id_centro`                      |
| `notificaciones`        | Notificaciones internas por empresa y usuario              |
| `provincias`            | Catálogo de provincias DR (estático)                       |
| `municipios`            | Catálogo de municipios DR (FK a provincias)                |
| `v_usuarios_login`      | Vista: usuario + persona + rol + permisos (para login)     |

---

## ⭐ Regla: Cálculo y aplicación de pagos

Cuando se registra un pago **sin especificar cuota**, el sistema aplica el monto automáticamente a las cuotas más antiguas primero (`ORDER BY numero ASC`). El flujo es:

**Capa 1 — Frontend** (`views/prestamos.php`): valida que el monto no supere el saldo pendiente antes de enviar.

**Capa 2 — Backend** (`api/pagos.php → registrar()`):

1. Verifica que el préstamo existe y no está cerrado (`pagado` / `cancelado`).
2. Inserta el pago en `pagos`.
3. Actualiza `saldo_pendiente` en `prestamos`.
4. Si el saldo llega a 0, marca el préstamo como `pagado`.
5. Aplica el monto a cuotas pendientes en orden ascendente de `numero`.

**Regla de mora** (`api/cuotas.php → calcularMora()`):

- Solo se ejecuta manualmente (botón "Actualizar Mora" en `views/mora.php`).
- Marca cuotas vencidas no pagadas como `mora` y registra en `mora_registro`.
- Actualiza el préstamo a estado `moroso`.
- La tasa de mora por defecto es `0.02` (2% mensual). Se puede sobrescribir vía payload.

---

## 🔍 Combobox con búsqueda interna (Searchable Select)

**Todos los `<select>` del proyecto** se convierten automáticamente en combobox con buscador al abrirse.

### Cómo funciona

- `js/searchable-select.js` se carga en **todas las páginas** desde `php/partials/head.php`.
- Al cargar el DOM inicializa todos los `<select>` presentes.
- Un `MutationObserver` detecta y convierte los selects añadidos dinámicamente.
- El buscador aparece solo si el select tiene **4 o más opciones**.
- Teclado: `↑↓` navega, `Enter` selecciona, `Escape` cierra.
- Resalta la coincidencia de búsqueda en amarillo.

### API pública

```javascript
SearchableSelect.init(selectEl); // Inicializar un select específico
SearchableSelect.refresh(selectEl); // Re-sincronizar tras cambio externo de .value
SearchableSelect.destroy(selectEl); // Eliminar y restaurar select nativo
ssRefreshAll(containerEl); // Refrescar todos los selects de un contenedor
ssSet("id-del-select", valor); // Asignar valor y actualizar widget en un paso
```

### Cuándo llamar refresh

Siempre que el código JS asigne `.value` externamente a un select ya convertido:

```javascript
// MAL — el widget no se entera del cambio
document.getElementById("u-rol").value = u.id_rol;

// BIEN — opción 1: usar ssSet
ssSet("u-rol", u.id_rol);

// BIEN — opción 2: asignar y luego refrescar
document.getElementById("u-rol").value = u.id_rol;
SearchableSelect.refresh(document.getElementById("u-rol"));

// BIEN — opción 3: al terminar de llenar un formulario completo
ssRefreshAll(document.getElementById("modal-usuario"));
```

### Excluir un select del componente

```html
<select data-no-search>
  ...
</select>
<!-- atributo -->
<select class="ss-skip">
  ...
</select>
<!-- clase -->
<select multiple>
  ...
</select>
<!-- múltiple: excluido automáticamente -->
```

### ⚠️ Regla crítica: cuándo usar `data-no-search`

Agregar `data-no-search` cuando el select:

- Tiene **menos de 4 opciones** (género, activo/inactivo, método de pago, etc.)
- Es un **selector de filtro de barra** — `fil-estado`, `flt-empresa`, `sel-periodo`, `filtro-rol`
- Se **repuebla dinámicamente** con `.innerHTML =` desde JavaScript

Si un select con el widget activo se repuebla con `.innerHTML =`, el widget queda con opciones desincronizadas y lanza `TypeError: Cannot read properties of null (reading 'addEventListener')`.

Los selects de **personas y clientes** (30+ opciones, estáticos) sí se benefician del buscador y NO deben tener `data-no-search`.

---

## 🗄️ Convenciones de base de datos

- Motor: **InnoDB**, charset **utf8**, MySQL **5.4+** / MariaDB 10+
- PKs: `id INT AUTO_INCREMENT`
- FKs nombradas: `fk_{tabla_corta}_{referencia}` — ej: `fk_usr_empresa`
- Índices nombrados: `idx_{tabla_corta}_{campo}` — ej: `idx_prest_empresa`
- Auditoría: `creado_en DATETIME DEFAULT NULL`, `actualizado_en TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- Cascada: `ON DELETE CASCADE` desde `empresas` → `usuarios`, `personas`, `notificaciones`; `ON DELETE SET NULL` donde aplique

### Patrón NULL-safe en queries (MySQL 5.x)

Para comparar campos que pueden ser NULL usar `<=>` (NULL-safe equals):

```sql
-- MAL: NULL = NULL → FALSE en SQL estándar
WHERE id_centro = ?

-- BIEN cuando el campo puede ser NULL: operador null-safe
WHERE (id_centro <=> ?)
```

Esto se usa en `configuracion_empresa` donde `id_centro` puede ser NULL para el registro global.

---

## 🔗 URLs del sistema

| URL                               | Vista                          |
| --------------------------------- | ------------------------------ |
| `/GestionPrestamo/`               | Dashboard principal            |
| `/GestionPrestamo/personas`       | Gestión de clientes y personas |
| `/GestionPrestamo/usuarios`       | Gestión de usuarios y roles    |
| `/GestionPrestamo/prestamos`      | Cartera de préstamos           |
| `/GestionPrestamo/planes`         | Planes de préstamo             |
| `/GestionPrestamo/mora`           | Cartera en mora                |
| `/GestionPrestamo/simulador`      | Simulador de cuotas            |
| `/GestionPrestamo/configuracion`  | Configuración de empresa       |
| `/GestionPrestamo/notificaciones` | Notificaciones internas        |
| `/GestionPrestamo/perfil`         | Perfil del usuario activo      |

**Tabs por hash**: se activan leyendo `window.location.hash` y se limpian con `history.replaceState`.

---

## 🔒 Seguridad

- `api/`, `php/`, `config/` bloqueados por `.htaccess` — no accesibles directamente.
- Toda API verifica sesión con `verificarSesionAPI()` al inicio.
- `id_centro` **nunca** viene del input del usuario, siempre de `$_SESSION`.
- Prepared statements con PDO en todas las queries.
- Uploads: solo imágenes, nombre aleatorio, carpeta con `.htaccess` propio.
- Rate limiting en login (`5 intentos / 5 min`) y registro (`3 intentos / 10 min`).

---

## 📦 Patches SQL aplicados

| Archivo                      | Descripción                                                                                                                                                                     | Fecha      |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| `gestion_prestamo.sql`       | Esquema base: todas las tablas, vistas, roles, permisos y datos iniciales                                                                                                       | 2026-03-03 |
| `gestion_prestamo_fixed.sql` | Agrega columna `id_centro` a `audit_log` + actualiza registros existentes con `id_centro = 1`. Elimina registro huérfano en `configuracion_empresa` (id=2, `id_centro=NULL`) | 2026-03-03 |

### ⚠️ Ejecutar patches en phpMyAdmin / HeidiSQL

Los archivos SQL usan `;` estándar statement por statement — **no usar `DELIMITER $$`** porque phpMyAdmin y HeidiSQL no lo soportan directamente (error 1064). En CLI mysql también funciona igual.

---

## 🤖 Instrucción para el asistente de IA

Cuando durante una revisión o corrección se identifique una **buena práctica** (seguridad, consistencia, claridad, robustez), aplicarla **en todo el proyecto** donde corresponda — no solo en el archivo que se está tocando en ese momento.

Condición obligatoria: **no cambiar la funcionalidad**. El comportamiento externo del sistema debe ser idéntico antes y después. Solo cambia la forma interna, no lo que hace.

Ejemplos de cuándo aplicar esto:

- Se detecta que un DELETE no verifica `id_centro` → corregirlo en **todos** los DELETEs del proyecto.
- Se detecta que una función PHP no usa `sanitizeStr()` donde debería → aplicarlo donde falte.
- Se detecta una query sin `LIMIT 1` en búsqueda de registro único → aplicarlo globalmente.
- Se detecta que un endpoint no llama `verificarSesionAPI()` al inicio → revisarlo en todas las APIs.

No preguntar si aplicarlo — hacerlo directamente y listar al final qué archivos se tocaron y por qué.

### 💡 Sugerencias proactivas de diseño y funcionalidad

El asistente **puede y debe** sugerir mejoras de diseño visual, UX o funcionalidades nuevas cuando identifique una oportunidad clara durante una revisión o corrección. No esperar a que el usuario pregunte.

Forma de sugerirlo: al final de la respuesta, en un bloque breve separado con `💡 Sugerencia:`, describir el problema identificado y la solución propuesta. Si el usuario dice "sí" o similar, aplicarla directamente.

### 📱 Regla de responsive — obligatoria y sin preguntar

**Todo archivo de vista o CSS que se toque debe quedar completamente responsive**, sin excepción.

Breakpoints estándar del proyecto:

| Breakpoint          | Descripción                    |
| ------------------- | ------------------------------ |
| `max-width: 1024px` | Tablet landscape               |
| `max-width: 768px`  | Tablet portrait / móvil grande |
| `max-width: 480px`  | Móvil pequeño                  |

Reglas obligatorias al tocar cualquier vista:

- **Tablas**: envueltas en `.tbl-wrap` con `overflow-x:auto` y `min-width` definido.
- **Grids**: usar `repeat(auto-fill, minmax(..., 1fr))` en vez de columnas fijas.
- **Flex layouts**: agregar `flex-wrap:wrap` y verificar que no se rompa en pantalla estrecha.
- **Botones y controles**: en móvil deben tener al menos 44px de alto (área táctil).
- **Formularios**: inputs y selects deben ser `width:100%` en móvil.
- **Imágenes**: `max-width:100%; height:auto`.

---

## 🛠️ Checklist al agregar features

1. ✅ Filtrar toda query por `id_centro`.
2. ✅ Revisar `helpers.php` antes de crear una función PHP nueva.
3. ✅ Selects nuevos: se convierten automáticamente. Si asignas `.value` desde JS, llamar `ssRefreshAll()` o `ssSet()`.
4. ✅ Tablas nuevas: incluir `id_centro INT DEFAULT NULL`, FK a `empresas`, índice `idx_{tabla}_empresa`.
5. ✅ El superadmin no tiene panel especial de multiempresa — opera como admin de `id_centro = 1`.
6. ✅ Todo endpoint de escritura (POST/DELETE) debe verificar que el registro pertenece al `id_centro` de la sesión antes de modificarlo.
7. ✅ Nunca usar `$esSuperadmin ? "1=1" : "id_centro = $id_centro"` — el superadmin también filtra por empresa.

### ⚠️ Patrón: selects dentro de contenedores flex

Cuando un `<select>` vive dentro de un contenedor `display:flex`, el `.ss-wrapper` que lo envuelve **hereda automáticamente** `flex:1; min-width:0` gracias a reglas en `searchable-select.css`. Si se agrega un nuevo contenedor flex, agregar la regla correspondiente en el CSS:

```css
.mi-contenedor-flex .ss-wrapper {
  flex: 1;
  min-width: 0;
  width: auto;
}
```

### ⚠️ Patrón: feedback visual `borderColor` con searchable-select

Cuando el código JS marca cambios con `sel.style.borderColor`, el select está oculto (`ss-hidden`) y el color no se ve. Siempre usar:

```javascript
const wrapper = sel.closest(".ss-wrapper");
if (wrapper) wrapper.querySelector(".ss-trigger").style.borderColor = "#f59e0b";
else sel.style.borderColor = "#f59e0b";
```

Y al limpiar después de guardar:

```javascript
document
  .querySelectorAll(".ss-wrapper .ss-trigger")
  .forEach((t) => (t.style.borderColor = ""));
```

### ⚠️ Patrón: tablas responsive (scroll horizontal móvil)

Toda `<table>` debe estar envuelta en un div con overflow-x para móvil:

```html
<div class="tbl-wrap">
  <table>
    ...
  </table>
</div>
```

```css
.tbl-wrap {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  border-radius: 12px;
}
table {
  min-width: 520px; /* o el ancho mínimo que necesite */
}
```

### ⚠️ Patrón: addEventListener siempre con null-check

**NUNCA** llamar `.addEventListener()` directamente sobre el resultado de `getElementById` sin verificar que no es null. El elemento puede no existir si está dentro de un bloque PHP condicional (`if ($esSuperadmin)`, etc.).

```javascript
// MAL — crashea si el elemento no existe en el DOM
document.getElementById("mi-modal").addEventListener("click", handler);

// BIEN — siempre con null-check
const el = document.getElementById("mi-modal");
if (el) el.addEventListener("click", handler);
```

Aplica especialmente a:

- Modales que solo se renderizan para ciertos roles
- Elementos dentro de `<?php if ($esSuperadmin): ?>` o similares
- Scripts que se ejecutan globalmente al cargar la página

### ⚠️ Patrón: bloques try/catch completos en JS

Verificar siempre que los bloques `if/else if` dentro de un `try` estén correctamente cerrados con `}` antes del `await` o código que los sigue. Un `}` faltante genera `SyntaxError: Unexpected token 'catch'` que rompe **todo el JS de la página**.

```javascript
// MAL — falta el } de cierre del else if antes del await
try {
    if (tipo === 'pago') {
        payload.monto = monto;
    } else if (tipo === 'cuota') {
        payload.id_cuota = idCuota;
    // ← FALTA }
    await apiFetch(...);
} catch(e) { ... }

// BIEN
try {
    if (tipo === 'pago') {
        payload.monto = monto;
    } else if (tipo === 'cuota') {
        payload.id_cuota = idCuota;
    }  // ← cierre correcto
    await apiFetch(...);
} catch(e) { ... }
```

### ⚠️ Patrón: APIs con parámetros obligatorios deben validar > 0

Cuando una API valida `!$param` (ej. `!$idPrestamo`), el valor `0` también dispara el error 400. Desde el frontend, no llamar endpoints si los parámetros requeridos son `0` o vacíos:

```javascript
// MAL — llama aunque idPrestamo sea 0, recibe 400
async function cargarCuotas() {
    const idPrestamo = getVal('sel-prestamo');
    if (!idPrestamo) return; // 0 pasa este check
    await fetch(...);
}

// BIEN — verificar que sea > 0
async function cargarCuotas() {
    const idPrestamo = parseInt(getVal('sel-prestamo'));
    if (!idPrestamo || idPrestamo === 0) return;
    await fetch(...);
}
```

### ⚠️ Patrón: filtros de barra siempre con `data-no-search`

Todo `<select>` que funcione como **filtro de barra** (no como campo de formulario) debe tener `data-no-search`. Identificarlos por:

- Están en `.toolbar`, `.filter-row` o similar (fuera de un modal/form)
- Su `id` sigue el patrón `fil-*`, `flt-*`, `filtro-*`, `sel-*` de barra
- Se repueblan dinámicamente con `.innerHTML =`

```html
<!-- MAL — filtro de barra sin data-no-search -->
<select id="flt-estado" onchange="filtrar()">
  ...
</select>

<!-- BIEN -->
<select id="flt-estado" data-no-search onchange="filtrar()">
  ...
</select>
```

Regla general: si el select tiene **menos de 8 opciones estáticas** o no se beneficia de búsqueda, siempre agregar `data-no-search`.

---

## 📄 Manual de Formularios (PDF)

El archivo `docs/manual_formularios.pdf` contiene el paso a paso de **todos los formularios del sistema**, módulo a módulo.

Se genera con el script `docs/generar_manual.py` usando ReportLab. **No editar el PDF binario directamente.**

### Regla de mantenimiento del manual

Cuando se agregue una función nueva o se modifique un formulario existente, **actualizar únicamente el script `docs/generar_manual.py`** con los cambios correspondientes.

**El PDF se regenera SOLO cuando el usuario lo solicite explícitamente.**

Pasos cuando el usuario pida regenerar el PDF:

1. Identificar el módulo correspondiente en `docs/generar_manual.py`
2. Confirmar que los cambios del script ya están al día
3. Ejecutar `python3 docs/generar_manual.py`
4. Entregar el PDF generado al usuario

Estructura estándar de cada formulario en el script:

```python
story.append(cabecera_form('Nombre del Formulario', '/ruta → acción'))
story.append(tabla_campos([
    ('Campo', 'Descripción de qué ingresar', obligatorio:bool),
]))
story.append(paso(1, 'Primer paso', 'descripción'))
story.append(nota('Advertencia o tip importante', 'warn|info|ok'))
```
