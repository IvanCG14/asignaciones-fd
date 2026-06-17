<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/**
 * public/historial_asignaciones.php
 * Planos con status Closed que tienen asignaciones registradas en Asignaciones_FD.
 * Muestra todos los componentes asignados en una única tabla horizontal.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/get_planos.php';

$user      = Auth::requerirLogin();
$pageTitle = 'Historial Asignaciones';
$navActive = 'historial';

try {
    $connJB = Database::getJB();
} catch (RuntimeException $e) {
    $connJB = null;
}
$connRW = Database::get();

// ── 1. PLANOS CLOSED desde JB_Delta ──────────────────────────────────────────
// Reutilizamos getPlanos pasando el filtro vacío para traer todos los status,
// luego filtramos solo los Closed.
$todosPlanos = Planos::getPlanos($connJB, $connRW, '');
$planosClosed = array_filter($todosPlanos, fn($p) => strtolower($p['status'] ?? '') === 'closed');

// ── 2. TODOS LOS COMPONENTES (sin filtrar por modelo) ────────────────────────
// Los necesitamos para construir las columnas de la tabla.
$todosComponentes = []; // [cod_pza => nombre_componente]
$ordenComponentes = []; // Para mantener el orden de aparición
$sc = sqlsrv_query($connRW,
    "SELECT DISTINCT nombre_componente, cod_pza, modelo FROM Componentes ORDER BY modelo, nombre_componente"
);
if ($sc) {
    while ($r = sqlsrv_fetch_array($sc, SQLSRV_FETCH_ASSOC)) {
        if (!isset($todosComponentes[$r['cod_pza']])) {
            $todosComponentes[$r['cod_pza']] = $r['nombre_componente'];
            $ordenComponentes[] = $r['cod_pza'];
        }
    }
    sqlsrv_free_stmt($sc);
}

// ── 3. ASIGNACIONES_FD de los planos Closed ───────────────────────────────────
// Solo cargamos las asignaciones donde plano_asignado coincide con un plano Closed.
// Estructura: $asig[plano|of][cod_pieza][fila_plano] = row
$asig = [];
if (!empty($planosClosed)) {
    // Construimos lista de planos para el WHERE
    $planosKeys = array_keys($planosClosed); // keys son "plano|of"
    $planosUnicos = array_unique(array_map(fn($k) => explode('|', $k)[0], $planosKeys));

    if (!empty($planosUnicos)) {
        $conds = implode(',', array_fill(0, count($planosUnicos), '?'));
        $sa = sqlsrv_query($connRW,
            "SELECT cod_pieza, OT_FD, FD, componente, cant, status_tarea_actual,
                    area_actual, plano_asignado, modelo_bomba, fila_plano, fecha_entrega
             FROM Asignaciones_FD
             WHERE plano_asignado IN ($conds)
             ORDER BY plano_asignado, cod_pieza, fila_plano",
            $planosUnicos
        );
        if ($sa) {
            while ($r = sqlsrv_fetch_array($sa, SQLSRV_FETCH_ASSOC)) {
                if ($r['fecha_entrega'] instanceof DateTime) {
                    $r['fecha_entrega'] = $r['fecha_entrega']->format('Y-m-d');
                }
                // Clave: necesitamos el of correcto — lo buscamos en planosClosed
                $ofMatch = '';
                foreach ($planosKeys as $pk) {
                    [$pl, $of] = explode('|', $pk);
                    if ($pl === $r['plano_asignado']) { $ofMatch = $of; break; }
                }
                $key = $r['plano_asignado'] . '|' . $ofMatch;
                $asig[$key][$r['cod_pieza']][$r['fila_plano']] = $r;
            }
            sqlsrv_free_stmt($sa);
        }
    }
}

// ── 4. FILTRAR: solo planos Closed que TIENEN asignaciones ───────────────────
$planosConAsig = array_filter($planosClosed, fn($p) => isset($asig[$p['plano'] . '|' . $p['of']]));

// Ordenar por fecha_final ascendente
uasort($planosConAsig, function($a, $b) {
    return strcmp($a['fecha_final'] ?? '9999-12-31', $b['fecha_final'] ?? '9999-12-31');
});

// ── 5. COLUMNAS REALES: solo componentes que aparecen en alguna asignación ───
$codsEnUso = [];
foreach ($asig as $compMap) {
    foreach (array_keys($compMap) as $cod) {
        $codsEnUso[$cod] = true;
    }
}
// Respetamos el orden original de Componentes
$columnasComponentes = array_filter($ordenComponentes, fn($cod) => isset($codsEnUso[$cod]));
$columnasComponentes = array_values($columnasComponentes);

$mesesES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
            'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

include __DIR__ . '/../includes/layout_top.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* ── Contenedor principal ───────────────────────────────────────────────── */
.hist-wrap {
    overflow-x: auto;
    overflow-y: auto;
    max-height: calc(100vh - 200px);
    width: 100%;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    position: relative;
}
.hist-wrap::-webkit-scrollbar { height: 8px; width: 6px; }
.hist-wrap::-webkit-scrollbar-thumb { background: var(--accent); border-radius: var(--radius); }
.hist-wrap::-webkit-scrollbar-track { background: var(--surface2); }

/* ── Tabla principal ────────────────────────────────────────────────────── */
.hist-table {
    border-collapse: separate;
    border-spacing: 0;
    font-size: .73rem;
    min-width: 100%;
    white-space: nowrap;
}
.hist-table th, .hist-table td {
    border-bottom: 1px solid var(--border);
    border-right:  1px solid var(--border);
    padding: .4rem .65rem;
    vertical-align: middle;
    text-align: center;
}
/* Cabecera nivel 1: nombre componente */
.hist-table thead tr:nth-child(1) th {
    position: sticky;
    top: 0;
    height: 34px;
    box-sizing: border-box;
    background: var(--surface2) !important;
    color: var(--text-dim);
    font-family: var(--mono);
    font-size: .62rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    z-index: 10;
    border-top: 1px solid var(--border);
}
/* Cabecera nivel 2: FD / Cant */
.hist-table thead tr:nth-child(2) th {
    position: sticky;
    top: 33px;
    background: var(--surface2) !important;
    color: var(--text-dim);
    font-family: var(--mono);
    font-size: .60rem;
    letter-spacing: .07em;
    text-transform: uppercase;
    z-index: 9;
    border-top: 1px solid var(--border);
}
.hist-table th.comp-header {
    background: #1a1d24 !important;
    color: var(--accent);
    font-size: .67rem;
    border-top: 2px solid var(--accent);
}
.hist-table thead th.col-fija-1,
.hist-table thead th.col-fija-2 {
    z-index: 25 !important;
    text-align: center !important;
}

/* ── Columnas fijas izquierda ───────────────────────────────────────────── */
.col-fija-1 {
    position: sticky !important;
    left: 0;
    min-width: 180px;
    max-width: 180px;
    background: var(--surface) !important;
    z-index: 11;
    text-align: left !important;
    font-family: var(--mono);
    white-space: normal !important;
    word-break: break-word;
    line-height: 1.35;
}
.col-fija-2 {
    position: sticky !important;
    left: 180px;
    min-width: 32px;
    max-width: 32px;
    background: var(--surface2) !important;
    z-index: 11;
    font-family: var(--mono);
    font-size: .65rem;
    color: var(--text-dim);
}

/* ── Celdas de datos ────────────────────────────────────────────────────── */
.cell-fd {
    font-family: var(--mono);
    font-size: .7rem;
    font-weight: 600;
    color: #fff;
}
.cell-fd-sub {
    font-family: var(--mono);
    font-size: .6rem;
    color: var(--text-dim);
    display: block;
    margin-top: 1px;
}
.cell-cant {
    font-family: var(--mono);
    font-size: .68rem;
    color: var(--text-dim);
}
.cell-vacia {
    color: var(--border);
    font-size: .6rem;
}

/* ── Buscador ───────────────────────────────────────────────────────────── */
.search-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 1rem;
}
.search-bar input {
    max-width: 420px;
    margin: 0;
}

/* ── Badge de status ─────────────────────────────────────────────────────── */
.badge-closed {
    background: rgba(100,100,120,.25);
    color: #aaa;
    font-size: .55rem;
    padding: .15rem .45rem;
    border-radius: 3px;
    font-family: var(--mono);
    letter-spacing: .06em;
    text-transform: uppercase;
}

/* ── Fila resaltada al filtrar ──────────────────────────────────────────── */
tr.fila-oculta { display: none; }

/* ── Contador de resultados ─────────────────────────────────────────────── */
#hist-count {
    font-family: var(--mono);
    font-size: .65rem;
    color: var(--text-dim);
    white-space: nowrap;
}

/* ── Separador entre grupos cuando se hace scroll ──────────────────────── */
.row-grupo-header td {
    background: var(--surface2) !important;
    color: var(--accent);
    font-family: var(--display);
    font-size: 1rem;
    padding: 8px 0;
    position: sticky;
    left: 0;
    text-align: center;
    letter-spacing: .04em;
}

/* ── Fila de plano (primera fila del grupo) ─────────────────────────────── */
.col-plano-info {
    font-weight: 500;
    color: #fff;
    margin-bottom: 2px;
    font-size: .72rem;
}
.col-plano-cliente {
    font-size: .62rem;
    color: var(--text-dim);
    margin-bottom: 2px;
}
.col-plano-fecha {
    font-size: .6rem;
    color: var(--accent);
}

/* ── Modelo bomba tag ────────────────────────────────────────────────────── */
.tag-modelo {
    display: inline-block;
    background: rgba(240,165,0,.12);
    border: 1px solid rgba(240,165,0,.3);
    color: var(--accent);
    font-family: var(--mono);
    font-size: .55rem;
    padding: .1rem .3rem;
    border-radius: 2px;
    margin-top: 2px;
    letter-spacing: .05em;
}

/* ── Stat cards ─────────────────────────────────────────────────────────── */
.stat-cards {
    display: flex;
    gap: .75rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .65rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: .1rem;
    min-width: 110px;
}
.stat-card-val {
    font-family: var(--display);
    font-size: 1.35rem;
    font-weight: 700;
    color: var(--accent);
    line-height: 1;
}
.stat-card-lbl {
    font-family: var(--mono);
    font-size: .6rem;
    color: var(--text-dim);
    letter-spacing: .08em;
    text-transform: uppercase;
}
</style>

<div class="page-header">
    <h2>Historial de Asignaciones</h2>
    <div class="page-sub">Planos cerrados con FDs registradas</div>
</div>

<!-- Stat cards -->
<?php
$totalAsig = 0;
foreach ($asig as $compMap) {
    foreach ($compMap as $filaMap) {
        $totalAsig += count($filaMap);
    }
}
$modelosUnicos = array_unique(array_filter(array_column($planosConAsig, 'modelo')));
?>
<div class="stat-cards">
    <div class="stat-card">
        <span class="stat-card-val"><?= count($planosConAsig) ?></span>
        <span class="stat-card-lbl">Planos Closed</span>
    </div>
    <div class="stat-card">
        <span class="stat-card-val"><?= $totalAsig ?></span>
        <span class="stat-card-lbl">Asignaciones FD</span>
    </div>
    <div class="stat-card">
        <span class="stat-card-val"><?= count($columnasComponentes) ?></span>
        <span class="stat-card-lbl">Componentes</span>
    </div>
    <div class="stat-card">
        <span class="stat-card-val"><?= count($modelosUnicos) ?></span>
        <span class="stat-card-lbl">Modelos</span>
    </div>
</div>

<!-- Buscador -->
<div class="search-bar card">
    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24"
         fill="none" stroke="var(--text-dim)" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" style="opacity:.7;flex-shrink:0">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
    <input type="text"
           id="hist-search"
           class="form-control"
           placeholder="Buscar por OF, plano o cliente…"
           oninput="filtrarHistorial(this.value)"
           autocomplete="off">
    <span id="hist-count"></span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:.5rem;">
        <button onclick="imprimirTabla()" class="btn btn-secondary btn-sm"
                style="display:flex;align-items:center;gap:6px;white-space:nowrap;">
            <i class="fa-solid fa-print" style="font-size:.75rem"></i>
            Imprimir
        </button>
    </div>
</div>

<!-- Tabla principal -->
<div class="hist-wrap" id="hist-wrap">
<?php if (empty($planosConAsig)): ?>
    <div style="padding:3rem;text-align:center;color:var(--text-dim);font-family:var(--mono);font-size:.8rem;">
        Sin planos cerrados con asignaciones registradas.
    </div>
<?php else: ?>
<table class="hist-table" id="hist-table">
<thead>
    <tr>
        <!-- Columnas fijas -->
        <th rowspan="2" class="col-fija-1">Plano / Cliente</th>
        <th rowspan="2" class="col-fija-2">#</th>
        <!-- Una cabecera por componente, abarca FD + Cant -->
        <?php foreach ($columnasComponentes as $cod): ?>
            <th colspan="2" class="comp-header"><?= htmlspecialchars($todosComponentes[$cod]) ?></th>
        <?php endforeach; ?>
    </tr>
    <tr>
        <?php foreach ($columnasComponentes as $cod): ?>
            <th>FD</th>
            <th>Cant</th>
        <?php endforeach; ?>
    </tr>
</thead>
<tbody>
<?php foreach ($planosConAsig as $planoKey => $pdata):
    $clave   = $pdata['plano'] . '|' . $pdata['of'];
    $asigPlano = $asig[$clave] ?? [];
    $cant    = max((int)($pdata['cant'] ?? 1), 1);
    $cliente = htmlspecialchars($pdata['cliente'] ?? '—');
    $modelo  = htmlspecialchars($pdata['modelo'] ?? '—');

    $fmes = $pdata['fecha_final']
        ? date('d', strtotime($pdata['fecha_final'])) . ' '
          . $mesesES[(int)date('n', strtotime($pdata['fecha_final']))] . ' '
          . date('y', strtotime($pdata['fecha_final']))
        : '—';
?>
    <?php for ($fila = 1; $fila <= $cant; $fila++): ?>
    <tr <?php if ($fila === 1): ?>
            class="fila-plano"
            data-plano="<?= htmlspecialchars(strtolower($pdata['plano'])) ?>"
            data-of="<?= htmlspecialchars(strtolower($pdata['of'])) ?>"
            data-cliente="<?= htmlspecialchars(strtolower($pdata['cliente'] ?? '')) ?>"
            data-grupo="<?= htmlspecialchars($clave) ?>"
        <?php else: ?>
            class="fila-secundaria"
            data-grupo-fila="<?= htmlspecialchars($clave) ?>"
        <?php endif; ?>>

        <?php if ($fila === 1): ?>
        <!-- Celda de plano (rowspan = cantidad) -->
        <td class="col-fija-1" rowspan="<?= $cant ?>">
            <div class="col-plano-info">
                <?= htmlspecialchars($pdata['plano']) ?>
                <span style="color:var(--text-dim);font-weight:400"> — </span>
                <?= htmlspecialchars($pdata['of']) ?>
                <span class="badge-closed" style="margin-left:4px;">closed</span>
            </div>
            <div class="col-plano-cliente"><?= $cliente ?></div>
            <div class="col-plano-fecha"><?= $fmes ?></div>
            <?php if (!empty($pdata['modelo']) && $pdata['modelo'] !== '—'): ?>
                <span class="tag-modelo"><?= $modelo ?></span>
            <?php endif; ?>
        </td>
        <?php endif; ?>

        <!-- Número de fila -->
        <td class="col-fija-2"><?= $fila ?></td>

        <!-- Celdas por componente -->
        <?php foreach ($columnasComponentes as $cod):
            $row = $asigPlano[$cod][$fila] ?? null;
        ?>
            <?php if ($row): ?>
                <td class="cell-fd">
                    <?= htmlspecialchars($row['FD']) ?>
                    <?php if (!empty($row['componente']) && $row['componente'] !== $row['FD']): ?>
                        <span class="cell-fd-sub"><?= htmlspecialchars($row['componente']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="cell-cant"><?= (int)$row['cant'] ?></td>
            <?php else: ?>
                <td class="cell-vacia" colspan="1">—</td>
                <td class="cell-vacia" colspan="1">—</td>
            <?php endif; ?>
        <?php endforeach; ?>
    </tr>
    <?php endfor; ?>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div><!-- /hist-wrap -->

<script>
// ── Filtro de la tabla ────────────────────────────────────────────────────────
function filtrarHistorial(q) {
    const terms = q.toLowerCase().trim().split(/\s+/).filter(Boolean);

    // Seleccionamos solo las filas "cabeza de grupo" (fila 1 de cada plano)
    const cabezas = document.querySelectorAll('#hist-table tbody tr.fila-plano');
    let visibles = 0;
    const total  = cabezas.length;

    cabezas.forEach(tr => {
        const texto = [
            tr.dataset.plano   || '',
            tr.dataset.of      || '',
            tr.dataset.cliente || ''
        ].join(' ');

        const match = terms.length === 0 || terms.every(t => texto.includes(t));

        // Mostrar/ocultar la fila cabeza
        tr.classList.toggle('fila-oculta', !match);

        // Mostrar/ocultar las filas secundarias del mismo grupo
        const grupo = tr.dataset.grupo;
        document.querySelectorAll(`#hist-table tbody tr[data-grupo-fila="${CSS.escape(grupo)}"]`)
            .forEach(sub => sub.classList.toggle('fila-oculta', !match));

        if (match) visibles++;
    });

    // Actualizar contador
    const el = document.getElementById('hist-count');
    if (el) {
        el.textContent = q.trim() ? `${visibles} / ${total} planos` : '';
    }
}

// ── Imprimir ──────────────────────────────────────────────────────────────────
function imprimirTabla() {
    // Recogemos el thead completo
    const thead = document.querySelector('#hist-table thead');
    const theadHTML = thead ? thead.outerHTML : '';

    // Solo filas visibles
    const filas = [...document.querySelectorAll('#hist-table tbody tr:not(.fila-oculta)')];
    if (!filas.length) { alert('No hay filas visibles para imprimir.'); return; }

    const tbodyHTML = '<tbody>' + filas.map(tr => tr.outerHTML).join('') + '</tbody>';

    const ventana = window.open('', '_blank');
    ventana.document.write(`<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Historial de Asignaciones</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; font-size: 10px; color: #111; padding: 20px; }
        h1  { font-size: 15px; font-weight: 700; margin-bottom: 3px; }
        .sub { font-size: 9px; color: #666; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        thead tr th { background: #1a1a2e !important; color: #fff; padding: 4px 7px;
                      font-size: 9px; text-align: center; border: 1px solid #555; }
        th.comp-header { background: #111 !important; color: #e6a817; font-size: 8.5px;
                         border-top: 2px solid #e6a817; }
        td { padding: 4px 7px; border: 1px solid #ddd; vertical-align: top; }
        tr:nth-child(even) td { background: #f7f7f7; }
        .col-fija-1 { min-width: 150px; text-align: left; }
        .col-plano-info { font-weight: 600; font-size: 9.5px; }
        .col-plano-cliente { font-size: 8px; color: #666; }
        .col-plano-fecha { font-size: 8px; color: #e6a817; }
        .badge-closed { background: #eee; color: #888; font-size: 7.5px;
                        padding: 1px 4px; border-radius: 2px; margin-left: 3px; }
        .tag-modelo { background: rgba(230,168,23,.15); color: #b87d00; font-size: 7.5px;
                      padding: 1px 3px; border-radius: 2px; border: 1px solid rgba(230,168,23,.4); }
        .cell-fd { font-weight: 600; font-size: 9px; }
        .cell-fd-sub { font-size: 7.5px; color: #666; display: block; }
        .cell-cant { font-size: 9px; color: #444; text-align: center; }
        .cell-vacia { color: #ccc; text-align: center; }
        button { display: none !important; }
        @media print { body { padding: 10px; } }
    </style>
</head>
<body>
    <h1>Historial de Asignaciones</h1>
    <div class="sub">Planos cerrados con FDs registradas — Generado el ${
        new Date().toLocaleDateString('es-ES', { day: '2-digit', month: 'long', year: 'numeric' })
    }</div>
    <table>
        ${theadHTML}
        ${tbodyHTML}
    </table>
    <script>window.onload = () => { window.print(); }<\/script>
</body>
</html>`);
    ventana.document.close();
}
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>