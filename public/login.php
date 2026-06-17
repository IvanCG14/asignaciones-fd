<?php
/**
 * public/login.php
 * Página de ingreso al sistema.
 */
require_once __DIR__ . '/../auth.php';

$error = '';

// Si ya está autenticado, redirigir
if (Auth::usuario()) {
    header('Location: index.php');
    exit;
}

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $id_usu = trim($_POST['id_usu'] ?? '');
    $clave  = $_POST['clave'] ?? '';
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $resultado = Auth::login($id_usu, $clave, $ip);
    if ($resultado === true) {
      // ── AQUÍ VA LA SOLUCIÓN 2: RECOLECTOR DE BASURA ──
      // Recuperamos la conexión activa a la base de datos
      $conn_rw = Database::get(); 
      if ($conn_rw) {
          // Eliminamos de la tabla de sesiones todos los tokens cuya fecha de expiración sea menor a la hora actual
          $sqlGarbageCollector = "DELETE FROM Sesiones WHERE expires_at < GETDATE()";
          sqlsrv_query($conn_rw, $sqlGarbageCollector);
      }
      // ──────────────────────────────────────────────────

      header('Location: index.php');
      exit;
    }
    $error = $resultado;
}

$csrf = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delta FD — Ingreso</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    /* ── Variables ── */
    :root {
    --bg:          #2a2d34;
    --surface:     #1a1c20;
    --border:      #3f434c;
    --accent:      #006cb5;
    --accent-dk:   #004c8c;
    --text:        #f1f5f9;
    --text-dim:    #94a3b8;
    --danger:      #e05252;
    --radius:      4px;
  }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      background: var(--bg);
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: 'IBM Plex Sans', sans-serif;
      color: var(--text);
      position: relative;
      overflow: hidden;
    }

    /* Fondo con patrón de rejilla industrial */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(240,165,0,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(240,165,0,.04) 1px, transparent 1px);
      background-size: 40px 40px;
      pointer-events: none;
    }

    /* Línea diagonal decorativa */
    body::after {
      content: '';
      position: fixed;
      top: -20%;
      right: -10%;
      width: 60vw;
      height: 140vh;
      background: linear-gradient(135deg, transparent 40%, rgba(0,172,240,.03) 40%, rgba(0, 172, 240, 0.03) 60%, transparent 60%);
      pointer-events: none;
    }

    /* ── Tarjeta de login ── */
    .login-wrap {
      position: relative;
      z-index: 1;
      width: 100%;
      max-width: 420px;
      padding: 0 1.5rem;
      animation: fadeUp .5s ease both;
    }

    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    .login-header {
      margin-bottom: 2.5rem;
    }

    .login-header .label {
      font-family: 'IBM Plex Mono', monospace;
      font-size: .7rem;
      letter-spacing: .18em;
      color: var(--accent);
      text-transform: uppercase;
      margin-bottom: .5rem;
    }

    .login-header h1 {
      font-family: 'Bebas Neue', sans-serif;
      font-size: 3.2rem;
      letter-spacing: .06em;
      color: #fff;
      line-height: 1;
    }

    .login-header h1 span {
      color: var(--accent);
    }

    .login-header p {
      margin-top: .6rem;
      font-size: .82rem;
      color: var(--text-dim);
      font-family: 'IBM Plex Mono', monospace;
    }

    /* Línea divisora con acento */
    .divider {
      height: 2px;
      background: linear-gradient(90deg, var(--accent) 30%, transparent 100%);
      margin-bottom: 2rem;
    }

    .login-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-top: 2px solid var(--accent);
      padding: 2rem;
    }

    /* ── Formulario ── */
    .form-group {
      margin-bottom: 1.25rem;
    }

    label {
      display: block;
      font-family: 'IBM Plex Mono', monospace;
      font-size: .68rem;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--text-dim);
      margin-bottom: .45rem;
    }

    input[type="text"],
    input[type="password"] {
      width: 100%;
      padding: .65rem .85rem;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      color: var(--text);
      font-family: 'IBM Plex Mono', monospace;
      font-size: .9rem;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }

    input[type="text"]:focus,
    input[type="password"]:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(240,165,0,.12);
    }

    /* Mostrar/ocultar contraseña */
    .pass-wrap {
      position: relative;
    }
    .pass-wrap input { padding-right: 2.8rem; }
    .toggle-pass {
      position: absolute;
      right: .75rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: var(--text-dim);
      font-size: .85rem;
      padding: 0;
      line-height: 1;
      transition: color .2s;
    }
    .toggle-pass:hover { color: var(--accent); }

    /* ── Error ── */
    .alert-error {
      background: rgba(224,82,82,.1);
      border: 1px solid rgba(224,82,82,.4);
      border-left: 3px solid var(--danger);
      color: #f08080;
      font-family: 'IBM Plex Mono', monospace;
      font-size: .78rem;
      padding: .65rem .85rem;
      margin-bottom: 1.25rem;
      border-radius: var(--radius);
    }

    /* ── Botón ── */
    .btn-login {
      width: 100%;
      padding: .8rem;
      background: var(--accent);
      border: none;
      border-radius: var(--radius);
      color: #0e0f11;
      font-family: 'Bebas Neue', sans-serif;
      font-size: 1.15rem;
      letter-spacing: .12em;
      cursor: pointer;
      transition: background .2s, transform .1s;
      margin-top: .5rem;
    }
    .btn-login:hover  { background: #eac7f1; }
    .btn-login:active { transform: scale(.98); }

    /* ── Footer info ── */
    .login-footer {
      margin-top: 1.5rem;
      text-align: center;
      font-family: 'IBM Plex Mono', monospace;
      font-size: .65rem;
      color: var(--text-dim);
      letter-spacing: .08em;
    }
  </style>
</head>
<body>

<div class="login-wrap">
  <div class="login-header">
    <div class="label">Sistema de Gestión</div>
    <h1>DELTA <span>FD</span></h1>
    <p>Control de Asignaciones de Producción</p>
  </div>

  <div class="divider"></div>

  <div class="login-card">
    <?php if ($error): ?>
      <div class="alert-error">⚠ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

      <div class="form-group">
        <label for="id_usu">ID de Usuario</label>
        <input
          type="text"
          id="id_usu"
          name="id_usu"
          maxlength="10"
          required
          autofocus
          value="<?= htmlspecialchars($_POST['id_usu'] ?? '') ?>"
          placeholder="Ej: USR001"
        >
      </div>

      <div class="form-group">
        <label for="clave">Contraseña</label>
        <div class="pass-wrap">
          <input
            type="password"
            id="clave"
            name="clave"
            maxlength="100"
            required
            placeholder="••••••••"
          >
          <button type="button" class="toggle-pass" onclick="togglePass()" title="Mostrar/ocultar">
            <span id="eye-icon">👁</span>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login">INGRESAR</button>
    </form>
  </div>

  <div class="login-footer">
    DELTA PRODUCCIÓN · ACCESO RESTRINGIDO · <?= date('Y') ?>
  </div>
</div>

<script>
function togglePass() {
  const inp = document.getElementById('clave');
  const ico = document.getElementById('eye-icon');
  if (inp.type === 'password') {
    inp.type = 'text';
    ico.textContent = '🙈';
  } else {
    inp.type = 'password';
    ico.textContent = '👁';
  }
}
</script>
</body>
</html>