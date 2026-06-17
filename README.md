# Asignaciones Piezas-Planos

> Sistema interno de planificación y asignación de fabricación de delta para el área de Producción

## Objetivo

[Completar: 2-4 líneas explicando el problema de negocio que resuelve. Por ejemplo: el sistema centraliza la planificación de órdenes de fabricación (OF) cruzando datos de JobBOSS con información complementaria registrada localmente (modelo de bomba, estación, fechas reales de entrega), y permite asignar Fichas de Despacho (FD) a los componentes de cada plano para dar seguimiento a su fabricación hasta el cierre de la orden.]

El sistema cubre tres etapas del flujo:

1. **Planificación** — visualización de órdenes activas (no cerradas) con sus fechas de entrega, avance de horas y datos enriquecidos (modelo, estación) detectados automáticamente o corregidos manualmente.
2. **Asignación** — asociación de FDs disponibles a los componentes de un plano según su modelo de bomba, registrando esa asignación en `Asignaciones_FD`.
3. **Historial** — consulta de planos ya cerrados (`status = Closed`) que efectivamente tuvieron asignaciones registradas, para trazabilidad posterior.

## Arquitectura de comunicación

```
┌─────────────────────┐         ┌──────────────────────┐
│   SQL Server JobBOSS │         │   SQL Server local    │
│   (JB_Delta)         │         │   (RW)                 │
│                      │         │                        │
│  Customer            │         │  Info_Bombas           │
│  Job                 │         │  Historial_Fechas      │
│  Delivery            │         │  Asignaciones_FD       │
│  Bill_Of_Jobs         │         │  Componentes           │
│  (solo lectura,      │         │  Usuarios / sesiones    │
│   fuente de verdad   │         │  (lectura/escritura)    │
│   de órdenes)        │         │                        │
└──────────┬───────────┘         └───────────┬────────────┘
           │                                  │
           │  sqlsrv_query (conn_jb)          │ sqlsrv_query (conn_rw)
           │                                  │
           └────────────────┬─────────────────┘
                             │
                  ┌──────────▼───────────┐
                  │  includes/            │
                  │  get_planos.php        │
                  │  (clase Planos)        │
                  │                        │
                  │  Cruza ambas BD,       │
                  │  detecta modelo/       │
                  │  estación por regex,   │
                  │  resuelve fecha final  │
                  │  según historial       │
                  └──────────┬─────────────┘
                             │
              ┌──────────────┼───────────────┐
              │              │               │
   ┌──────────▼──────┐ ┌─────▼──────────┐ ┌──▼───────────────────┐
   │ planificacion.php│ │tabla_asignacion │ │historial_asignaciones│
   │                  │ │.php             │ │.php                  │
   │ Lista de OFs     │ │ Asignación de   │ │ Planos Closed con     │
   │ activas + filtro │ │ FDs por modelo  │ │ asignaciones ya       │
   │                  │ │                 │ │ registradas           │
   └──────────┬───────┘ └────────┬────────┘ └───────────────────────┘
              │                  │
              │                  │ fetch() / AJAX
              │                  ▼
              │         ┌──────────────────┐
              │         │ api_asignar.php   │
              │         │                   │
              │         │ INSERT/UPDATE/    │
              │         │ DELETE sobre      │
              │         │ Asignaciones_FD   │
              │         │ (CSRF + sesión)   │
              │         └──────────────────┘
              │
              ▼
     [navegador / usuario]
```

### Flujo de datos

- **JB_Delta** es de **solo lectura**: se consulta vía `sqlsrv_query($conn_jb, ...)` para traer las órdenes (`Job`), clientes (`Customer`) y fechas comprometidas (`Delivery`), incluyendo una consulta recursiva (`Bill_Of_Jobs`) para sumar horas estimadas/reales de toda la jerarquía de sub-trabajos de cada OF.
- **RW** (base local) es de **lectura/escritura** y guarda todo lo que JobBOSS no contempla: correcciones manuales de modelo/estación/descripcion (`Info_Bombas`), historial de cambios de fecha de entrega (`Historial_Fechas`), el catálogo de componentes por modelo (`Componentes`) y las asignaciones de FD a cada plano (`Asignaciones_FD`).
- La clase `Planos::getPlanos()` (en `includes/get_planos.php`) es el **punto único de cruce** entre ambas bases: por cada plano de JB_Delta busca su fila correspondiente en `Info_Bombas` (clave `plano|of`) y en el historial de fechas, decide qué dato mostrar (modificado manualmente vs. detectado automáticamente vs. valor crudo de JobBOSS) y devuelve un arreglo normalizado. Todas las páginas de planificación reutilizan esta función para no duplicar la lógica de cruce.
- Las páginas de asignación (`tabla_asignacion.php`) leen ese arreglo más las FDs disponibles y las asignaciones ya hechas, y delegan cualquier cambio (crear, mover, eliminar una asignación) a `api_asignar.php` vía `fetch()`/AJAX, que valida sesión y CSRF antes de tocar `Asignaciones_FD`.
- `historial_asignaciones.php` es de **solo lectura**: vuelve a llamar `Planos::getPlanos()` (sin excluir Closed), filtra los que tengan registros en `Asignaciones_FD`, y los muestra en una tabla horizontal con una columna FD/Cant por cada componente.

## Estructura del proyecto

```
proyecto/
├── auth.php                       # Login, sesión, CSRF
├── includes/
│   ├── database.php               # Conexiones sqlsrv (JB_Delta y RW)
│   ├── get_planos.php              # Clase Planos::getPlanos() — cruce de datos
│   ├── layout_top.php              # Header/nav común a todas las páginas
│   └── layout_bottom.php           # Footer/scripts comunes
└── public/
    ├── planificacion.php           # Listado de OFs activas + filtro
    ├── tabla_asignacion.php        # Asignación de FDs por modelo de bomba
    ├── api_asignar.php             # Endpoint AJAX: CRUD de Asignaciones_FD
    └── historial_asignaciones.php  # Planos Closed con asignaciones registradas
```

## Modelo de datos (resumen)

| Tabla | Base | Rol |
|---|---|---|
| `Customer`, `Job`, `Delivery`, `Bill_Of_Jobs` | JB_Delta | Fuente de verdad de órdenes, clientes y fechas comprometidas (JobBOSS) |
| `Info_Bombas` | RW | Correcciones manuales por plano+OF (modelo, estación, descripción, fecha) |
| `Historial_Fechas` | RW | Auditoría de cambios de fecha de entrega por plano+OF |
| `Componentes` | RW | Catálogo de componentes por modelo de bomba (define las columnas de asignación) |
| `Asignaciones_FD` | RW | Asignación real de cada FD a un componente de un plano/OF/fila |

## Cómo usar el sistema

### 1. Planificación (`planificacion.php`)
- Muestra todas las OF activas (no Closed) ordenadas por fecha de entrega.
- El campo de búsqueda filtra por OF, plano o cliente en un solo input.
- Los datos de modelo y estación se detectan automáticamente desde la descripción/comentario de JobBOSS; si están corregidos manualmente en `Info_Bombas`, se muestra esa corrección en su lugar.

### 2. Asignación de FDs (`tabla_asignacion.php`)
- Selecciona un modelo de bomba (pestañas) para ver sus componentes como columnas.
- El panel lateral lista las FDs disponibles que coinciden con ese modelo; se pueden filtrar por FD o código de pieza.
- Al hacer clic en una FD del panel, o desde el panel manual, se elige el plano destino y la fila (cuando la cantidad del plano es mayor a 1) y se confirma la asignación.
- Cada alta/baja/edición llama a `api_asignar.php`, que persiste el cambio en `Asignaciones_FD` validando la sesión y el token CSRF.

### 3. Historial de asignaciones (`historial_asignaciones.php`)
- Vista de **solo lectura** pensada para auditoría: muestra únicamente planos `Closed` que llegaron a tener al menos una FD asignada.
- Misma estructura de columnas por componente que la pantalla de asignación, pero sin separación por modelo (todo en una sola tabla).
- El buscador filtra por OF, plano o cliente en un solo campo.
- Incluye un botón de impresión que exporta la tabla (respetando el filtro activo) a una vista lista para imprimir/PDF.

## Requisitos

- PHP con extensión `sqlsrv` habilitada.
- Acceso de red a la instancia de JobBOSS (JB_Delta) y a la instancia local (RW).
- Sesión de usuario autenticada (`auth.php`) para acceder a cualquier página bajo `public/`.

## Notas técnicas

- Las consultas a JB_Delta usan CTEs recursivos (`JerarquiaHijos`) para sumar horas estimadas/reales de toda la jerarquía de sub-órdenes de un Job, con `OPTION (MAXRECURSION 0)` para no limitar la profundidad.
- La detección automática de modelo y estación se hace por expresiones regulares sobre la descripción y el comentario de JobBOSS; estos valores se pueden sobreescribir manualmente y quedan guardados en `Info_Bombas`, que siempre tiene prioridad sobre el valor detectado.
- El historial de fechas (`Historial_Fechas`) permite saber si la fecha de entrega mostrada es la original de JobBOSS o una corregida manualmente, y conserva las fechas anteriores para trazabilidad.
