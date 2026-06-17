<?php
/**
 * public/index.php
 * Punto de entrada principal — redirige según autenticación.
 */
require_once __DIR__ . '/../auth.php';

Auth::requerirLogin();
header('Location: planificacion.php');
exit;