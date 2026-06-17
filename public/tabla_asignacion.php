<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
/**
 * public/tabla_asignacion.php
 * Tabla de Asignación FD — 4 pestañas por modelo de bomba.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/get_planos.php';

$user        = Auth::requerirLogin();
$esEditor    = Auth::tieneRol('Gerente', 'Supervisor', 'admin');
$pageTitle   = 'Tabla Asignación';
$navActive   = 'tabla';

// DESPUÉS
try {
    $connJB = Database::getJB();
} catch (RuntimeException $e) {
    $connJB = null;
}
$connRW = Database::get();

$modelos = ['modelo1', 'modelo2', 'modelo3', 'modelo4'];
$modeloActivo = $_GET['modelo'] ?? 'BAF-090';
if (!in_array($modeloActivo, $modelos)) $modeloActivo = 'BAF-090';

// ── Componentes del modelo activo ────────────────────────────────────────────
$todoComponentes = []; // ['modelo1' => [['nombre_componente'=>..,'cod_pza'=>..], ...]]
$sc = sqlsrv_query($connRW,
    "SELECT DISTINCT nombre_componente, cod_pza, modelo FROM Componentes ORDER BY modelo, nombre_componente"
);
if ($sc) {
    while ($r = sqlsrv_fetch_array($sc, SQLSRV_FETCH_ASSOC))
        $todoComponentes[$r['modelo']][] = ['nombre_componente' => $r['nombre_componente'], 'cod_pza' => $r['cod_pza']];
    sqlsrv_free_stmt($sc);
}
$componentes = $todoComponentes[$modeloActivo] ?? [];

// ── Planos del modelo desde JB_Delta ─────────────────────────────────────────
$planos = Planos::getPlanos($connJB, $connRW);
$planosFiltrados = array_filter($planos, fn($p) => str_contains($p['modelo'] ?? '', $modeloActivo));

// ── FDs del modelo desde JB_Delta ────────────────────────────────────────────
$todosCodigos = [];
foreach ($todoComponentes as $mod => $comps)
    foreach ($comps as $c)
        if (!empty($c['cod_pza'])) $todosCodigos[] = $c['cod_pza'];
$todosCodigos =array_values(array_unique($todosCodigos));

$todasFDs = [];
if ($connJB && !empty($todosCodigos)) {
    $conds = implode(' OR ', array_fill(0, count($todosCodigos), "j.Part_Number LIKE ?"));
    $anioActual = (int)date('Y'); 
    $paramsFD = array_map(fn($cod) => $cod . '%', $todosCodigos);
    
    $qFD = "
        WITH CodigosBase AS (
            SELECT j.Codigo AS of_fd, j.Part_Number AS plano_fd,
                   j.Cantidad_Ordenada AS cant, j.Estado AS estado, j.Estado_Fecha	 AS fecha
            FROM dbo.Codigo j
            LEFT JOIN dbo.Delivery d ON j.Codigo = d.Codigo
            WHERE j.Assembly_Level = 0 
              AND j.Codigo LIKE 'FD%'
              AND YEAR(j.Estado_Fecha) = $anioActual
              AND j.Estado NOT LIKE 'Closed'
              AND ($conds)
        )
        SELECT DISTINCT of_fd, plano_fd, cant, estado, fecha FROM CodigosBase ORDER BY of_fd
    ";

    $stmtFD = sqlsrv_query($connJB, $qFD, $paramsFD);
    
    if ($stmtFD) {
        while ($r = sqlsrv_fetch_array($stmtFD, SQLSRV_FETCH_ASSOC)) {
            $todasFDs[] = [
                'of_fd' => $r['of_fd'], 
                'plano_fd' => $r['plano_fd'], 
                'cant' => (int)$r['cant'], 
                'estado' => $r['estado']
            ];
        }
        sqlsrv_free_stmt($stmtFD);
    } else {
        error_log('FD query error: ' . print_r(sqlsrv_errors(), true));
    }
}

// FDs del modelo activo para el panel lateral (renderizado PHP)
$fds = array_values(array_filter($todasFDs, function($fd) use ($todoComponentes, $modeloActivo) {
    $codsModelo = array_column($todoComponentes[$modeloActivo] ?? [], 'cod_pza');
    foreach ($codsModelo as $cod) {
        if (str_starts_with($fd['plano_fd'], $cod) || str_starts_with($cod, $fd['plano_fd'])) {
            return true;
        }
    }
    return false;
}));

// ── Asignaciones guardadas ─────────────────────────────────────────────────
$asig = [];
if (!empty($todosCodigos)) {
    $conds2 = implode(' OR ', array_fill(0, count($todosCodigos), "cod_pieza = ?"));
    $sa = sqlsrv_query($connRW, "SELECT * FROM Asignaciones_FD WHERE ($conds2)", $todosCodigos);
    if ($sa) {
        while ($r = sqlsrv_fetch_array($sa, SQLSRV_FETCH_ASSOC)) {
            if ($r['fecha_entrega'] instanceof DateTime) $r['fecha_entrega'] = $r['fecha_entrega']->format('Y-m-d');
            $asig[$r['plano_asignado'].'|'.$r['OT_FD']][$r['cod_pieza']][$r['fila_plano']] = $r;
        }
        sqlsrv_free_stmt($sa);
    }
}

$mesesES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
            'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];


include __DIR__ . '/../includes/layout_top.php'; 
?>

<style>
.model-tabs{display:flex;gap:.25rem;margin-bottom:1.5rem}
.model-tab{padding:.45rem 1.2rem;font-family:var(--mono);font-size:.75rem;letter-spacing:.1em;
  border:1px solid var(--border);border-radius:var(--radius);color:var(--text-dim);
  text-decoration:none;transition:all .2s}
.model-tab:hover{color:var(--text);border-color:var(--text-dim);text-decoration:none}
.model-tab.active{background:var(--accent);color:#0e0f11;border-color:var(--accent);font-weight:600}

.asig-wrap {
  overflow-x: auto; /* Fuerza el scroll horizontal */
  overflow-y: auto; /* Fuerza el scroll vertical */
  max-height: calc(100vh - 140px); /* Evita que la pantalla se alargue hacia abajo */
  width: 100%; /* Ocupa el 100% de su columna en el grid */
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  position: relative;
}
.asig-table {
  border-collapse: separate; /* Vital para que position:sticky funcione bien */
  border-spacing: 0;
  font-size: .75rem;
  min-width: 100%;
  /*white-space: nowrap;*/
}
.asig-table th, .asig-table td {
  border-bottom: 1px solid var(--border);
  border-right: 1px solid var(--border);
  padding: .4rem .6rem;
  vertical-align: middle;
  text-align: center;
  white-space: nowrap;
}
.asig-table thead th {
  background: var(--surface2) !important;
  color: var(--text-dim);
  font-family: var(--mono);
  font-size: .62rem;
  letter-spacing: .08em;
  text-transform: uppercase;
  z-index: 10;
  border-top: 1px solid var(--border);
}
.asig-table th.comp-header {
  background: #1a1d24 !important;
  color: var(--accent);
  font-size: .68rem;
  border-top: 2px solid var(--accent);
}
.asig-table thead tr:nth-child(1) th {
  position: sticky;
  top: 0;
  height: 35px; /* Fijamos altura para calcular la 2da fila */
  box-sizing: border-box;
}
.asig-table thead tr:nth-child(2) th {
  position: sticky;
  top: 34px; /* Se queda pegada justo debajo de la primera fila */
  z-index: 9; 
}
.asig-table td.col-plano{text-align:left;font-family:var(--mono);font-weight:500;
  background:var(--surface);position:sticky;left:0;z-index:2;min-width:120px}
.asig-table td.col-fila{background:var(--surface2);color:var(--text-dim);font-family:var(--mono);font-size:.65rem;min-width:28px}
.col-fija-1 {
  position: sticky !important;
  left: 0; /* Pegada al borde izquierdo */
  min-width: 180px;
  max-width: 180px;
  background: var(--surface) !important; /* Fondo sólido bloquea visión de celdas por detrás */
  z-index: 11;
  text-align: left !important;
  font-family: var(--mono);
  white-space: normal !important; 
  word-break: break-word;
  line-height: 1.3;
}
.col-fija-2 {
  position: sticky !important;
  left: 160px; /* Empieza exactamente donde termina la col-fija-1 */
  min-width: 35px;
  max-width: 35px;
  background: var(--surface2);
  z-index: 11;
  font-family: var(--mono);
  font-size: .65rem;
  color: var(--text-dim);
}
.asig-table thead th.col-fija-1, .asig-table thead th.col-fija-2 {z-index: 25 !important;text-align: center !important;}
.cell-fd { font-family: var(--mono); font-size: .7rem; font-weight: 600; color: #fff; }
.cell-vacia { color: var(--border); font-size: .65rem; }

.highlight-pending td.pending-cell{background:rgba(240,165,0,.07)!important;outline:1px dashed rgba(240,165,0,.35)}

.soldado-wrap{display:flex;align-items:center;gap:.3rem;justify-content:center}
.soldado-wrap input[type=checkbox]{accent-color:var(--success);width:14px;height:14px;cursor:pointer}

.asig-manual-panel{background:var(--surface);border:1px solid var(--border);
  border-left:3px solid var(--accent);padding:1rem 1.25rem;margin-bottom:1.5rem;display:none}
.asig-manual-panel.open{display:block}

.autocomplete-wrap{position:relative}
.autocomplete-list{position:absolute;top:100%;left:0;right:0;z-index:50;
  background:var(--surface2);border:1px solid var(--accent);border-top:none;
  max-height:220px;overflow-y:auto}
.autocomplete-list li{list-style:none;padding:.45rem .75rem;cursor:pointer;
  font-family:var(--mono);font-size:.72rem;border-bottom:1px solid var(--border)}
.autocomplete-list li:hover{background:rgba(240,165,0,.12);color:var(--accent)}
.autocomplete-list li .sub{font-size:.62rem;color:var(--text-dim)}
.toolbar{display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap}

/* ── Layout principal: tabla + panel FDs ─────────────────────────────────── */
.main-layout{display:grid;grid-template-columns:1fr 260px;gap:1rem;align-items:start;width: 100%;}
.main-layout > div {min-width: 0;}
@media(max-width:900px){.main-layout{grid-template-columns:1fr;}}
#tabla-search:focus { border-color: var(--accent); }

/* ── Panel lateral FDs ───────────────────────────────────────────────────── */
.fd-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;position:sticky;top:1rem}
.fd-panel-header{background:var(--surface2);padding:.6rem 1rem;display:flex;align-items:center;
  justify-content:space-between;border-bottom:1px solid var(--border)}
.fd-panel-title{font-family:var(--mono);font-size:.65rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--accent)}
.fd-panel-count{font-family:var(--mono);font-size:.65rem;color:var(--text-dim)}
.fd-search{padding:.5rem .75rem;border-bottom:1px solid var(--border)}
.fd-search input{width:100%;background:var(--bg);border:1px solid var(--border);
  border-radius:2px;color:var(--text);font-family:var(--mono);font-size:.7rem;
  padding:.3rem .5rem;outline:none;box-sizing:border-box}
.fd-search input:focus{border-color:var(--accent)}
.fd-list{max-height:calc(100vh - 220px);overflow-y:auto}
.fd-item{padding:.5rem .75rem;border-bottom:1px solid var(--border);transition:background .15s}
.fd-item:hover{background:rgba(240,165,0,.06)}
.fd-item-Codigo{font-family:var(--mono);font-size:.72rem;font-weight:600;color:#fff}
.fd-item-plano{font-family:var(--mono);font-size:.62rem;color:var(--text-dim);margin-top:.1rem}
.fd-item-meta{display:flex;gap:.4rem;align-items:center;margin-top:.25rem;flex-wrap:wrap}
.fd-item-cant{font-family:var(--mono);font-size:.6rem;color:var(--text-dim)}
.fd-item-fecha{font-family:var(--mono);font-size:.6rem;color:var(--accent)}
.fd-empty{padding:1.5rem;text-align:center;font-family:var(--mono);font-size:.7rem;color:var(--text-dim)}
</style>

<div class="page-header">
  <h2>Tabla Asignación</h2>
  <div class="page-sub"><?= htmlspecialchars($modeloActivo) ?></div>
</div>

<div class="model-tabs">
  <?php foreach ($modelos as $m): ?>
    <a href="#" data-modelo="<?= $m ?>" class="model-tab <?= $m===$modeloActivo?'active':'' ?>" onclick="cambiarModelo(this)"><?= $m ?></a>
  <?php endforeach; ?>
</div>

<div class="toolbar">
  <?php if ($esEditor): ?>
    <button class="btn btn-primary" id="btn-auto" onclick="asignarAutomatico()">⚡ Asignación Automática</button>
    <button class="btn btn-secondary" id="btn-manual-toggle" onclick="toggleManual()">✎ Asignación Manual</button>
  <?php endif; ?>
  <button class="btn btn-secondary" id="btn-hl" onclick="toggleHighlight()">◉ Resaltar Pendientes</button>
  <span id="spin-auto" style="display:none"><span class="spinner"></span> Procesando…</span>
  <span id="msg-auto" style="font-family:var(--mono);font-size:.75rem"></span>
</div>

<?php if ($esEditor): ?>
<div class="asig-manual-panel" id="panel-manual">
  <div style="font-family:var(--mono);font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:.75rem">
    Asignación Manual
  </div>
  <div style="display:grid;grid-template-columns:1fr 180px 80px auto;gap:.75rem;align-items:flex-end">
    <div>
      <label style="font-family:var(--mono);font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);display:block;margin-bottom:.3rem">FD Seleccionada</label>
      <div class="autocomplete-wrap">
        <input type="text" id="inp-fd-buscar" class="form-control" placeholder="Escribe FD o código de pieza..." autocomplete="off">
        <ul class="autocomplete-list" id="fd-lista" style="display:none"></ul>
      </div>
      <input type="hidden" id="fd-seleccionada">
      <input type="hidden" id="codp-selec">
    </div>
    <div>
      <label style="font-family:var(--mono);font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);display:block;margin-bottom:.3rem">Plano destino</label>
      <select id="sel-plano" class="form-control">
        <option value="">— Seleccionar —</option>
        <?php foreach ($planosFiltrados as $p): ?>
          <option value="<?= htmlspecialchars($p['plano'] . '|' . $p['of']) ?>" data-status="<?= htmlspecialchars($p['status'] ?? '') ?>" data-fecha="<?= htmlspecialchars($p['fecha_final'] ?? '') ?>">
            <?= htmlspecialchars($p['plano']) ?> — <?= htmlspecialchars($p['of']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-family:var(--mono);font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);display:block;margin-bottom:.3rem">Fila</label>
      <select id="sel-fila" class="form-control"><option value="1">1</option><option value="2">2</option></select>
    </div>
    <button class="btn btn-primary" onclick="asignarManual()">Asignar</button>
  </div>
  <div id="msg-manual" style="margin-top:.5rem;font-family:var(--mono);font-size:.72rem"></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1rem;padding:12px 16px;">
  <div style="display:flex;align-items:center;gap:10px;">
    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
         fill="none" stroke="var(--text-dim)" stroke-width="2"
         stroke-linecap="round" stroke-linejoin="round" style="opacity:.7;flex-shrink:0">
      <circle cx="11" cy="11" r="8"></circle>
      <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
    <input type="text" id="tabla-search" class="form-control"
           placeholder="Buscar por plano, OF o cliente…"
           oninput="filtrarTabla(this.value)"
           style="max-width:400px;margin:0;">
    <span id="tabla-count"
          style="font-family:var(--mono);font-size:.65rem;color:var(--text-dim);white-space:nowrap"></span>
  </div>
</div>

<div class="main-layout">
<div><!-- columna principal: tabla -->
<div class="asig-wrap" id="tabla-wrap">
<table class="asig-table">
<thead>
  <tr>
    <th rowspan="2" class="col-fija-1">Plano / Cliente</th>
    <th rowspan="2" class="col-fija-2">#</th>
    <?php foreach ($componentes as $comp): ?>
      <th colspan="4" class="comp-header"><?= htmlspecialchars($comp['nombre_componente']) ?></th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($componentes as $comp): ?>
      <th>FD</th><th>#</th><th>Status</th><th>Área</th>
    <?php endforeach; ?>
  </tr>
</thead>
<tbody>
<?php foreach ($planosFiltrados as $plano => $pdata):
    $cant    = $pdata['cant'];
    $cliente = $pdata['cliente'];
    $fmes    = $pdata['fecha_final']
        ? date('d',strtotime($pdata['fecha_final'])).' '.$mesesES[(int)date('n',strtotime($pdata['fecha_final']))].' '.date('y',strtotime($pdata['fecha_final']))
        : '—';
?>
  <?php for ($fila = 1; $fila <= max($cant,1); $fila++): ?>
  <tr <?php if ($fila === 1): ?>
        data-plano="<?= htmlspecialchars(strtolower($pdata['plano'])) ?>"
        data-of="<?= htmlspecialchars(strtolower($pdata['of'])) ?>"
        data-cliente="<?= htmlspecialchars(strtolower($cliente)) ?>"
        data-grupo="<?= htmlspecialchars($plano) ?>"
      <?php else: ?>
        data-grupo-fila="<?= htmlspecialchars($plano) ?>"
      <?php endif; ?>>
    <?php if ($fila === 1): ?>
    <td class="col-fija-1" rowspan="<?= max($cant,1) ?>">
      <div style="font-weight: 500;color: #fff;margin-bottom:2px;"><?= htmlspecialchars($pdata['plano']).' - '.htmlspecialchars($pdata['of']) ?></div>
      <div style="font-size:.65rem;color:var(--text-dim);font-weight:400;margin-bottom:2px;"><?= htmlspecialchars($cliente) ?></div>
      <div style="font-size:.62rem;color:var(--accent)"><?= $fmes ?></div>
    </td>
    <?php endif; ?>
    <td class="col-fija-2"><?= $cant > 1 ? $fila : '1' ?></td>
    <?php foreach ($componentes as $comp):
      $row = $asig[$plano][$comp['cod_pza']][$fila] ?? null;
    ?>
      <?php if ($row): ?>
        <?php if ($esEditor): ?>
          <td class="cell-fd"
              data-componente="<?= htmlspecialchars($row['componente']) ?>"
              data-plano="<?= htmlspecialchars($row['plano_asignado']) ?>"
              data-fila="<?= (int)$row['fila_plano'] ?>"
              oncontextmenu="abrirContextMenu(event, this)">
            <?= htmlspecialchars($row['FD']) ?>
          </td>
        <?php else: ?>
          <td class="cell-fd"><?= htmlspecialchars($row['FD']) ?></td>
        <?php endif; ?>
        <td><?= (int)$row['cant'] ?></td>
        <td style="font-size:.65rem">
          <?php $st = $row['status_tarea_actual'] ?? '';
            $cls = match(strtolower($st)) {'active'=>'badge-success','complete'=>'badge-neutral',default=>'badge-warning'};
            echo $st ? "<span class='badge $cls'>$st</span>" : '—'; ?>
        </td>
        <td>
          <?php
            $areas = ['—','ARMADO','ACABADO','CORTE','MAQUINADO','PINTURA','SOLDADURA'];
            $areaActual = $row['area_actual'] ?? '—';
          ?>
          <?php if ($esEditor): ?>
            <select data-id="<?= $row['componente'] ?>" 
                    data-plano="<?= htmlspecialchars($row['plano_asignado']) ?>"
                    data-fila="<?= (int)$row['fila_plano'] ?>"
                    onchange="guardarArea(this)"
                    style="font-size: 0.6rem; padding: 0.15rem 0.25rem; background: var(--bg); border: 1px solid var(--border); border-radius: 2px; color: var(--text); width: 80px;">
              <?php foreach ($areas as $a): ?>
                <span style="font-size: 0.62rem; font-weight: 500;">
                  <option value="<?= $a ?>" <?= $areaActual === $a ? 'selected' : '' ?>><?= $a ?></option>
                </span>
              <?php endforeach; ?>
            </select>
          <?php else: echo htmlspecialchars($areaActual); endif; ?>
        </td>
      <?php else: ?>
        <td colspan="4" class="cell-vacia pending-cell"
            data-plano="<?= htmlspecialchars($pdata['plano']) ?>"
            data-comp="<?= htmlspecialchars($comp['nombre_componente']) ?>"
            data-fila="<?= $fila ?>">
          <?php if ($esEditor): ?>
            <button class="btn btn-secondary btn-sm"
                    onclick="abrirManualRapido('<?= htmlspecialchars($pdata['plano'] . '|' . $pdata['of'], ENT_QUOTES) ?>','<?= htmlspecialchars($comp['cod_pza'], ENT_QUOTES) ?>',<?= $fila ?>)">
              + FD
            </button>
          <?php else: ?><span style="opacity:.3">—</span><?php endif; ?>
        </td>
      <?php endif; ?>
    <?php endforeach; ?>
  </tr>
  <?php endfor; ?>
<?php endforeach; ?>
<?php if (empty($planosFiltrados)): ?>
  <tr><td colspan="<?= 2+count($componentes)*4 ?>" style="text-align:center;color:var(--text-dim);padding:2rem">
    Sin planos para <?= htmlspecialchars($modeloActivo) ?>.
  </td></tr>
<?php endif; ?>
<?php if ($esEditor): ?>
<div id="ctx-menu" style="
  display:none;position:fixed;z-index:9999;
  background:var(--surface2);border:1px solid var(--border);
  border-radius:var(--radius);padding:.25rem 0;min-width:160px;
  box-shadow:0 4px 16px rgba(0,0,0,.4)">
  <div id="ctx-eliminar" style="
    padding:.5rem 1rem;font-family:var(--mono);font-size:.72rem;
    color:var(--danger);cursor:pointer;display:flex;align-items:center;gap:.5rem"
    onmouseenter="this.style.background='rgba(220,50,50,.12)'"
    onmouseleave="this.style.background=''"
    onclick="confirmarEliminar()">
    ✕ Eliminar asignación
  </div>
</div>
<?php endif; ?>
</tbody>
</table>
</div><!-- /asig-wrap -->
</div><!-- /columna principal -->

<!-- ── Panel lateral: FDs disponibles ──────────────────────────────────── -->
<aside class="fd-panel">
  <div class="fd-panel-header">
    <span class="fd-panel-title">FDs disponibles</span>
    <span class="fd-panel-count" id="fd-count"><?= count($fds) ?></span>
  </div>
  <div class="fd-search">
    <input type="text" id="fd-filter" placeholder="Filtrar FD o código de pieza…" oninput="filtrarFDs(this.value)">
  </div>
  <div class="fd-list" id="fd-list">
    <?php if (empty($fds)): ?>
      <div class="fd-empty">Sin FDs para <?= htmlspecialchars($modeloActivo) ?></div>
    <?php else: ?>
      <?php foreach ($fds as $fd):
          $estadoCls = match(strtolower($fd['estado'] ?? '')) {
              'active'   => 'badge-success',   // Verde: En proceso activo
              'complete' => 'badge-neutral',   // Gris/Oscuro: Terminado, ya no requiere atención
              'closed'   => 'badge-neutral',   // Gris: Cerrado administrativamente
              'hold'     => 'badge-danger',    // Rojo: Detenido por algún problema (requiere acción)
              'pending'  => 'badge-warning',   // Naranja: Pendiente por iniciar
              default    => 'badge-warning',   // Por defecto, tratar como pendiente si no se reconoce
          };
          $planosAsignadosCruce = [];
          if (!empty($asig)) {
              // Iteramos el primer nivel (ej: $plano_of = "PLANO-123|OF20264")
              foreach ($asig as $plano_of => $componentesAsig) {
                  foreach ($componentesAsig as $codPza => $filasAsig) {
                      foreach ($filasAsig as $numFila => $rowAsig) {
                          // Tu arreglo guarda la FD en 'OT_FD'. Comparamos contra el 'of_fd' del panel lateral.
                          if (isset($rowAsig['componente']) && $rowAsig['componente'] === $fd['of_fd']) {
                              // Extraemos solo la parte del plano (antes del '|') para mostrarlo limpio en el panel
                              $partesPlano = explode('|', $plano_of);
                              $nombrePlanoClean = !empty($partesPlano[0]) ? trim($partesPlano[0]) : $plano_of;
                              $planosAsignadosCruce[$nombrePlanoClean] = [
                                'plano'        => $nombrePlanoClean,
                                'modelo_bomba' => $rowAsig['modelo_bomba'] ?? '—'
                              ];
                          }
                      }
                  }
              }
          }
      ?>
      <div class="fd-item"
           onclick="seleccionarFDLateral('<?= htmlspecialchars($fd['of_fd'], ENT_QUOTES) ?>')"
           data-Codigo="<?= htmlspecialchars(strtolower($fd['of_fd'])) ?>"
           data-plano="<?= htmlspecialchars(strtolower($fd['plano_fd'])) ?>">
        <div class="fd-item-Codigo"><?= htmlspecialchars($fd['of_fd']) ?></div>
        <div class="fd-item-plano"><?= htmlspecialchars($fd['plano_fd']) ?></div>
        <div class="fd-item-meta">
          <span class="fd-item-cant">cant: <?= $fd['cant'] ?></span>
          <?php if ($fd['estado']): ?>
            <span class="badge <?= $estadoCls ?>" style="font-size:.55rem"><?= htmlspecialchars($fd['estado']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($planosAsignadosCruce)): 
          $planosAgrupados = [];
          foreach ($asig as $plano_of => $componentesAsig) {
            foreach ($componentesAsig as $codPza => $filasAsig) {
              foreach ($filasAsig as $numFila => $rowAsig) {
                if (isset($rowAsig['componente']) && $rowAsig['componente'] === $fd['of_fd']) {
                  $partesPlano = explode('|', $plano_of);
                  $planoKey = trim($partesPlano[0] ?? $plano_of);
                  if (!isset($planosAgrupados[$planoKey])) {
                    $planosAgrupados[$planoKey] = [
                      'plano'        => $planoKey,
                      'modelo_bomba' => $rowAsig['modelo_bomba'] ?? '—',
                      'cantidad'     => 0
                    ];
                  }
                  $planosAgrupados[$planoKey]['cantidad']++;
                }
              }
            }
          }
        ?>
          <div class="fd-item-assignments" style="margin-top: 6px; padding-top: 4px; border-top: 1px dashed var(--border); font-size: 0.65rem; color: var(--accent);">
            <?php $contadorFila = 1; ?>
            <?php foreach ($planosAgrupados as $pAgrupado): ?>
              <div style="font-family: var(--mono); margin-bottom: 1px;">
                <span style="color: var(--text-dim);">Plano <?= $contadorFila ?>:</span>
                <?= htmlspecialchars($pAgrupado['plano']) ?>
                <?php if ($pAgrupado['cantidad'] > 1): ?>
                  <span style="color: var(--accent); font-weight: bold;">(<?= $pAgrupado['cantidad'] ?>)</span>
                <?php endif; ?>
                - <span style="color: var(--text-dim); font-size: .6rem;"><?= htmlspecialchars($pAgrupado['modelo_bomba']) ?></span>
              </div>
              <?php $contadorFila++; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</aside>

</div><!-- /main-layout -->

<script>
const CSRF   = <?= json_encode(Auth::csrfToken()) ?>;
const MODELO = <?= json_encode($modeloActivo) ?>;
const TODAS_FDS = <?= json_encode($todasFDs) ?>;
const TODOS_COMPONENTES = <?= json_encode($todoComponentes) ?>;
const TODOS_PLANOS = <?= json_encode(array_values($planos)) ?>;
const COMPONENTES = <?= json_encode($componentes) ?>;

function perteneceAModelo(fd, modelo) {
  const comps = (TODOS_COMPONENTES[modelo] || []).map(c => c.cod_pza);
  return comps.some(cod => fd.plano_fd.startsWith(cod) || cod.startsWith(fd.plano_fd));
}

let FDS_DISPONIBLES = TODAS_FDS.filter(fd => perteneceAModelo(fd, MODELO));
const ASIGNACIONES_ACTUALES = <?= json_encode($asig) ?>;

let highlightOn = false;
function toggleHighlight() {
  highlightOn = !highlightOn;
  document.getElementById('tabla-wrap').classList.toggle('highlight-pending', highlightOn);
  const btn = document.getElementById('btn-hl');
  btn.style.color = highlightOn ? 'var(--accent)' : '';
  btn.style.borderColor = highlightOn ? 'var(--accent)' : '';
}

function toggleManual() {
  document.getElementById('panel-manual').classList.toggle('open');
}



function cambiarModelo(tab) {
    // 1. Actualizar pestaña activa visualmente
    document.querySelectorAll('.model-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    const modelo = tab.dataset.modelo;

    // 2. Actualizar FDS_DISPONIBLES filtradas
    FDS_DISPONIBLES = TODAS_FDS.filter(fd => perteneceAModelo(fd, modelo));

    // 3. Actualizar el panel lateral de FDs
    const fdList = document.getElementById('fd-list');
    if (!FDS_DISPONIBLES.length) {
        fdList.innerHTML = `<div class="fd-empty">Sin FDs para ${modelo}</div>`;
    } else {
        fdList.innerHTML = FDS_DISPONIBLES.map(fd => `
            <div class="fd-item"
                 onclick="seleccionarFDLateral('${fd.of_fd}')"
                 data-Codigo="${fd.of_fd.toLowerCase()}"
                 data-plano="${fd.plano_fd.toLowerCase()}">
                <div class="fd-item-Codigo">${fd.of_fd}</div>
                <div class="fd-item-plano">${fd.plano_fd}</div>
                <div class="fd-item-meta">
                    <span class="fd-item-cant">cant: ${fd.cant}</span>
                    ${fd.estado ? `<span class="badge badge-warning" style="font-size:.55rem">${fd.estado}</span>` : ''}
                </div>
            </div>`).join('');
    }
    document.getElementById('fd-count').textContent = FDS_DISPONIBLES.length;

    // 4. Recargar la tabla — esta parte sí necesita un reload porque la tabla se renderiza en PHP
    // Solo hacemos reload si el modelo cambió realmente
    if (modelo !== MODELO) {
        window.location.href = '?modelo=' + modelo;
    }
}

function seleccionarFDLateral(of_fd) {
  const panel = document.getElementById('panel-manual');
  // Abrimos el panel manual lateral izquierdo y hacemos scroll suave hacia él
  panel.classList.add('open');
  panel.scrollIntoView({ behavior: 'smooth' });
  
  document.getElementById('inp-fd-buscar').value = of_fd;
  document.getElementById('fd-seleccionada').value = of_fd;
  document.getElementById('sel-plano').value = "";
  document.getElementById('sel-fila').value = "1"; 
  cerrarLista();
  // Opcional: Mandamos un pequeño mensaje en el panel para guiar al usuario
  const msg = document.getElementById('msg-manual');
  if (msg) {
    msg.textContent = `✓ Seleccionada ${of_fd}. Ahora elige el Plano Destino y Fila para completar la asignación.`;
    msg.style.color = 'var(--accent)';
  }
}

function abrirManualRapido(plano, comp, fila) {
  const panel = document.getElementById('panel-manual');
  panel.classList.add('open');
  panel.scrollIntoView({behavior:'smooth'});
  document.getElementById('inp-fd-buscar').value = comp;
  document.getElementById('fd-seleccionada').value = ''; // Limpiamos selección previa explícita
  document.getElementById('sel-plano').value = plano;
  document.getElementById('sel-fila').value  = fila;
  buscarFDLocal(comp); // Ejecuta el filtrado local inmediato
}

document.getElementById('inp-fd-buscar')?.addEventListener('input', function() {
  document.getElementById('fd-seleccionada').value = ''; // Si el usuario escribe, se invalida la selección previa hasta elegir de la lista
  buscarFDLocal(this.value);
});

function buscarFDLocal(q) {
  const lista = document.getElementById('fd-lista');
  lista.innerHTML = '';
  if (!q.trim()) { cerrarLista(); return; }
  
  // Dividimos la búsqueda por palabras para permitir búsquedas cruzadas (ej: "FD26072 ORODELTI")
  const terms = q.toLowerCase().trim().split(/\s+/).filter(Boolean);
  
  // Filtramos el array inyectado por coincidencias con of_fd o plano_fd
  const filtrados = FDS_DISPONIBLES.filter(fd => {
    const textoCelda = ((fd.of_fd || '') + ' ' + (fd.plano_fd || '')).toLowerCase();
    const coincideTexto = terms.every(t => textoCelda.includes(t));
    
    if (!coincideTexto) return false;
    let vecesAsignada = 0
    // Recorremos la estructura inyectada de asignaciones para contar el uso real de esta FD
    if (ASIGNACIONES_ACTUALES && typeof ASIGNACIONES_ACTUALES === 'object') {
      for (const planoKey in ASIGNACIONES_ACTUALES) {
        const componentesAsig = ASIGNACIONES_ACTUALES[planoKey];
        for (const codPza in componentesAsig) {
          const filasAsig = componentesAsig[codPza];
          for (const numFila in filasAsig) {
            const rowAsig = filasAsig[numFila];
            // Si la asignación guardada pertenece a la FD actual, sumamos 1 al contador
            if (rowAsig && rowAsig.componente === fd.of_fd) {
              vecesAsignada++;
            }
          }
        }
      }
    }
    return vecesAsignada < fd.cant;
  });
  
  if (!filtrados.length) { cerrarLista(); return; }
  
  // Construimos el HTML de las sugerencias de manera idéntica al panel lateral
  filtrados.forEach(fd => {
    const li = document.createElement('li');
    
    // Evaluamos dinámicamente las clases del badge de estados en JS
    let estadoCls = 'badge-warning';
    if (fd.estado) {
      const est = fd.estado.toLowerCase();
      if (est === 'active') estadoCls = 'badge-success';
      else if (est === 'complete' || est === 'closed') estadoCls = 'badge-neutral';
      else if (est === 'hold') estadoCls = 'badge-danger';
    }
    
    const badgeHtml = fd.estado ? `<span class="badge ${estadoCls}" style="font-size:.55rem; margin-left:5px;">${fd.estado}</span>` : '';
    
    li.innerHTML = `<strong>${fd.of_fd}</strong> — ${fd.plano_fd} ${badgeHtml} <br><span class="sub">cant: ${fd.cant}</span>`;
    
    // Al dar click asignamos los valores a los inputs del formulario manual
    li.onclick = () => {
      document.getElementById('inp-fd-buscar').value = fd.of_fd;
      document.getElementById('fd-seleccionada').value = fd.of_fd;
      document.getElementById('codp-selec').value = ''; 
      
      for (const comp of COMPONENTES) { 
        // Validamos si el código de pieza está presente en plano_fd o of_fd
        if (fd.plano_fd.toLowerCase().includes(comp.cod_pza.toLowerCase()) || 
            fd.of_fd.toLowerCase().includes(comp.cod_pza.toLowerCase())) { 
          document.getElementById('codp-selec').value = comp.cod_pza; 
          break; // Salimos del bucle una vez encontrado
        }
      }
      cerrarLista();
    };
    lista.appendChild(li);
  });
  
  lista.style.display = 'block';
}

function cerrarLista() {
  const l = document.getElementById('fd-lista');
  if (l) { l.innerHTML = ''; l.style.display = 'none'; }
}

function asignarManual() {
  const selPlano = document.getElementById('sel-plano');
  const codp = document.getElementById('codp-selec').value;
  const componente = document.getElementById('fd-seleccionada').value || document.getElementById('inp-fd-buscar').value;
  const plano = selPlano.value;
  const fila  = document.getElementById('sel-fila').value;
  const estadoPlano = selPlano.options[selPlano.selectedIndex]?.dataset.status || '';
  const fechaPlano  = selPlano.options[selPlano.selectedIndex]?.dataset.fecha  || '';
  const msg   = document.getElementById('msg-manual');
  if (!componente||!plano) { msg.textContent='⚠ Selecciona FD y plano.'; msg.style.color='var(--danger)'; return; }
  fetch('/public/api_asignar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({csrf_token:CSRF,action:'manual',componente,plano,fila_num:fila, modelo_bomba:MODELO, estado_plano: estadoPlano, fecha_plano: fechaPlano, codp: codp})})
  .then(r=>r.json()).then(d=>{
    msg.textContent=d.message; msg.style.color=d.success?'var(--success)':'var(--danger)';
    if(d.success) setTimeout(()=>location.reload(),1200);
  });
}

function asignarAutomatico() {
  if (!confirm('¿Asignar automáticamente todas las FDs disponibles a planos pendientes?')) return;
  const btn=document.getElementById('btn-auto');
  const spin=document.getElementById('spin-auto');
  const msg=document.getElementById('msg-auto');
  btn.disabled=true; spin.style.display='inline';
  fetch('/public/api_asignar.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({csrf_token:CSRF,action:'automatica'})})
  .then(r=>r.json()).then(d=>{
    spin.style.display='none'; btn.disabled=false;
    msg.textContent=d.message; msg.style.color=d.success?'var(--success)':'var(--danger)';
    if(d.success&&d.asignados>0) setTimeout(()=>location.reload(),1500);
  }).catch(()=>{spin.style.display='none';btn.disabled=false;});
}

function guardarArea(sel) {
  const componente = sel.dataset.id;
  const plano      = sel.dataset.plano;
  const fila_plano = sel.dataset.fila;
  const area = sel.value;
  fetch(`${window.location.origin}/public/api_asignar.php`, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({csrf_token: CSRF, action: 'soldado', componente, plano, fila_plano, area_actual: area})
  });
}

// ── Filtro del panel lateral de FDs ───────────────────────────────────────
function filtrarFDs(q) {
  const terms = q.toLowerCase().trim().split(/\s+/).filter(Boolean);
  const items = document.querySelectorAll('#fd-list .fd-item');
  let visibles = 0;
  items.forEach(el => {
    const txt = (el.dataset.Codigo + ' ' + el.dataset.plano);
    const match = terms.every(t => txt.includes(t));
    el.style.display = match ? '' : 'none';
    if (match) visibles++;
  });
  document.getElementById('fd-count').textContent = visibles;
}

// Agregar junto a las demás funciones JS
let _ctxData = null;

function abrirContextMenu(e, td) {
  e.preventDefault();
  _ctxData = {
    componente: td.dataset.componente,
    plano:      td.dataset.plano,
    fila_plano: td.dataset.fila
  };
  const menu = document.getElementById('ctx-menu');
  menu.style.display = 'block';
  menu.style.left    = e.clientX + 'px';
  menu.style.top     = e.clientY + 'px';
}

function cerrarContextMenu() {
  document.getElementById('ctx-menu').style.display = 'none';
  _ctxData = null;
}

function confirmarEliminar() {
  const dataAsignacion = _ctxData; 
  console.log("Objeto dataAsignacion guardado con éxito:", dataAsignacion);
  cerrarContextMenu(); 
  if (!dataAsignacion) return;

  const nombrePlano = dataAsignacion.plano;
  const fdelimin = dataAsignacion.componente;
  const mensaje = `¿Estás seguro de eliminar ${fdelimin}, asignada al plano ${nombrePlano}?`;
  if (!confirm(mensaje)) return;

  fetch(`${window.location.origin}/public/api_asignar.php`, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({
      csrf_token: CSRF,
      action:     'eliminar',
      componente: dataAsignacion.componente,
      plano:      dataAsignacion.plano,
      fila_plano: dataAsignacion.fila_plano
    })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success) location.reload();
    else alert('Error: ' + d.message);
  });
}

// Cerrar menú al hacer click en cualquier otro lado
document.addEventListener('click', () => cerrarContextMenu());

// ── Filtro de la tabla principal ──────────────────────────────────────────
function filtrarTabla(q) {
  const terms = q.toLowerCase().trim().split(/\s+/).filter(Boolean);

  // Seleccionamos las filas "cabeza de grupo" (tienen data-plano)
  const cabezas = document.querySelectorAll('#tabla-wrap tbody tr[data-grupo]');
  let visibles = 0;

  cabezas.forEach(tr => {
    const texto = (tr.dataset.plano || '') + ' ' + (tr.dataset.of || '') + ' ' + (tr.dataset.cliente || '');
    const match = terms.length === 0 || terms.every(t => texto.includes(t));

    // Mostrar/ocultar la fila cabeza
    tr.style.display = match ? '' : 'none';

    // Mostrar/ocultar todas las filas secundarias del mismo grupo
    const grupo = tr.dataset.grupo;
    document.querySelectorAll(`#tabla-wrap tbody tr[data-grupo-fila="${CSS.escape(grupo)}"]`)
      .forEach(sub => sub.style.display = match ? '' : 'none');

    if (match) visibles++;
  });

  // Actualizar contador
  const total = cabezas.length;
  const el = document.getElementById('tabla-count');
  if (el) el.textContent = q.trim() ? `${visibles} / ${total} planos` : '';
}

</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>