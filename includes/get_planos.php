<?php
/**
 * includes/planificacion_helpers.php
 * Funciones reutilizables para obtener datos de planificación.
 */

/**
 * Obtiene los planos activos con sus datos cruzados (JB_Delta + Info_Bombas + Historial_Fechas).
 * @return array Lista de planos con todos sus campos resueltos.
 */

class Planos{

    public static function getPlanos($conn_jb, $conn_rw, $excPlanos = "AND j.Estado NOT LIKE 'Closed'"): array
    {
        $items = [];
        try {
            // Consulta a JB_Delta
            $sqlJB = "
            WITH CodigosBase AS (
                SELECT  
                    c.Nombre AS cliente,
                    c.Cliente AS cod_cliente,
                    j.Codigo AS of_num,
                    j.Numero AS plano,
                    j.Description AS description,
                    j.Comentario AS comment,
                    j.Cantidad_Ordenada AS cant,
                    j.Estado AS [status],
                    d.Fecha AS fecha_ent
                FROM dbo.Cliente c
                INNER JOIN dbo.Codigo j ON c.Cleinte = j.Cliente
                LEFT JOIN dbo.Delivery d ON j.Codigo = d.Codigo
                WHERE j.Assembly_Level = 0 
                AND d.Fecha IS NOT NULL
                $excPlanos
                AND (j.Codigo LIKE 'OF%' OR j.Codigo LIKE 'RP%' OR j.Codigo LIKE 'GR%')
            ),
            JerarquiaHijos AS (
                SELECT b.Codigo_Padre AS JobBase, b.Codigo_Componente
                FROM Bill_Of_Jobs b 
                INNER JOIN JobsBase jb ON b.Codigo_Padre = jb.of_num
                WHERE b.Relationship_Type = 'Component'
                UNION ALL
                SELECT jh.JobBase, b.Codigo_Componente
                FROM Bill_Of_Jobs b
                INNER JOIN JerarquiaHijos jh ON b.Codigo_Padre = jh.Codigo_Componente
                WHERE b.Relationship_Type = 'Component'
            ),
            DetalleHoras AS (
                SELECT jb.of_num AS CodigoBase, ISNULL(j.Est_Total_Hrs, 0) AS Est, ISNULL(j.Act_Total_Hrs, 0) AS Act
                FROM CodigosBase jb
                INNER JOIN dbo.Codigo j ON j.Codigo = jb.of_num
                UNION ALL
                SELECT jh.CodigoBase, ISNULL(j.Est_Total_Hrs, 0), ISNULL(j.Act_Total_Hrs, 0)
                FROM JerarquiaHijos jh
                INNER JOIN dbo.Codigo j ON j.Codigo = jh.Codigo_Componente
            ),
            ResumenHoras AS (
                SELECT CodigoBase, 
                    SUM(Act) AS TotalAct, 
                    SUM(Est) AS TotalEst,
                    CASE WHEN SUM(Est) > 0 THEN (SUM(Act) / SUM(Est)) * 100 ELSE 0 END AS Avance
                FROM DetalleHoras
                GROUP BY CodigoBase
            )
            SELECT jb.*, rh.Avance FROM CodigosBase jb
            LEFT JOIN ResumenHoras rh ON jb.of_num = rh.CodigoBase
            ORDER BY jb.fecha_ent ASC OPTION (MAXRECURSION 0);";

            $stmt = sqlsrv_query($conn_jb, $sqlJB);
            
            // Cargar Mapa de Modificaciones locales
            $modsMap = [];
            $sm = sqlsrv_query($conn_rw, "SELECT * FROM Info_Bombas");
            while($r = $sm ? sqlsrv_fetch_array($sm, SQLSRV_FETCH_ASSOC) : null) { 
                // Creamos una llave única combinando plano y la orden de fabricación
                $key = $r['plano'] . '|' . $r['ORF']; 
                $modsMap[$key] = $r; 
            }
            // Cargar Mapa de Historial de Fechas
            $histMap = [];
            $sh = sqlsrv_query($conn_rw, "
                SELECT 
                    plano, 
                    of_plano,
                    STRING_AGG(CONVERT(varchar, fecha_anterior, 23), ',') WITHIN GROUP (ORDER BY cambiado_en) AS anteriores,
                    MAX(ultimo_valor) AS fecha_vigente
                FROM (
                    SELECT 
                        plano, 
                        of_plano, 
                        fecha_anterior, 
                        cambiado_en,
                        -- FIRST_VALUE nos trae la fecha_nueva del registro con el cambiado_en más alto para ese grupo
                        FIRST_VALUE(fecha_nueva) OVER (
                            PARTITION BY plano, of_plano 
                            ORDER BY cambiado_en DESC
                        ) AS ultimo_valor
                    FROM Historial_Fechas
                ) AS SubConsulta
                GROUP BY plano, of_plano
            ");

            while($r = $sh ? sqlsrv_fetch_array($sh, SQLSRV_FETCH_ASSOC) : null) {
                $key = $r['plano'] . '|' . $r['of_plano'];
                $histMap[$key] = [
                    'anteriores'    => $r['anteriores'],
                    'fecha_vigente' => ($r['fecha_vigente'] instanceof DateTime) 
                                        ? $r['fecha_vigente']->format('Y-m-d') 
                                        : $r['fecha_vigente'],
                ];
            }

            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $p = $row['plano'];
                // Generamos la llave usando el plano ($p) y el número de OF ($row['of_num'])
                $modKey = $p . '|' . $row['of_num'];
                $m = $modsMap[$modKey] ?? null;
                $f_jb = $row['fecha_ent'] instanceof DateTime ? $row['fecha_ent']->format('Y-m-d') : null;

                $modelo_detectado = '-';

                if (!empty($row['description']) && empty($m['modelo'])) {
                    // 1. Dividimos la descripción en palabras usando el espacio como separador
                    $descripcion = $row['description'] ?? '';
                    
                    //Eliminar caracteres especiales
                    $descripcion = str_replace(["\xc2\xa0", 'Â'], [' ', ''], $descripcion);
                    $descripcion = str_replace(['(', ')', '[', ']', '{', '}'], ' ', $descripcion);
                    $descripcion = preg_replace('/\s+/', ' ', $descripcion);

                    $palabras = explode(' ', $descripcion);
                    $i = 0;
                
                    foreach ($palabras as $palabra) {
                        // Limpiamos caracteres extraños (comas, paréntesis) que puedan estar pegados
                        $p_limpia = trim($palabra, " ,()[]{}.:;");
                        $longitud = strlen($p_limpia);

                        // 2. Aplicamos tus dos condiciones:
                        // Condición A: Tiene un guion "-"
                        // Condición B: Empieza con "BA" y tiene más de 4 caracteres
                        if (strpos($p_limpia, '-') !== false || 
                        (strncasecmp($p_limpia, 'BA', 2) === 0 && $longitud > 4)) {
                            
                            if (str_ends_with($p_limpia, '-')){
                                $modelo_detectado = $p_limpia . trim($palabras[$i+1] ?? '', " ,()[]{}.:;\n");
                                break;
                            }
                            $modelo_detectado = trim($p_limpia ?? '', " ,()[]{}.:;\n");
                            break; // Detenemos la búsqueda al encontrar la primera coincidencia
                        }

                        $i += 1;
                    }
                }

                $estacion_detectada = '—';
                $comm = $row['comment'] ?? '';

                if (!empty($comm) && empty($m['estacion'])) {
                    // Explicación de la Regex:
                    // ^\s* -> Empieza desde el inicio del texto
                    // (.*?)            -> CAPTURA TODO (será nuestra estación) de forma "no codiciosa"
                    // \s* -> Espacios opcionales
                    // \d+\s*-\s* -> Busca un número seguido de un guion (ej: "3 -")
                    // \s*BOMBA         -> Hasta llegar a la palabra BOMBA (insensible a mayúsculas)
                    
                    if (preg_match('/^\s*(.*?)\s*-\s*\d+\s*BOMBA/i', $comm, $matches)) {
                        // $matches[1] contiene lo que está antes del número y el guion
                        $estacion_detectada = trim($matches[1], " ,-");
                    } else {
                        // Opción B: Si no hay número y guion, pero sí dice BOMBA, agarra lo anterior
                        if (preg_match('/^\s*(.*?)\s*BOMBA/i', $comm, $matches)) {
                            $estacion_detectada = trim($matches[1], " ,-");
                        }
                    }
                }

                $histData = $histMap[$modKey] ?? null;

                $fecha_mostrar = $f_jb;
                if (!empty($histData['fecha_vigente'])) {
                    $fecha_mostrar = $histData['fecha_vigente'];
                } elseif (isset($m['fecha_entrega'])) {
                    $fecha_mostrar = ($m['fecha_entrega'] instanceof DateTime)
                        ? $m['fecha_entrega']->format('Y-m-d')
                        : $m['fecha_entrega'];
                }

                $items[$modKey] = [
                    'plano'          => $p ?? '',
                    'cliente'        => $row['cliente'],
                    'estacion'       => (!empty($m['estacion']) && $m['estacion'] !== '—') ? $m['estacion'] : $estacion_detectada, // Muestra modificado o vacío
                    'modelo'         => (!empty($m['modelo']) && $m['modelo'] !== '—') ? $m['modelo'] : $modelo_detectado,   // Muestra modificado o vacío
                    'of'             => $row['of_num'],
                    'cod_cliente'    => $row['cod_cliente'],
                    'status'         => $row['status'],
                    'cant'           => (int)$row['cant'],
                    'fecha_jb'       => $f_jb,
                    'fecha_final'    => $fecha_mostrar,
                    'fecha_hist'     => $histData['anteriores'] ?? null,
                    'desc'           => ((!empty($m['desc_usu']) && $m['desc_usu'] !== '—') ? $m['desc_usu'] : $row['description']) ?? '—',
                    'avance'         => (round($row['Avance'], 1) >= 100.0) ? 100.0 : round($row['Avance'], 1)
                ];
            }
        } catch (Exception $e) { /* Manejo de error */ }

        uasort($items, function($a, $b) {
            // Manejar posibles casos donde la fecha sea nula o vacía enviándolas al final
            $fechaA = !empty($a['fecha_final']) ? $a['fecha_final'] : '9999-12-31';
            $fechaB = !empty($b['fecha_final']) ? $b['fecha_final'] : '9999-12-31';
            
            return $fechaA <=> $fechaB;
        });

        return $items;
    }
}