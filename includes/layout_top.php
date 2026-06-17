<?php
/**
 * includes/layout_top.php
 * Encabezado y navegación principal con paleta Delta Delfini & Cía., S.A.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Delta FD — <?= htmlspecialchars($pageTitle ?? 'Sistema') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    /* ══════════════════════════════════════════════════
       VARIABLES GLOBALES (Gama Delta Delfini)
    ══════════════════════════════════════════════════ */
    :root {
      --bg:          #2a2d34; /* Gris oscuro base solicitado */
      --surface:     #16181c; /* Superficie profunda */
      --surface2:    #1d1f25; /* Superficie secundaria */
      --border:      #3f434c; /* Borde sutil */
      --accent:      #006cb5; /* Azul corporativo (image_8a4103.png) */
      --accent-dk:   #004c8c; /* Azul oscuro para estados hover */
      --text:        #f1f5f9; /* Texto claro (sustituye al blanco puro) */
      --text-dim:    #94a3b8; /* Texto atenuado */
      --danger:      #e05252;
      --success:     #4caf7d;
      --warning:     #f0a500;
      --nav-h:       56px;
      --radius:      4px;
      --mono:        'IBM Plex Mono', monospace;
      --sans:        'IBM Plex Sans', sans-serif;
      --display:     'Bebas Neue', sans-serif;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    html, body {
      height: 100%;
      background: var(--bg);
      color: var(--text);
      font-family: var(--sans);
      font-size: 14px;
      line-height: 1.5;
    }

    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* ══════════════════════════════════════════════════
       NAVBAR
    ══════════════════════════════════════════════════ */
    .navbar {
      position: fixed;
      top: 0; left: 0; right: 0;
      height: var(--nav-h);
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      border-top: 3px solid var(--accent);
      display: flex;
      align-items: center;
      padding: 0 1.5rem;
      z-index: 100;
      gap: 2rem;
    }

    .navbar-brand {
      font-family: var(--display);
      font-size: 1.5rem;
      letter-spacing: .1em;
      color: #fff;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .navbar-brand span { color: var(--accent); }

    .navbar-nav {
      display: flex;
      align-items: center;
      gap: .25rem;
      flex: 1;
      list-style: none;
    }

    .navbar-nav a {
      display: flex;
      align-items: center;
      gap: .4rem;
      padding: .4rem .85rem;
      font-family: var(--mono);
      font-size: .72rem;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--text-dim);
      border-radius: var(--radius);
      transition: color .2s, background .2s;
      text-decoration: none;
      white-space: nowrap;
    }
    .navbar-nav a:hover {
      color: var(--text);
      background: rgba(255,255,255,.05);
    }
    .navbar-nav a.active {
      color: var(--accent);
      background: rgba(0, 108, 181, 0.1); /* Ajustado al azul */
      border: 1px solid rgba(0, 108, 181, 0.2); /* Ajustado al azul */
    }

    .nav-icon { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; }

    .navbar-user {
      display: flex;
      align-items: center;
      gap: .85rem;
      margin-left: auto;
      flex-shrink: 0;
    }

    .user-badge {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-family: var(--mono);
      font-size: .7rem;
    }

    .role-tag {
      padding: .15rem .5rem;
      border-radius: 2px;
      font-size: .62rem;
      letter-spacing: .1em;
      text-transform: uppercase;
      font-weight: 500;
    }
    /* Estilos de roles ajustados a la nueva gama */
    .role-tag.super      { background: rgba(0, 108, 181, .2); color: var(--accent); border: 1px solid rgba(0, 108, 181, .3); }
    .role-tag.editor     { background: rgba(76, 175, 125, .15); color: var(--success); border: 1px solid rgba(76, 175, 125, .25); }
    .role-tag.observador { background: rgba(148, 163, 184, .15); color: var(--text-dim); border: 1px solid rgba(148, 163, 184, .25); }

    .btn-logout {
      padding: .35rem .75rem;
      background: transparent;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      color: var(--text-dim);
      font-family: var(--mono);
      font-size: .68rem;
      letter-spacing: .08em;
      cursor: pointer;
      transition: border-color .2s, color .2s;
    }
    .btn-logout:hover {
      border-color: var(--danger);
      color: var(--danger);
    }

    /* ══════════════════════════════════════════════════
       CONTENIDO PRINCIPAL
    ══════════════════════════════════════════════════ */
    .main-content {
      margin-top: var(--nav-h);
      padding: 1.75rem 2rem;
      min-height: calc(100vh - var(--nav-h));
    }

    .page-header {
      display: flex;
      align-items: flex-end;
      gap: 1rem;
      margin-bottom: 1.5rem;
      padding-bottom: .75rem;
      border-bottom: 1px solid var(--border);
    }
    .page-header h2 {
      font-family: var(--display);
      font-size: 1.8rem;
      letter-spacing: .06em;
      color: #fff;
      line-height: 1;
    }
    .page-header .page-sub {
      font-family: var(--mono);
      font-size: .7rem;
      color: var(--text-dim);
      letter-spacing: .1em;
      text-transform: uppercase;
      padding-bottom: .15rem;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.25rem;
    }
    .card-title {
      font-family: var(--mono);
      font-size: .7rem;
      letter-spacing: .12em;
      text-transform: uppercase;
      color: var(--text-dim);
      margin-bottom: 1rem;
    }

    .badge {
      display: inline-block;
      padding: .15rem .55rem;
      border-radius: 2px;
      font-family: var(--mono);
      font-size: .65rem;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .badge-success  { background: rgba(76,175,125,.15); color: var(--success); }
    .badge-warning  { background: rgba(240,165,0,.15);  color: var(--warning); }
    .badge-danger   { background: rgba(224,82,82,.15);  color: var(--danger); }
    .badge-neutral  { background: rgba(107,114,128,.15); color: var(--text-dim); }

    .form-control {
      width: 100%;
      padding: .55rem .75rem;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      color: var(--text);
      font-family: var(--mono);
      font-size: .85rem;
      outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .form-control:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(0, 108, 181, 0.1);
    }
    .form-control:read-only {
      color: var(--text-dim);
      cursor: not-allowed;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .5rem 1rem;
      border-radius: var(--radius);
      font-family: var(--mono);
      font-size: .75rem;
      letter-spacing: .08em;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all .2s;
      text-decoration: none;
    }
    .btn-primary   { background: var(--accent); color: #fff; border-color: var(--accent); }
    .btn-primary:hover { background: var(--accent-dk); }
    .btn-secondary { background: transparent; color: var(--text); border-color: var(--border); }
    .btn-secondary:hover { border-color: var(--text-dim); }
    .btn-danger    { background: transparent; color: var(--danger); border-color: var(--danger); }
    .btn-danger:hover { background: rgba(224,82,82,.1); }
    .btn-sm { padding: .3rem .65rem; font-size: .68rem; }

    .table-wrap { overflow-x: auto; }
    table.data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
    }
    table.data-table th {
      background: var(--surface2);
      color: var(--text-dim);
      font-family: var(--mono);
      font-size: .65rem;
      letter-spacing: .1em;
      text-transform: uppercase;
      padding: .6rem .85rem;
      text-align: left;
      border-bottom: 2px solid var(--border);
      white-space: nowrap;
    }
    table.data-table td {
      padding: .55rem .85rem;
      border-bottom: 1px solid var(--border);
      vertical-align: middle;
    }
    table.data-table tr:hover td { background: rgba(255,255,255,.02); }

    .alert {
      padding: .7rem 1rem;
      border-radius: var(--radius);
      font-size: .82rem;
      margin-bottom: 1rem;
    }
    .alert-error   { background: rgba(224,82,82,.1); border-left: 3px solid var(--danger); color: #f08080; }
    .alert-success { background: rgba(76,175,125,.1); border-left: 3px solid var(--success); color: #7dd4a8; }
    .alert-warning { background: rgba(240,165,0,.1);  border-left: 3px solid var(--warning); color: var(--warning); }

    .spinner {
      display: inline-block;
      width: 16px; height: 16px;
      border: 2px solid var(--border);
      border-top-color: var(--accent);
      border-radius: 50%;
      animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Fondo con rejilla sutil ajustada al azul corporativo */
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background-image:
        linear-gradient(rgba(0, 108, 181, .025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 108, 181, .025) 1px, transparent 1px);
      background-size: 40px 40px;
      pointer-events: none;
      z-index: 0;
    }
    .main-content { position: relative; z-index: 1; }
  </style>
</head>
<body>

<nav class="navbar">
  <div class="navbar-brand">DELTA <span>FD</span></div>

  <ul class="navbar-nav">
    <li>
      <a href="planificacion.php" class="<?= ($navActive ?? '') === 'planificacion' ? 'active' : '' ?>">
        <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Planificación
      </a>
    </li>

    <li>
      <a href="tabla_asignacion.php" class="<?= ($navActive ?? '') === 'tabla' ? 'active' : '' ?>">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
        Tabla Asignación
      </a>
    </li>

    <li>
      <a href="historial_cerrados.php" class="<?= ($navActive ?? '') === 'historial' ? 'active' : '' ?>">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg>
        Historial Cerrados
      </a>
    </li>
  </ul>

  <div class="navbar-user">
    <div class="user-badge">
      <span style="color:var(--text-dim)"><?= htmlspecialchars($user['nombre_usu'] ?? '—') ?></span>
      <span class="role-tag <?= htmlspecialchars($user['rol'] ?? '') ?>">
        <?= htmlspecialchars($user['rol'] ?? '') ?>
      </span>
    </div>
    <form method="POST" action="logout.php" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Auth::csrfToken()) ?>">
      <button type="submit" class="btn-logout">Salir</button>
    </form>
  </div>
</nav>

<main class="main-content">