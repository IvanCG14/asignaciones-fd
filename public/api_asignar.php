<?php
/**
 * public/api_asignar.php
 * Maneja asignación automática y manual de FDs a planos.
 *
 * POST action=automatica  → asigna todas las FDs disponibles a planos pendientes
 * POST action=manual       → asigna una FD específica a un plano
 * POST action=soldado      → actualiza checkbox soldado + comentario
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/get_planos.php';

header('Content-Type: application/json');

$user = Auth::usuario();
if (!$user) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'No autorizado']); exit; }

$action = $_POST['action'] ?? '';

// Soldado lo puede hacer editor; asignación solo editor/super
if ($action === 'soldado') {
    if (!Auth::tieneRol('Supervisor', 'admin')) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Sin permisos']); exit;
    }
} else {
    if (!Auth::tieneRol('Gerente', 'Supervisor', 'admin')) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Sin permisos']); exit;
    }
}

Auth::exigirCsrf();

$connJB = Database::getJB();
$connRW = Database::get();

// ── Helper: parsear componente ────────────────────────────────────────────────────
function parsedCOMP(string $fd_comp): array {
    preg_match('/^(FD\d+[A-Z]+)\s+(.+)$/i', $fd_comp, $m);
    return [
        'FD'         => $m[1] ?? $fd_comp,
        'componente' => trim($m[2] ?? ''),
    ];
}

// ── Helper: obtener FDs disponibles de JB_Delta ──────────────────────────────
function getFDsDisponibles($connJB, $connRW, array $codigosPieza = []): array {
    $whereModelo = '';
    $paramsModelo = [];
    $anioActual = (int)date('Y');

    if (!empty($codigosPieza)) {
        $condiciones = array_fill(0, count($codigosPieza), "j.Numero LIKE ? + '%'");
        $whereModelo = 'AND (' . implode(' OR ', $condiciones) . ')';
        $paramsModelo = $codigosPieza;
    }

    $sql = "
        WITH CodigosBase AS (
            SELECT j.Codigo            AS of_fd,
                   j.Numero    AS codigo,
                   j.Cantidad_Ordenada AS cant_fab,
                   j.Estado         AS estado,
                   j.Estado_Fecha	AS fecha
            FROM dbo.Codigo j
            LEFT JOIN dbo.Delivery d ON j.Codigo = d.Codigo
            WHERE j.Assembly_Level = 0
              AND j.Codigo LIKE 'FD%'
              AND YEAR(j.Estado_Fecha) = $anioActual
              AND j.Estado NOT LIKE 'Closed'
              $whereModelo
        )
        SELECT DISTINCT of_fd, codigo, cant_fab, estado
        FROM CodigosBase
        ORDER BY of_fd
    ";

    $stmt = sqlsrv_query($connJB, $sql, $paramsModelo ?: []);
    $fds = [];
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $fd_comp = trim($r['of_fd']);
            $parsed  = parsedCOMP($fd_comp);
            $fds[] = [
                'componente' => $fd_comp,
                'FD'         => $parsed['FD'],
                'cant_fab'   => (int)$r['cant_fab'],
                'status'     => $r['estado'],
                'cod_pza'    => $r['codigo']
            ];
        }
        sqlsrv_free_stmt($stmt);

        usort($fds, function($a, $b) {
            // 1. Forzar a string para evitar el aviso de 'null' (convierten null a '')
            $fd_a = (string)($a['FD'] ?? '');
            $fd_b = (string)($b['FD'] ?? '');

            // 2. Si ambos están vacíos, son iguales
            if ($fd_a === '' && $fd_b === '') return 0;
            // Si uno está vacío, lo mandamos al final
            if ($fd_a === '') return 1;
            if ($fd_b === '') return -1;

            // 3. Ejecutar el preg_match con variables que garantizan ser strings
            preg_match('/^FD(\d{2})(\d+)([A-Z]+)$/i', $fd_a, $ma);
            preg_match('/^FD(\d{2})(\d+)([A-Z]+)$/i', $fd_b, $mb);

            // Si no parsea (formato inesperado), comparar como string común
            if (!$ma || !$mb) return strcmp($fd_a, $fd_b);

            // 4. Comparar por Año (ej: 25)
            $cmp = (int)$ma[1] - (int)$mb[1];
            if ($cmp !== 0) return $cmp;

            // 5. Comparar por Número de FD (ej: 03)
            $cmp = (int)$ma[2] - (int)$mb[2];
            if ($cmp !== 0) return $cmp;

            // 6. Por si acaso, si año y número coinciden, comparar la letra (ej: F)
            return strcmp(strtoupper($ma[3]), strtoupper($mb[3]));
        });
    } else {
        error_log('getFDsDisponibles query error: ' . print_r(sqlsrv_errors(), true));
    }
    return $fds;
}

// ── Helper: insertar asignación ──────────────────────────────────────────────
function insertarAsignacion($connRW, array $data): bool {
    $sql = "INSERT INTO Asignaciones_FD
                (cod_pieza, OT_FD, FD, componente, cant, status_OT, area_actual, plano_asignado,
                 status_tarea_actual, fecha_entrega, modelo_bomba, fila_plano)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
    $r = sqlsrv_query($connRW, $sql, [
        $data['cod_pza'],
        $data['OT_FD'],
        $data['FD'],
        $data['componente'],
        $data['cant_fab'],
        $data['status_OT'],
        $data['area_actual'],
        $data['plano_asignado'],
        $data['status_tarea_actual'],
        $data['fecha'],
        $data['modelo_bomba'],
        $data['fila_plano'],
    ]);
    return $r !== false;
}

// ════════════════════════════════════════════════════════════════════════════
// ACCIÓN: AUTOMÁTICA
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'automatica') {

    // 1. Todos los planos ordenados por fecha de entrega ascendente (más urgente primero)
    $todosPlanos = Planos::getPlanos($connJB, $connRW);
    usort($todosPlanos, function($a, $b) {
        $fa = $a['fecha_final'] ?? '9999-12-31';
        $fb = $b['fecha_final'] ?? '9999-12-31';
        return strcmp($fa, $fb);
    });

    // 2. Componentes por modelo
    $compPorModelo = [];
    $compStmt = sqlsrv_query($connRW, "SELECT cod_pza, nombre_componente, modelo FROM Componentes");
    if ($compStmt) {
        while ($r = sqlsrv_fetch_array($compStmt, SQLSRV_FETCH_ASSOC)) {
            $compPorModelo[$r['modelo']][] = ['cod_pza' => $r['cod_pza'], 'nombre' => $r['nombre_componente']];
        }
        sqlsrv_free_stmt($compStmt);
    }

    // 3. FDs disponibles por modelo (cache)
    $modelosUnicos = array_unique(array_map(function($p) {
        preg_match('/^(BAF-\d+)/i', $p['modelo'], $m);
        return $m[1] ?? $p['modelo'];
    }, $todosPlanos));

    $cacheFDs = [];
    foreach ($modelosUnicos as $mod) {
        $codsModelo = array_column($compPorModelo[$mod] ?? [], 'cod_pza');
        if (empty($codsModelo)) continue;
        $cacheFDs[$mod] = getFDsDisponibles($connJB, $connRW, $codsModelo);
    }

    // 4. Calcular asignación ideal desde cero
    //    Estructura: $ideal[componente_fd][cant] = ['plano'=>.., 'of'=>.., 'fila_plano'=>.., ...]
    $ideal      = [];   // lo que DEBE quedar
    $conteoFD   = [];   // cuántas filas lleva cada componente FD

    foreach ($todosPlanos as $plano) {
        preg_match('/^(BAF-\d+)/i', $plano['modelo'], $mMatch);
        $modelo = $mMatch[1] ?? $plano['modelo'];
        $cant   = min((int)($plano['cant'] ?? 1), 2);

        $compsModelo = $compPorModelo[$modelo] ?? [];
        $fdsDisp     = $cacheFDs[$modelo]      ?? [];
        if (empty($compsModelo)) continue;

        // Fecha para guardar
        $fechaInput = trim($plano['fecha_final'] ?? '');
        $fechaSql   = null;
        if (!empty($fechaInput)) {
            $dt = DateTime::createFromFormat('Y-m-d', $fechaInput)
               ?: DateTime::createFromFormat('d/m/Y', $fechaInput);
            if ($dt) {
                $anio = (int)$dt->format('Y');
                if ($anio >= 1900 && $anio <= 2079) $fechaSql = $dt->format('Ymd 00:00:00');
            }
        }

        foreach ($compsModelo as $comp) {
            $cod_pza = $comp['cod_pza'];

            $fdsComp = array_values(array_filter($fdsDisp, function($fd) use ($cod_pza) {
                return strpos($fd['cod_pza'], $cod_pza) === 0 || strpos($cod_pza, $fd['cod_pza']) === 0;
            }));
            if (empty($fdsComp)) continue;

            for ($fila = 1; $fila <= $cant; $fila++) {
                // Buscar el primer FD con capacidad disponible en orden alfabético
                foreach ($fdsComp as $fd) {
                    $usado = $conteoFD[$fd['componente']] ?? 0;
                    if ($usado >= $fd['cant_fab']) continue; // lleno

                    $cantAsig = $usado + 1; // siguiente hueco secuencial
                    $conteoFD[$fd['componente']] = $usado + 1;

                    $parsed = parsedCOMP($fd['componente']);
                    $ideal[$fd['componente']][$cantAsig] = [
                        'cod_pza'             => $cod_pza,
                        'OT_FD'               => $plano['of'],
                        'FD'                  => $parsed['FD'],
                        'componente'          => $fd['componente'],
                        'cant_fab'            => $cantAsig,
                        'status_OT'           => $plano['status'],
                        'area_actual'         => '-',
                        'plano_asignado'      => $plano['plano'],
                        'status_tarea_actual' => $fd['status'],
                        'fecha'               => $fechaSql,
                        'modelo_bomba'        => $modelo,
                        'fila_plano'          => $fila,
                    ];
                    break;
                }
            }
        }
    }

    // 5. Cargar asignaciones actuales de la DB
    $actual = [];
    $saStmt = sqlsrv_query($connRW,
        "SELECT componente, cant, OT_FD, plano_asignado, fila_plano, area_actual
         FROM Asignaciones_FD WHERE componente IS NOT NULL");
    if ($saStmt) {
        while ($r = sqlsrv_fetch_array($saStmt, SQLSRV_FETCH_ASSOC)) {
            $actual[$r['componente']][$r['cant']] = $r;
        }
        sqlsrv_free_stmt($saStmt);
    }

    // 6. Comparar ideal vs actual → detectar cambios
    $aEliminar = []; // ['componente'=>.., 'cant'=>..]
    $aInsertar = []; // filas del $ideal

    foreach ($ideal as $comp => $filasIdeal) {
        foreach ($filasIdeal as $cant => $row) {
            $actualRow = $actual[$comp][$cant] ?? null;
            $igual = $actualRow
                && $actualRow['plano_asignado'] === $row['plano_asignado']
                && (int)$actualRow['fila_plano'] === (int)$row['fila_plano']
                && $actualRow['OT_FD']           === $row['OT_FD'];

            if (!$igual) {
                if ($actualRow) $aEliminar[] = ['componente' => $comp, 'cant' => $cant];
                $aInsertar[] = $row;
            }
        }
    }

    // Filas que existen en DB pero ya no están en el ideal (plano eliminado o FD llena de otra forma)
    foreach ($actual as $comp => $filasActual) {
        foreach ($filasActual as $cant => $row) {
            if (!isset($ideal[$comp][$cant])) {
                // Solo eliminar si el plano asignado sigue existiendo en la lista de planos
                // (si el plano fue eliminado de JB, no tocamos — decisión conservadora)
                // Si quieres limpiar huérfanos descomenta:
                // $aEliminar[] = ['componente' => $comp, 'cant' => $cant];
            }
        }
    }

    // 7. Aplicar cambios en DB
    $totalEliminados = 0;
    $totalInsertados = 0;
    $errores         = [];

    foreach ($aEliminar as $del) {
        $r = sqlsrv_query($connRW,
            "DELETE FROM Asignaciones_FD WHERE componente=? AND cant=?",
            [$del['componente'], $del['cant']]);
        if ($r !== false) $totalEliminados++;
    }

    foreach ($aInsertar as $ins) {
        $ok = insertarAsignacion($connRW, $ins);
        if ($ok) $totalInsertados++;
        else $errores[] = "{$ins['componente']} → {$ins['plano_asignado']} fila {$ins['fila_plano']}";
    }

    $cambios = $totalInsertados;
    echo json_encode([
        'success'    => true,
        'asignados'  => $cambios,
        'eliminados' => $totalEliminados,
        'errores'    => $errores,
        'message'    => $cambios === 0
            ? 'Sin cambios: la asignación ya está al día.'
            : "Reasignación completa: {$cambios} registros actualizados.",
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// ACCIÓN: MANUAL
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'manual') {
    $FD_comp  = trim($_POST['componente']  ?? '');
    $codp     = trim($_POST['codp']  ?? '');
    $valorCompuesto = trim($_POST['plano'] ?? ''); // Contiene "plano|OF"
    list($plano, $of) = explode('|', $valorCompuesto);

    // Validación de seguridad: si no se pudieron extraer ambos componentes, detenemos el proceso de forma limpia
    if (empty($plano) || empty($of)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $valorCompuesto
        ]);
        exit;
    }
    
    $estadoPlano = trim($_POST['estado_plano'] ?? '');
    $fila   = (int)($_POST['fila_num'] ?? 1);

    if (!$FD_comp || !$plano || !in_array($fila, [1,2])) {
        echo json_encode(['success'=>false,'message'=>'Datos incompletos']); exit;
    }

    // Verificar que la FD existe en JB_Delta
    $chkFD = sqlsrv_query($connJB,
        "SELECT j.Codigo, j.Numero, j.Cantidad_Ordenada, j.Estado
        FROM dbo.Codigo j LEFT JOIN dbo.Delivery d ON j.Codigo=d.Codigo
        WHERE j.Codigo=?",
        [$FD_comp]
    );
    $fdRow = $chkFD ? sqlsrv_fetch_array($chkFD, SQLSRV_FETCH_ASSOC) : null;
    if ($chkFD) sqlsrv_free_stmt($chkFD);
    if (!$fdRow) { echo json_encode(['success'=>false,'message'=>'FD no encontrada en JB_Delta']); exit; }

    // 1. Buscamos el número máximo asignado actual Y la lista de filas que ya existen
    $stmtCheck = sqlsrv_query($connRW, "SELECT cant FROM Asignaciones_FD WHERE componente = ? ORDER BY cant ASC",
        [$FD_comp]);
    $filasOcupadas = [];
    $maxFila = 0;
    if ($stmtCheck) {
        while ($rowAsig = sqlsrv_fetch_array($stmtCheck, SQLSRV_FETCH_ASSOC)) {
            $num = (int)$rowAsig['cant'];
            $filasOcupadas[] = $num;
            if ($num > $maxFila) { $maxFila = $num; }
        }
        sqlsrv_free_stmt($stmtCheck);
    }

    // 2. Determinar qué número de fila le corresponde
    $cantMaxPermitida = (int)$fdRow['Cantidad_Ordenada'];
    $cantAsigActual = count($filasOcupadas);
    // Si ya se alcanzó o superó el límite físico de registros, bloqueamos directamente
    if ($cantAsigActual >= $cantMaxPermitida) {
        echo json_encode([
            'success' => false, 
            'message' => 'El FD ya alcanzó su cantidad máxima de asignaciones (' . $cantMaxPermitida . ')'
        ]);
        exit;
    }

    // 3. Buscamos si hay un "hueco" disponible
    $cantAsig = null;
    for ($i = 1; $i <= $cantMaxPermitida; $i++) {
        if (!in_array($i, $filasOcupadas)) {
            $cantAsig = $i;
            break; // Encontramos el primer número libre (ej: la fila 1 que se eliminó)
        }
    }
    // Si por alguna razón no se encontró un hueco intermedio, le damos el consecutivo más alto
    if ($cantAsig === null) { $cantAsig = $maxFila + 1; }
    // Opcional: Validación extrema de seguridad antes de insertar en tu SQL
    if ($cantAsig > $cantMaxPermitida) {
        echo json_encode([
            'success' => false, 
            'message' => 'Error lógico: La fila calculada (' . $cantAsig . ') excede el límite permitido.'
        ]);
        exit;
    }

    $parsed  = parsedCOMP($FD_comp);
    $modelo = trim($_POST['modelo_bomba'] ?? '');
    if (!$modelo) { echo json_encode(['success'=>false,'message'=>'Falta modelo_bomba']); exit; }
    
    $fecha_input = trim($_POST['fecha_plano'] ?? ''); // Aquí llega "2026-01-23"
    if (empty($fecha_input)) {
        $fecha = null;
    } else {
        $date_obj = DateTime::createFromFormat('Y-m-d', $fecha_input);
        if (!$date_obj) {
            $date_obj = DateTime::createFromFormat('d/m/Y', $fecha_input);
        }

        if ($date_obj) { // Validamos el rango de smalldatetime (1900 - 2079)
            $anio = (int)$date_obj->format('Y');
            if ($anio >= 1900 && $anio <= 2079) {
                $fecha = $date_obj->format('Ymd 00:00:00');         
            } else { $fecha = null; }
        } else { $fecha = $fecha_input; }
    }

    $ok = insertarAsignacion($connRW, [
        'cod_pza'        => !empty($codp) ? $codp : $fdRow['Numero'],
        'OT_FD'          => $of,
        'FD'             => $parsed['FD'],
        'componente'     => $FD_comp,
        'cant_fab'       => $cantAsig,
        'status_OT'      => $estadoPlano,
        'area_actual'    => '-',
        'plano_asignado' => $plano ?? '',
        'status_tarea_actual' => $fdRow['Status'],
        'fecha'          => $fecha,
        'modelo_bomba'   => $modelo,
        'fila_plano'     => $fila            
    ]);

    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'FD asignada correctamente.' : 'Error al insertar: '.print_r(sqlsrv_errors(),true),
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// ACCIÓN: SOLDADO
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'soldado') {
    $fdc        = ($_POST['componente'] ?? 0);
    $plano        = trim($_POST['plano']        ?? '');
    $fila_plano   = (int)($_POST['fila_plano']  ?? 0);
    $areaActual = trim($_POST['area_actual'] ?? '');

    $areasValidas = ['—','ARMADO','ACABADO','CORTE','MAQUINADO','PINTURA','SOLDADURA'];
    if (!$fdc || !$plano || !$fila_plano) { echo json_encode(['success'=>false,'message'=>'Datos incompletos']); exit; }
    if (!in_array($areaActual, $areasValidas)) { echo json_encode(['success'=>false,'message'=>'Área inválida']); exit; }

    $r = sqlsrv_query($connRW,
        "UPDATE Asignaciones_FD SET area_actual=? WHERE componente=? AND plano_asignado=? AND fila_plano=?",
        [$areaActual === '—' ? '—' : $areaActual, $fdc, $plano, $fila_plano]
    );
    echo json_encode(['success' => $r !== false]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// ACCIÓN: ELIMINAR
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'eliminar') {
    if (!Auth::tieneRol('Gerente', 'Supervisor', 'admin')) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Sin permisos']); exit;
    }

    $componente = trim($_POST['componente'] ?? '');
    $plano      = trim($_POST['plano']      ?? '');
    $fila_plano = (int)($_POST['fila_plano'] ?? 0);

    if (!$componente || !$plano || !$fila_plano) {
        echo json_encode(['success'=>false,'message'=>'Datos incompletos']); exit;
    }

    $r = sqlsrv_query($connRW,
        "DELETE FROM Asignaciones_FD WHERE componente=? AND plano_asignado=? AND fila_plano=?",
        [$componente, $plano, $fila_plano]
    );

    echo json_encode(['success' => $r !== false,
                      'message' => $r ? 'Asignación eliminada.' : print_r(sqlsrv_errors(), true)]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Acción desconocida']);