<?php
/**
 * public/logout.php
 */
require_once __DIR__ . '/../auth.php';

// Eliminamos la comprobación de POST y CSRF para permitir el enlace <a>
Auth::logout();

header('Location: login.php');
exit;