<?php
/**
 * auth/Auth.php
 * Autenticación completa usando sqlsrv (SQL Server).
 */
require_once __DIR__ . '/includes/database.php';

class Auth
{
    private const COOKIE_NAME  = 'delta_sid';
    private const COOKIE_LIFE  = 28800;   // 8 horas
    private const BCRYPT_COST  = 12;
    private const MAX_INTENTOS = 5;
    private const BLOQUEO_SEG  = 900;     // 15 min

    private static ?array $currentUser = null;

    // ── Login ─────────────────────────────────────────────────────────────────

    public static function login(string $id_usu, string $clave, string $ip): bool|string
    {
        if (self::estaBloqueado($ip)) {
            return 'Demasiados intentos fallidos. Espere 15 minutos.';
        }

        $conn = Database::get();
        $stmt = sqlsrv_query($conn,
            'SELECT id_usu, nombre_usu, rol, area_asignada, clave
             FROM Usuarios WHERE nombre_usu = ?',
            [$id_usu]
        );
        $user = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($stmt) sqlsrv_free_stmt($stmt);

        if (!$user ) {//|| !$user['activo']
            self::registrarFallo($ip);
            return 'Usuario o contraseña incorrectos khjkhjk.';
        }

        if ($clave !== $user['clave']) {
            self::registrarFallo($ip);
            return ' Usuario o contraseña incorrectos 000.';
        }

        // Rehash si el costo cambió o si la clave aún es texto plano
        if (password_needs_rehash($user['clave'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            // Generamos el hash seguro a partir de la clave ingresada
            $nuevoHash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
            
            sqlsrv_query($conn,
                'UPDATE Usuarios SET clave = ? WHERE nombre_usu = ?',
                [$nuevoHash, $user['nombre_usu']] // <-- AQUÍ usamos el hash, NO la clave plana
            );
        }

        // Crear sesión
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + self::COOKIE_LIFE);
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        // --- BLOQUE NUEVO: Guardar en la base de datos ---
        $expires = new DateTime();
        $expires->modify('+' . self::COOKIE_LIFE . ' seconds');

        // 2. Prepara los parámetros enviando el objeto $expires directamente
        $sqlSesion = "INSERT INTO Sesiones (token, id_usu, ip_addr, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)";
        $paramsSesion = [
            $token, 
            $user['id_usu'], 
            $ip, 
            $ua, 
            $expires // <--- El driver sqlsrv convertirá el objeto DateTime correctamente
        ];
        
        $stmtSesion = sqlsrv_query($conn, $sqlSesion, $paramsSesion);

        if ($stmtSesion === false) {
            die(print_r(sqlsrv_errors(), true));
            //return 'Error al registrar la sesión en el servidor.';
        }
        // ------------------------------------------------

        self::setCookie($token);
        self::limpiarFallos($ip);
        self::$currentUser = $user;
        return true;
    }

    // ── Logout ────────────────────────────────────────────────────────────────
    public static function logout(): void
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        
        if ($token) {
            $conn = Database::get();
            sqlsrv_query($conn, "DELETE FROM Sesiones WHERE token = ?", [$token]);
        }

        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => self::isHttps(),
        ]);
        self::$currentUser = null;
    }

    // ── Verificación de sesión ────────────────────────────────────────────────

    public static function usuario(): ?array
    {
        // ── MODO DE PRUEBA (DESARROLLO) ───────────────────────────────────
        // Retornamos un usuario ficticio para saltar la verificación de DB
        /*return [
            'id_usu'         => 'PROB-01',
            'nombre_usu'     => 'Administrador de Pruebas',
            'rol'            => 'super', // Puedes cambiar a 'editor' u 'observador'
            'area_asignada'  => 'Producción',
            'activo'         => 1
        ];*/

        if (self::$currentUser !== null) return self::$currentUser;

        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!$token) return null;

        try { $conn = Database::get(); }
        catch (RuntimeException) { return null; }

        // Buscamos el token en Sesiones y traemos los datos del Usuario vinculado
        $sql = "SELECT u.id_usu, u.nombre_usu, u.rol, u.area_asignada
                FROM Sesiones s
                INNER JOIN Usuarios u ON s.id_usu = u.id_usu
                WHERE s.token = ? AND s.expires_at > GETDATE()";
        
        $stmt = sqlsrv_query($conn, $sql, [$token]);
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($stmt) sqlsrv_free_stmt($stmt);

        if (!$row) return null;

        self::$currentUser = $row;
        return $row;
    }

    public static function requerirLogin(): array
    {
        $user = self::usuario();
        if (!$user) { header('Location: login.php'); exit; }
        return $user;
    }

    public static function requerirRol(string ...$roles): array
    {
        $user = self::requerirLogin();
        if (!in_array($user['rol'], $roles, true)) {
            http_response_code(403);
            echo '<h2>Acceso denegado</h2><p>No tienes permisos para esta sección.</p>';
            exit;
        }
        return $user;
    }

    public static function tieneRol(string ...$roles): bool
    {
        $user = self::usuario();
        return $user && in_array($user['rol'], $roles, true);
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validarCsrf(): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        $enviado  = $_POST['csrf_token'] ?? '';
        $esperado = $_SESSION['csrf_token'] ?? '';
        return $esperado && hash_equals($esperado, $enviado);
    }

    public static function exigirCsrf(): void
    {
        if (!self::validarCsrf()) {
            http_response_code(403);
            die('Token CSRF inválido. Recarga la página e intenta de nuevo.');
        }
    }

    public static function hashClave(string $clave): string
    {
        return password_hash($clave, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    private static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires'  => time() + self::COOKIE_LIFE,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => self::isHttps(),
        ]);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    }

    private static function registrarFallo(string $ip): void
    {
        if (function_exists('apcu_inc')) {
            $key = 'login_fail_' . md5($ip);
            apcu_add($key, 0, self::BLOQUEO_SEG);
            apcu_inc($key);
        }
    }

    private static function estaBloqueado(string $ip): bool
    {
        if (function_exists('apcu_fetch')) {
            $intentos = apcu_fetch('login_fail_' . md5($ip));
            return $intentos !== false && $intentos >= self::MAX_INTENTOS;
        }
        return false;
    }

    private static function limpiarFallos(string $ip): void
    {
        if (function_exists('apcu_delete')) {
            apcu_delete('login_fail_' . md5($ip));
        }
    }

    public static function simplificarErrorSql($errors): string 
    {
        if (!is_array($errors) || empty($errors)) {
            return "Ocurrió un error inesperado en el sistema.";
        }

        foreach ($errors as $err) {
            $code = $err['code'] ?? 0;
            $msgOriginal = $err['message'] ?? '';

            // Mapeo de errores comunes de SQL Server
            switch ($code) {
                case 2628:
                case 8152:
                    // Error de truncado (exceso de caracteres)
                    if (preg_match('/columna "([^"]+)"/i', $msgOriginal, $matches)) {
                        return "El texto ingresado en el campo '" . ucfirst($matches[1]) . "' excede el límite de caracteres permitido.";
                    }
                    return "Uno de los campos excede el límite de caracteres permitido.";

                case 2627:
                case 2601:
                    return "No se pudo guardar: Ya existe un registro con estos mismos datos clave (Duplicado).";

                case 547:
                    return "Error de consistencia: Se intentó asociar un dato (como un Usuario u OF) que no existe en el sistema.";
                    
                case 241:
                case 242:
                    return "El formato de alguna de las fechas ingresadas no es válido para el sistema.";
            }
        }

        // Si no coincide con ninguno de los códigos conocidos, extraemos el mensaje limpio del Driver
        // Removiendo los molestos prefacios de [Microsoft][ODBC...]
        $errorLimpio = preg_replace('/\[[^\]]+\]/', '', $errors[0]['message'] ?? '');
        return "Error en la base de datos: " . trim($errorLimpio);
    }
}