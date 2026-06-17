<?php
/**
 * includes/Database.php
 * Capa de compatibilidad sobre config/config.php.
 * config.php expone: $conn (JB_Delta) y $conn_usuarios (DELTA_PROD_INFO)
 * Ambas son conexiones sqlsrv (SQL Server).
 */
require_once __DIR__ . '/../config/config.php';

class Database
{
    /** Conexión a JB_Delta (solo lectura) */
    public static function getJB()
    {
        global $conn;
        if ($conn === false || $conn === null) {
            throw new RuntimeException('Sin conexión a Nombre1 (SQL Server).');
        }
        return $conn;
    }

    /** Conexión a DELTA_PROD_INFO (usuarios, sesiones, asignaciones) */
    public static function get(string $source = 'prod_asign')
    {
        global $conn_usuarios;
        if ($conn_usuarios === false || $conn_usuarios === null) {
            throw new RuntimeException('Sin conexión a Nombre2 (SQL Server).');
        }
        return $conn_usuarios;
    }
}