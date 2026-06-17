<?php
// Prevenir acceso directo a este archivo
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header('HTTP/1.0 403 Forbidden');
    exit('Acceso denegado');
}

// CONFIGURACIÓN DE BASE DE DATOS
define('DB_SERVER', "###.###.###.###"); 
define('DB_NAME', "Nombre");
define('DB_USER', "Usuario");
define('DB_PASS', "clave");


define('DB_NAME_USUARIOS', "Nombre2"); 
define('DB_USER_USUARIOS', "Usuario2"); 
define('DB_PASS_USUARIOS', "clave2"); 
define('DB_ENCRYPT', true);
define('DB_TIMEOUT', 10);

// CONEXIÓN PARA LECTURA (JBDELTA)
$connectionOptions = array(
    "Database" => DB_NAME,
    "Uid" => DB_USER,
    "PWD" => DB_PASS,
    "Encrypt" => DB_ENCRYPT,
    "TrustServerCertificate" => true,
    "LoginTimeout" => DB_TIMEOUT,
    "CharacterSet" => "UTF-8"
);

$conn = sqlsrv_connect(DB_SERVER, $connectionOptions);

if ($conn === false) {
    $ruta = __DIR__ . DIRECTORY_SEPARATOR . "error_sql.txt";
    echo "El archivo se guardará en: " . $ruta;
    
    file_put_contents($ruta, print_r(sqlsrv_errors(), true));
}



// CONEXIÓN PARA USUARIOS (DELTA_PROD_INFO)
$connectionOptions_usuarios = array(
    "Database" => DB_NAME_USUARIOS,  
    "Uid" => DB_USER_USUARIOS,
    "PWD" => DB_PASS_USUARIOS, 
    "Encrypt" => DB_ENCRYPT,
    "TrustServerCertificate" => true,
    "LoginTimeout" => DB_TIMEOUT,
    "CharacterSet" => "UTF-8"
);

$conn_usuarios = sqlsrv_connect(DB_SERVER, $connectionOptions_usuarios);//** */

if ($conn_usuarios === false) {
    die("Error de conexión a la base de datos de usuarios: " . print_r(sqlsrv_errors(), true));
}

// Función para obtener conexión de usuarios
function getConnection() {
    global $conn_usuarios;
    return $conn_usuarios;
}

// Función para obtener conexión de lectura
function getConnectionRead() {
    global $conn;
    return $conn;
}

?>