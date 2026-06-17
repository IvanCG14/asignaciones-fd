<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
/**
 * public/planificacion_sql.php
 * Versión final: Campos de Estación/Modelo + Historial de Fechas
 */
require_once __DIR__ . '/../auth.php'; // Cambiado según tu estructura
require_once __DIR__ . '/../includes/database.php'; // Usa el que adaptamos para config.php
require_once __DIR__ . '/../includes/get_planos.php';

$user        = Auth::requerirLogin();
$puedeEditar = Auth::tieneRol('Gerente', 'admin');
$pageTitle   = 'Planificación';
$navActive   = 'planificacion';

$conn_rw = Database::get(); // Conexión a la DB de modificaciones (SQL Server)
$msg     = ['tipo' => '', 'texto' => ''];
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']); // Lo borramos para que no aparezca de nuevo al recargar manualmente
}

// ── 1. GUARDAR MODIFICACIÓN ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $puedeEditar && isset($_POST['action_save'])) {
    Auth::exigirCsrf();

    $plano    = trim($_POST['plano'] ?? '');
    $of_plano = trim($_POST['orf'] ?? '');
    $f_orig   = $_POST['fecha_orig'] ?? null;
    $f_nueva  = !empty($_POST['fecha_ent']) ? $_POST['fecha_ent'] : null;
    $d_nueva  = trim($_POST['desc_usu'] ?? '') ?: null;
    $e_mod    = trim($_POST['estacion_mod'] ?? '') ?: null;
    $m_mod    = trim($_POST['modelo_mod'] ?? '') ?: null;
    $cli_plan = $_POST['cliente_plan'] ?? null;
    $can_plan = $_POST['cantidad_plan'] ?? null;
    $ava_plan = $_POST['avance_plan'] ?? null;
    $cod_cli = $_POST['cod_cliente'] ?? null;
    $status  = $_POST['status_job'] ?? null;

    $f_nueva_norm = null;

    $f_orig_norm = $f_orig;
    $dt3 = date_create_from_format('Y-m-d', $f_orig)
        ?: date_create_from_format('d/m/Y', $f_orig)
        ?: date_create_from_format('d/m/y', $f_orig);
    if ($dt3) $f_orig_norm = $dt3->format('Y-m-d');

    if ($plano) {
        // Lógica de Historial de Fechas (si cambió la fecha)
        if ($f_nueva) {
            // 1. Buscamos si ya existe una modificación previa en nuestra DB local
            $sqlLast = "SELECT TOP 1 fecha_nueva 
                FROM Historial_Fechas 
                WHERE plano = ? 
                ORDER BY cambiado_en DESC";
    
            $s = sqlsrv_query($conn_rw, $sqlLast, [$plano]);
            $res_hist = $s ? sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC) : null;
            if ($s) sqlsrv_free_stmt($s);
            // 2. Determinamos contra qué comparar: 
            // Si ya hubo una modificación, comparamos contra esa. Si no, contra la original de JB.
            $fecha_comparar = ($res_hist['fecha_nueva'] ?? $f_orig);

            // Convertir a string si es objeto para comparar
            if ($fecha_comparar instanceof DateTime) {
                $fecha_comparar = $fecha_comparar->format('Y-m-d');
            } elseif ($fecha_comparar) {
                // El campo hidden fecha_orig se llena desde JS como dd/mm/yy o dd/mm/yyyy
                // Normalizamos cualquier formato a Y-m-d
                $dt = date_create_from_format('d/m/y', $fecha_comparar)
                   ?: date_create_from_format('d/m/Y', $fecha_comparar)
                   ?: date_create_from_format('Y-m-d', $fecha_comparar);
                if ($dt) {
                    $fecha_comparar = $dt->format('Y-m-d');
                }
            }

            $f_nueva_norm = $f_nueva;
            $dt2 = date_create_from_format('Y-m-d', $f_nueva)
                ?: date_create_from_format('d/m/Y', $f_nueva)
                ?: date_create_from_format('d/m/y', $f_nueva);
            if ($dt2) $f_nueva_norm = $dt2->format('Y-m-d');
            
            // 3. Solo registramos en historial si el valor es realmente distinto
            if ($fecha_comparar !== $f_nueva_norm) {
                $sqlInsHist = "INSERT INTO Historial_Fechas 
                            (plano, of_plano, fecha_anterior, fecha_nueva, id_usu, cambiado_en) 
                            VALUES (?, ?, ?, ?, ?, GETDATE())";
                
                $paramsHist = [
                    $plano, 
                    $of_plano,
                    $fecha_comparar, 
                    $f_nueva_norm, 
                    $user['id_usu']
                ];
                
                $stmtHist = sqlsrv_query($conn_rw, $sqlInsHist, $paramsHist);
                if ($stmtHist) sqlsrv_free_stmt($stmtHist);
            }
        }

        $f_para_tabla = $f_nueva_norm ?? ($f_orig_norm);

        // MERGE (Upsert) incluyendo Estación y Modelo
        $sqlMerge = "MERGE Info_Bombas AS target
                     USING (SELECT ? AS plano, ? AS ORF) AS src 
                        ON target.plano = src.plano AND target.ORF = src.ORF
                     WHEN MATCHED THEN
                       UPDATE SET fecha_entrega = ?, desc_usu = ?, estacion = ?, modelo = ?,
                                  nombre_cliente = ?, cant = ?, por_avance = ?, cod_cliente = ?, [status] = ?
                     WHEN NOT MATCHED THEN
                       INSERT (plano, ORF, fecha_entrega, desc_usu, estacion, modelo, nombre_cliente, cant, por_avance, cod_cliente, [status])
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);";
        
        $params = [$plano, $of_plano, [$f_para_tabla, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_SMALLDATETIME],
                   $d_nueva, $e_mod, $m_mod, $cli_plan, $can_plan, $ava_plan, $cod_cli, $status,
                   $plano, $of_plano, [$f_para_tabla, SQLSRV_PARAM_IN, SQLSRV_PHPTYPE_STRING(SQLSRV_ENC_CHAR), SQLSRV_SQLTYPE_SMALLDATETIME],
                   $d_nueva, $e_mod, $m_mod, $cli_plan, $can_plan, $ava_plan, $cod_cli, $status];
        
        $stmtMerge = sqlsrv_query($conn_rw, $sqlMerge, $params);
        if ($stmtMerge) sqlsrv_free_stmt($stmtMerge);

        $mergeError = sqlsrv_errors();
        if ($mergeError) {
            $mensajeSimplificado = Auth::simplificarErrorSql($mergeError);
            $msg = ['tipo' => 'error', 'texto' => $mensajeSimplificado];
        } else {
            // Guardamos el mensaje en la sesión para que no se pierda al redirigir
            $_SESSION['flash_msg'] = ['tipo' => 'success', 'texto' => "Plano {$plano} actualizado correctamente."];
    
            // Redirigimos a la misma página (esto limpia el POST)
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        // Diagnóstico temporal - eliminar después
        file_put_contents(__DIR__ . '/merge_debug.txt', 
            date('H:i:s') . " plano=$plano of=$of_plano f_para_tabla=$f_para_tabla errores=" . 
            print_r(sqlsrv_errors(), true) . "\n", 
            FILE_APPEND
        );
    }
}

// ── 2. CARGAR DATOS ──────────────────────────────────────────────────────────
$conn_jb = Database::getJB();
$items = Planos::getPlanos($conn_jb, $conn_rw, '');

// Agrupación por mes
$grupos = [];

usort($items, function($a, $b) { //Ordenar por fechas
    return strcmp($a['fecha_final'] ?? '9999-12-31', $b['fecha_final'] ?? '9999-12-31');
});

foreach ($items as $it) {
    $m = $it['fecha_final'] ? date('Y-m', strtotime($it['fecha_final'])) : '9999-12';
    $grupos[$m]['label'] = $it['fecha_final'] ? ucfirst(IntlDateFormatter::formatObject(new DateTime($it['fecha_final']), 'MMMM/yyyy', 'es_ES')) : 'Sin Fecha';
    $grupos[$m]['items'][] = $it;
}
ksort($grupos);

include __DIR__ . '/../includes/layout_top.php'; 
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<?php if (!empty($msg['texto'])): ?>
    <div id="toast-notificacion" class="toast-box toast-<?= $msg['tipo'] ?>">
        <div class="toast-contenido">
            <span class="toast-icono">
                <?= $msg['tipo'] === 'success' 
                    ? '<i class="fa-regular fa-circle-check" style="color: var(--success);"></i>' 
                    : '<i class="fa-regular fa-circle-xmark" style="color: var(--danger);"></i>' 
                ?>
            </span>
            <div class="toast-mensaje">
                <span class="toast-titulo"><?= $msg['tipo'] === 'success' ? 'Éxito' : 'Error' ?></span>
                <p><?= htmlspecialchars($msg['texto']) ?></p>
            </div>
        </div>
        <button class="toast-cerrar" onclick="cerrarToast()">×</button>
    </div>
<?php endif; ?>

<!-- FORMULARIO (Actualizado con Estación y Modelo) -->
<div id="contenedor-scroll-objetivo">
<?php if ($puedeEditar): ?> 
<div id="form-planificacion" class="card" style="margin-bottom:2rem; border-left:4px solid var(--accent); padding:0; overflow:hidden;">
    <div onclick="toggleFormularioPlanificacion()" style="padding: 1rem; background: rgba(0,0,0,0.15); cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none;">
        <div class="card-title" style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-regular fa-pen-to-square" style="color: var(--accent);"></i>
            Modificar Plano
        </div>
        <svg id="plan-icon-flecha" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-dim)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 0.2s;">
            <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
    </div>

    <div id="form-plan-desplegable" style="display: none; padding: 1rem; border-top: 1px solid var(--border);">
        <form method="POST" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:1rem; align-items:flex-end">
            <input type="hidden" name="csrf_token" value="<?php echo Auth::csrfToken(); ?>">
            <input type="hidden" name="cliente_plan" id="inp-cliente">
            <input type="hidden" name="cantidad_plan" id="inp-cantidad">
            <input type="hidden" name="avance_plan" id="inp-avance">
            <input type="hidden" name="cod_cliente" id="inp-cod-cliente">
            <input type="hidden" name="status_job" id="inp-status"> 
            <div><label>Plano #</label><input type="text" name="plano" id="inp-plano" readonly class="form-control" style="background:var(--surface2)"></div>
            <div><label>Fecha Actual</label><input type="text" name="fecha_orig" id="inp-fecha-actual" class="form-control" readonly style="background:var(--surface2)"></div>
            <div><label>OF Cliente</label><input type="text" name="orf" id="inp-of" class="form-control" readonly style="background:var(--surface2)"></div>
            <div><label>Nueva Fecha</label><input type="date" name="fecha_ent" id="inp-fecha-nueva" class="form-control"></div>
            <div><label>Estación</label><input type="text" name="estacion_mod" id="inp-est" class="form-control"></div>
            <div><label>Modelo</label><input type="text" name="modelo_mod" id="inp-mod" class="form-control"></div>
            <div style="grid-column:span 2"><label>Descripción</label><input type="text" name="desc_usu" id="inp-desc" class="form-control"></div>
            <div style="display: flex; gap: 0.5rem;">
                <button type="submit" name="action_save" class="btn btn-primary" style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fa-regular fa-floppy-disk"></i> GUARDAR CAMBIOS
                </button>
                
                <button type="button" id="btn-limpiar" class="btn btn-secondary" style="background: var(--surface2); color: var(--text); display: flex; align-items: center; justify-content: center; gap: 8px;">
                    <i class="fa-regular fa-trash-can"></i> LIMPIAR
                </button>
            </div>
        </form>
    </div>
</div> 
<?php endif; ?>
</div>

<!-- TABLA (Estilo combinado: Cliente/Estación y Descripción/Modelo) -->
<div class="table-wrap">
    <!-- Buscador -->
    <div class="card" style="margin-bottom: 1rem; padding: 12px 16px;">
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text-dim)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.7;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="buscador-of" class="form-control" placeholder="Buscar por número de OF o Plano..." style="max-width: 400px; margin: 0;">
            </div>
            <!-- Separador -->
            <div style="width:1px; height:24px; background:var(--border);"></div>
            <!-- Filtro por rango de fechas (nuevo) -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                    fill="none" stroke="var(--text-dim)" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.7; flex-shrink:0;">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <input type="date" id="fecha-desde" class="form-control"
                    style="max-width:150px; margin:0;"
                    title="Fecha desde">
                <span style="color:var(--text-dim); font-size:.8rem;">—</span>
                <input type="date" id="fecha-hasta" class="form-control"
                    style="max-width:150px; margin:0;"
                    title="Fecha hasta">
                <button id="btn-limpiar-fechas" class="btn btn-secondary btn-sm"
                        style="padding: 4px 10px; font-size: .72rem; white-space:nowrap;">
                    ✕
                </button>
            </div>

            <div style="width:1px; height:24px; background:var(--border);"></div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; font-size: .8rem; color: var(--text-dim);">
                    <input type="checkbox" id="filtro-closed" style="display: none;">
                    <div id="switch-track" style="width: 36px; height: 18px; background: var(--surface2); border: 1px solid var(--border); border-radius: 9px; position: relative; transition: all 0.3s ease;">
                        <div id="switch-handle" style="width: 12px; height: 12px; background: var(--text-dim); border-radius: 50%; position: absolute; top: 2px; left: 3px; transition: all 0.3s ease;"></div>
                    </div>
                    <span id="label-status-switch" style="font-weight: 600;">Planos Activos</span>
                </label>
            </div>
            <!-- Separador -->
            <div style="width:1px; height:24px; background:var(--border);"></div>
            <!-- Botón imprimir -->
            <button onclick="imprimirTabla()" class="btn btn-secondary btn-sm"
                    style="display:flex; align-items:center; gap:6px; white-space:nowrap;">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                    fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 6 2 18 2 18 9"></polyline>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                Imprimir
            </button>
        </div>
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 16%;">Cliente / Estación</th>
                <th style="width: 26%;">Cant. / Modelo / Descripción</th>
                <th style="width: 12%;">OF #</th>
                <th style="width: 14%;">Plano #</th>
                <?php if ($puedeEditar): ?>
                    <th style="width: 14%;">Avance %</th>
                    <th style="width: 10%;">F/Ent.</th>
                    <th style="width: 8%;"> Editar</th>
                <?php else: ?>
                    <th style="width: 18%;">Avance %</th>
                    <th style="width: 14%;">F/Ent.</th>
                <?php endif; ?> 
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grupos as $mes_id => $g): ?>
                <tr class="header-grupo" style="background:var(--surface2); cursor:pointer;" onclick="toggleGrupo('<?= $mes_id ?>', this)">
                    <td colspan="7" style="color:var(--accent); font-family:var(--display); font-size:1.1rem; padding: 10px 0;">
                        <div style="display: flex; justify-content: center; align-items: center; width: 100%;">
                            
                            <div style="display: flex; align-items: center; width: 100px; justify-content: flex-start;">
                                
                                <div style="width: 30px; display: flex; justify-content: center; flex-shrink: 0;">
                                    <span class="icon-flecha" style="transition: transform 0.3s; display: inline-block; transform: rotate(-90deg);">▼</span>
                                </div>
                                
                                <div style="white-space: nowrap;">
                                    <?= $g['label'] ?>
                                </div>
                                
                            </div>
                        </div>
                    </td>
                </tr>
                <?php foreach ($g['items'] as $r): ?>
                <tr class="grupo-contenido-<?= $mes_id ?> fila-plano" data-of="<?= htmlspecialchars(strtolower($r['of'])) ?>"
                    data-plano="<?= htmlspecialchars(strtolower($r['plano'])) ?>" 
                    data-fecha="<?= htmlspecialchars($r['fecha_final'] ?? '') ?>"
                    data-status="<?= htmlspecialchars(strtolower($r['status'] ?? '')) ?>"
                    style="display: none;">
                    <td>
                        <div style="font-weight:600"><?= htmlspecialchars($r['cliente']) ?></div>
                        <div style="font-size:.7rem; color:var(--accent)"><?= htmlspecialchars($r['estacion']) ?></div>
                    </td>
                    <td>
                        <div style="font-size:.8rem"><strong><?= $r['cant'] ?></strong> - <?= htmlspecialchars($r['modelo']) ?></div>
                        <div style="font-size:.7rem; color:var(--text-dim); font-style:italic"><?= htmlspecialchars($r['desc']) ?></div>
                    </td>
                    <td style="font-family:var(--mono)"><?= $r['of'] ?></td>
                    <td class="col-plano" style="font-family:var(--mono); font-weight:bold"><?= $r['plano'] ?></td>
                    <td>
                        <?php if ($r['avance'] >= 100): ?>
                            <div style="color: var(--success); font-weight: bold; font-family: var(--display); font-size: 0.9rem; letter-spacing: 1px;">
                                TERMINADO
                            </div>
                        <?php else: ?>
                            <!-- Barra de Avance -->
                            <div style="margin: 0; background: var(--border); height: 6px; border-radius: 2px; width: 100px;">
                                <div style="background: var(--success); height: 100%; border-radius: 2px; width: <?= min($r['avance'], 100) ?>%;"></div>
                            </div>
                            <div style="font-size: .65rem; color: var(--success); font-family: var(--mono);">
                                AVANCE: <?= $r['avance'] ?>%
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php 
                        // 1. Mostrar historial tachado (rojo)
                        if ($r['fecha_hist']) {
                            foreach(explode(',', $r['fecha_hist']) as $fh) {
                                echo "<div style='text-decoration:line-through; color:var(--danger); font-size:.7rem'>".date('d/m/y', strtotime($fh))."</div>";
                            }
                        }
                        // 2. Fecha Final (Negrita/Verde si es modificado)
                        $isMod = $r['fecha_jb'] !== $r['fecha_final'];
                        echo "<div style='font-weight:".($isMod?'bold':'normal')."; color:".($isMod?'var(--success)':'inherit')."'>".date('d/m/y', strtotime($r['fecha_final']))."</div>";
                        ?>
                    </td>
                    <?php if ($puedeEditar): ?> 
                        <td style="text-align: right;"><button class="btn-cargar btn btn-secondary btn-sm" data-json='<?= json_encode($r) ?>'>✎</button></td>
                    <?php endif; ?> 
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>

/* Ajustes para que la tabla ocupe el 100% y sea scrollable */
.table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--border);
    background: var(--surface);
    max-height: 80vh; 
    overflow-y: auto;
}

/* Forzar que la tabla no se colapse y use todo el espacio */
table.data-table {
    width: 100%;
    min-width: 750px; /* Evita que las columnas se amontonen */
    table-layout: fixed;
}

/* Estilo para la barra de desplazamiento (Scrollbar) */
.table-wrap::-webkit-scrollbar {
    height: 10px; /* Grosor scroll horizontal */
    width: 8px;   /* Grosor scroll vertical */
}
.table-wrap::-webkit-scrollbar-thumb {
    background: var(--accent);
    border-radius: var(--radius);
}
.table-wrap::-webkit-scrollbar-track {
    background: var(--surface2);
}

.col-plano {
    max-width: 150px;
    word-wrap: break-word; /* Rompe las palabras largas */
    overflow-wrap: break-word;
    white-space: normal;   /* Permite que el texto use varias líneas */
}

#contenedor-scroll-objetivo {
    scroll-margin-top: 100px; /* Ajusta este valor según la altura de tu menú superior */
}

/* Clase para resaltar la fila en edición */
.fila-editando {
    background-color: rgba(var(--accent-rgb), 0.1) !important; /* Un tono suave del color de acento */
    border-left: 5px solid var(--accent) !important;
    box-shadow: inset 0 0 10px rgba(0,0,0,0.2);
    transition: all 0.3s ease;
}

.data-table thead tr {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--surface);
    box-shadow: 0 1px 0 var(--border)
}

/* Opcional: para que la transición sea suave en todas las filas */
.data-table tr {
    transition: background-color 0.3s ease, border-left 0.3s ease;
    border-left: 5px solid transparent; /* Evita que la tabla "salte" al activar el borde */
}

.header-grupo {
    position: sticky;
    top: 41px; /* altura del thead — ajusta si cambia */
    z-index: 1;
    background: var(--surface2);
    box-shadow: 0 1px 0 var(--border), 0 -1px 0 var(--border); 
}

/* Contenedor principal del Toast */
.toast-box {
    position: fixed;
    top: 100px;
    right: 20px;
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.25);
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    z-index: 9999;
    min-width: 300px;
    max-width: 450px;
    transform: translateX(120%);
    transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

/* Clase activa para la animación de entrada */
.toast-box.mostrar {
    transform: translateX(0);
}

/* Variaciones de color según el tipo */
.toast-success { border-left: 5px solid var(--success); }
.toast-error { border-left: 5px solid var(--danger); }

.toast-contenido {
    display: flex;
    align-items: center;
    gap: 12px;
}

.toast-icono { font-size: 1.5rem; }

.toast-mensaje .toast-titulo {
    font-weight: bold;
    font-size: 0.95rem;
    color: var(--text);
    display: block;
}

.toast-mensaje p {
    margin: 3px 0 0 0;
    font-size: 0.85rem;
    color: var(--text-dim);
}

.toast-cerrar {
    background: none;
    border: none;
    color: var(--text-dim);
    font-size: 1.4rem;
    cursor: pointer;
    padding: 0 5px;
}
.toast-cerrar:hover { color: var(--text); }
</style>

<script>
// Al hacer clic en editar, cargamos todo al formulario
document.querySelectorAll('.btn-cargar').forEach(btn => {
    btn.addEventListener('click', () => {
        try {
            // 1. Quitar el resaltado de cualquier otra fila antes de marcar la nueva
            document.querySelectorAll('tr').forEach(tr => tr.classList.remove('fila-editando'));

            // 2. Agregar la clase a la fila (tr) donde está el botón
            const filaActual = btn.closest('tr');
            if (filaActual) {
                filaActual.classList.add('fila-editando');
            }

            const d = JSON.parse(btn.dataset.json);
            
            // --- Tu lógica de llenado de campos (Asegúrate de tener el ID 'inp-fecha-nueva') ---
            document.getElementById('inp-plano').value = d.plano;
            
            if (d.fecha_final && d.fecha_final !== 'S/F') {
                const partes = d.fecha_final.split('-'); 
                const fechaFormateada = `${partes[2]}/${partes[1]}/${partes[0]}`;
                document.getElementById('inp-fecha-actual').value = fechaFormateada;
            } else {
                document.getElementById('inp-fecha-actual').value = 'S/F';
            }

            document.getElementById('inp-of').value = d.of;
            document.getElementById('inp-est').value   = d.estacion;
            document.getElementById('inp-mod').value   = d.modelo;
            document.getElementById('inp-desc').value  = d.desc;

            // Dentro de btn.addEventListener('click', () => { ...
            document.getElementById('inp-cliente').value  = d.cliente;
            document.getElementById('inp-cantidad').value = d.cant;
            document.getElementById('inp-avance').value   = d.avance;
            document.getElementById('inp-cod-cliente').value = d.cod_cliente;
            document.getElementById('inp-status').value   = d.status;

            // --- Scroll hacia arriba ---
            asegurarPlanificacionAbierta();
            document.getElementById('contenedor-scroll-objetivo').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e) {
            console.error("Error al cargar datos:", e);
        }
    });
});

function toggleFormularioPlanificacion() {
    const contenedorForm = document.getElementById('form-plan-desplegable');
    const flecha = document.getElementById('plan-icon-flecha');
    
    if (contenedorForm.style.display === 'none') {
        contenedorForm.style.display = 'block';
        flecha.style.transform = 'rotate(180deg)';
    } else {
        contenedorForm.style.display = 'none';
        flecha.style.transform = 'rotate(0deg)';
    }
}

function asegurarPlanificacionAbierta() {
    const contenedorForm = document.getElementById('form-plan-desplegable');
    const flecha = document.getElementById('plan-icon-flecha');
    
    contenedorForm.style.display = 'block';
    flecha.style.transform = 'rotate(180deg)';
}

function toggleGrupo(id, el) {
    const filas = document.querySelectorAll('.grupo-contenido-' + id);
    const flecha = el.querySelector('.icon-flecha');

    let abriendo = false;
    for (let fila of filas) {
        if (fila.classList.contains('fila-activa') && (fila.style.display === "none" || fila.style.display === "")) {
            abriendo = true;
            break;
        }
    }

    filas.forEach(fila => {
        // Si la fila está oculta (o no tiene estilo definido), la mostramos
        if (abriendo) {
            if (fila.classList.contains('fila-activa')) {
                fila.style.display = "table-row";
            } else {
                fila.style.display = "none";
            }
            if (flecha) flecha.style.transform = "rotate(0deg)"; // Flecha hacia abajo
        } else {
            fila.style.display = "none";
            if (flecha) flecha.style.transform = "rotate(-90deg)"; // Flecha hacia el lado
        }
    });
}

// Esperar a que el DOM cargue para activar la animación de entrada del Toast
document.addEventListener("DOMContentLoaded", () => {
    const toast = document.getElementById("toast-notificacion");
    if (toast) {
        // Un pequeño delay para que la transición CSS se note suave
        setTimeout(() => {
            toast.classList.add("mostrar");
        }, 100);

        // Auto-cerrar después de 4 segundos (4000ms)
        setTimeout(cerrarToast, 4100);
    }
});

function cerrarToast() {
    const toast = document.getElementById("toast-notificacion");
    if (toast) {
        toast.classList.remove("mostrar");
        // Esperamos a que termine la animación de salida antes de removerlo del layout
        setTimeout(() => { toast.remove(); }, 400);
    }
}

document.getElementById('fecha-desde').value = new Date().getFullYear() + '-01-01';
aplicarFiltros(); // aplicar el filtro inicial con esa fecha

function aplicarFiltros() {
    const textoOF   = document.getElementById('buscador-of').value.trim().toLowerCase();
    const desdeTxt  = document.getElementById('fecha-desde').value;  // 'YYYY-MM-DD' o ''
    const hastaTxt  = document.getElementById('fecha-hasta').value;
    const mostrarClosed = document.getElementById('filtro-closed').checked;

    const desde = desdeTxt ? new Date(desdeTxt) : null;
    const hasta = hastaTxt ? new Date(hastaTxt) : null;
    if (hasta) hasta.setHours(23, 59, 59); // incluir el día completo

    const filas            = document.querySelectorAll('.fila-plano');
    const cabecerasGrupos  = document.querySelectorAll('.header-grupo');
    const hayBusquedaActiva = textoOF || desde || hasta;
    const conteoPorGrupo = {};

    filas.forEach(fila => {
        const statusFila = fila.getAttribute('data-status') || '';
        // Si el switch está activo busca 'closed', si no busca cualquiera diferente de 'closed'
        const pasaStatus = mostrarClosed ? (statusFila === 'closed') : (statusFila !== 'closed');

        let visible = pasaStatus;

        if (visible && hayBusquedaActiva) {
            // Filtro OF / Plano
            const of = fila.getAttribute('data-of') || '';
            const plano = fila.getAttribute('data-plano') || '';
            const pasaOF = !textoOF || (of.includes(textoOF) || plano.includes(textoOF));
            
            // Filtro Fechas
            const fechaTxt = fila.getAttribute('data-fecha');
            let pasaFecha = true;
            if (fechaTxt && fechaTxt !== 'S/F') {
                const fechaFila = new Date(fechaTxt);
                if (desde && fechaFila < desde) pasaFecha = false;
                if (hasta && fechaFila > hasta) pasaFecha = false;
            } else if (desde || hasta) {
                pasaFecha = false; 
            }
            visible = pasaOF && pasaFecha;
        }

        // Asignamos clase identificadora para impresión e interacción manual
        if (visible) {
            fila.classList.add('fila-activa');
            const claseGrupo = [...fila.classList].find(c => c.startsWith('grupo-contenido-'));
            if (claseGrupo) {
                const mesId = claseGrupo.replace('grupo-contenido-', '');
                conteoPorGrupo[mesId] = (conteoPorGrupo[mesId] || 0) + 1;
            }
        } else {
            fila.classList.remove('fila-activa');
        }

        // Renderizado visual en tiempo real de la fila
        if (!visible) {
            fila.style.display = 'none';
        } else {
            if (!hayBusquedaActiva) {
                fila.style.display = 'none'; // Se mantiene colapsado si no hay términos de búsqueda escritos
            } else {
                fila.style.display = 'table-row';
            }
        }
    });

    const totalVisibles = Object.values(conteoPorGrupo).reduce((a, b) => a + b, 0);

    cabecerasGrupos.forEach(cabecera => {
        const onclick = cabecera.getAttribute('onclick') || '';
        const match   = onclick.match(/'([^']+)'/);
        if (!match) return;
        const mesId = match[1];

        const hayResultados = (conteoPorGrupo[mesId] || 0) > 0;
        cabecera.style.display = hayResultados ? 'table-row' : 'none';

        if (!hayResultados) return;

        const flecha = cabecera.querySelector('.icon-flecha');

        if (!hayBusquedaActiva) {
            if (flecha) flecha.style.transform = 'rotate(-90deg)';
            document.querySelectorAll('.grupo-contenido-' + mesId).forEach(f => f.style.display = 'none');
        } else {
            if (totalVisibles > 20) {
                document.querySelectorAll('.grupo-contenido-' + mesId).forEach(f => f.style.display = 'none');
                if (flecha) flecha.style.transform = 'rotate(-90deg)';
            } else {
                document.querySelectorAll('.grupo-contenido-' + mesId).forEach(f => {
                    f.style.display = f.classList.contains('fila-activa') ? 'table-row' : 'none';
                });
                if (flecha) flecha.style.transform = 'rotate(0deg)';
            }
        }
    });
}

document.getElementById('buscador-of').addEventListener('input', aplicarFiltros);
document.getElementById('fecha-desde').addEventListener('change', aplicarFiltros);
document.getElementById('fecha-hasta').addEventListener('change', aplicarFiltros);

document.getElementById('filtro-closed').addEventListener('change', function() {
    const track = document.getElementById('switch-track');
    const handle = document.getElementById('switch-handle');
    const label = document.getElementById('label-status-switch');
    
    if (this.checked) {
        track.style.background = 'var(--accent)';
        track.style.borderColor = 'var(--accent)';
        handle.style.left = '19px';
        handle.style.background = '#fff';
        label.innerText = 'Planos Cerrados';
        label.style.color = 'var(--accent)';
    } else {
        track.style.background = 'var(--surface2)';
        track.style.borderColor = 'var(--border)';
        handle.style.left = '3px';
        handle.style.background = 'var(--text-dim)';
        label.innerText = 'Planos Activos';
        label.style.color = 'var(--text-dim)';
    }
    aplicarFiltros();
});

document.getElementById('btn-limpiar-fechas').addEventListener('click', () => {
    document.getElementById('fecha-desde').value = '';
    document.getElementById('fecha-hasta').value = '';
    aplicarFiltros();
});

// Lógica para el botón Limpiar Campos
document.getElementById('btn-limpiar').addEventListener('click', () => {
    // 1. Remover el resaltado de cualquier fila seleccionada
    document.querySelectorAll('tr').forEach(tr => tr.classList.remove('fila-editando'));

    // 2. Vaciar todos los campos del formulario
    document.getElementById('inp-plano').value = '';
    document.getElementById('inp-fecha-actual').value = '';
    document.getElementById('inp-of').value = '';
    document.getElementById('inp-fecha-nueva').value = '';
    document.getElementById('inp-est').value = '';
    document.getElementById('inp-mod').value = '';
    document.getElementById('inp-desc').value = '';

    // 3. Vaciar también los campos ocultos (hidden)
    document.getElementById('inp-cliente').value = '';
    document.getElementById('inp-cantidad').value = '';
    document.getElementById('inp-avance').value = '';
    document.getElementById('inp-cod-cliente').value = '';
    document.getElementById('inp-status').value = '';
});

function imprimirTabla() {
    const meses = document.querySelectorAll('.header-grupo');
    let html = '';

    meses.forEach(cabecera => {
        if (cabecera.style.display === 'none') return;

        // Extraer mes_id del onclick
        const match = (cabecera.getAttribute('onclick') || '').match(/'([^']+)'/);
        if (!match) return;
        const mesId = match[1];

        // Filas de este grupo
        const filas = [...document.querySelectorAll('.grupo-contenido-' + mesId + '.fila-activa')];
        if (filas.length === 0) return;

        const labelMes = cabecera.innerText.trim();

        html += `<div class="mes-bloque">
            <div class="mes-titulo">${labelMes}</div>
            <table>
                <thead>
                    <tr>
                        <th>Cliente / Estación</th>
                        <th>Cant. / Modelo / Descripción</th>
                        <th>OF #</th>
                        <th>Plano #</th>
                        <th>Avance %</th>
                        <th>F/Ent.</th>
                    </tr>
                </thead>
                <tbody>`;

        filas.forEach(fila => {
            // Copiar celdas 0-5 (omitir la 6 = Editar)
            const celdas = fila.querySelectorAll('td');
            html += '<tr>';
            for (let i = 0; i < 6; i++) {
                html += `<td>${celdas[i]?.innerHTML || ''}</td>`;
            }
            html += '</tr>';
        });

        html += `       </tbody>
            </table>
        </div>`;
    });

    if (!html) {
        alert('No hay filas visibles para imprimir.');
        return;
    }

    const ventana = window.open('', '_blank');
    ventana.document.write(`<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Planificación</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; font-size: 11px; color: #111; padding: 24px; }

        h1 { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .subtitulo { font-size: 10px; color: #666; margin-bottom: 20px; }

        .mes-bloque { margin-bottom: 24px; page-break-inside: avoid; }
        .mes-titulo {
            font-size: 13px; font-weight: 700; letter-spacing: .5px;
            border-bottom: 2px solid #111; padding-bottom: 4px; margin-bottom: 8px;
            text-transform: uppercase;
        }

        table { width: 100%; border-collapse: collapse; }
        thead tr { background: #111; color: #fff; }
        th { padding: 5px 8px; text-align: left; font-size: 10px; font-weight: 600; }
        td { padding: 5px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        tr:nth-child(even) td { background: #f7f7f7; }

        /* Ocultar botones y barras de progreso — solo texto */
        button { display: none !important; }
        div[style*="background: var(--border)"],
        div[style*="background:var(--border)"] { display: none !important; }

        @media print {
            body { padding: 12px; }
            .mes-bloque { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <h1>Planificación de Planos</h1>
    <div class="subtitulo">Generado el ${new Date().toLocaleDateString('es-ES', { day:'2-digit', month:'long', year:'numeric' })}</div>
    ${html}
    <script>window.onload = () => { window.print(); }<\/script>
</body>
</html>`);
    ventana.document.close();
}

</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>