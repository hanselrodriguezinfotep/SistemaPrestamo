<?php
// login.php — GestionPrestamo | Acceso al sistema
require_once __DIR__ . '/config/session.php';
iniciarSesionSegura();

// Redirigir si ya está autenticado
if (!empty($_SESSION['usuario_id'])) {
    $dest = in_array($_SESSION['rol'] ?? '', ['cliente','consultor'])
        ? '/GestionPrestamo/portal.php'
        : '/GestionPrestamo/index.php';
    header("Location: {$dest}");
    exit;
}

$urlError = match ($_GET['error'] ?? '') {
    'timeout'  => '⏱️ Tu sesión expiró por inactividad.',
    'noaccess' => '🔒 No tienes permiso para acceder a esa sección.',
    default    => '',
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GestionPrestamo — Acceso</title>
    <link rel="stylesheet" href="css/login.css">
</head>
<body>

<div class="login-card">

    <!-- Logo -->
    <div class="login-logo">
        <span class="logo-icon">💰</span>
        <h1>GestionPrestamo</h1>
        <p>Sistema de Gestión de Préstamos</p>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" data-tab="login"   onclick="switchTab('login')">🔑 Ingresar</button>
        <button class="tab"        data-tab="recover" onclick="switchTab('recover')">🔒 Recuperar</button>
    </div>

    <!-- ═══════════════════════════════════
         PANEL: LOGIN
    ════════════════════════════════════ -->
    <div class="tab-panel active" id="panel-login">

        <div class="alert alert-error" id="loginError"
             style="display:<?= $urlError ? 'flex' : 'none' ?>">
            <?= htmlspecialchars($urlError, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <form id="loginForm" autocomplete="on" novalidate>
            <div class="form-group">
                <label for="username">👤 Usuario</label>
                <input type="text" id="username" name="username"
                       required autocomplete="username"
                       placeholder="Ej: profe.garcia">
            </div>
            <div class="form-group">
                <label for="password">🔐 Contraseña</label>
                <div class="field-wrap">
                    <input type="password" id="password" name="password"
                           required autocomplete="current-password"
                           placeholder="••••••••">
                    <button type="button" class="toggle-pw"
                            data-target="#password" title="Mostrar">👁️</button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary" id="btnLogin">
                Iniciar Sesión
            </button>
            <div class="remember-row" style="margin-top:12px; margin-bottom:0">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Recordar mi usuario</label>
            </div>
        </form>

    </div>

    <!-- ═══════════════════════════════════
         PANEL: RECUPERAR CONTRASEÑA
    ════════════════════════════════════ -->
    <div class="tab-panel" id="panel-recover">

        <div class="alert alert-error"   id="recoverError"   style="display:none"></div>
        <div class="alert alert-success" id="recoverSuccess" style="display:none"></div>

        <form id="recoverForm" novalidate>
            <p style="color:var(--gray-500);font-size:.84rem;margin-bottom:18px;line-height:1.5">
                Ingresa tu usuario y el correo con el que te registraste para recibir un código de recuperación.
            </p>
            <div class="form-group">
                <label>👤 Usuario *</label>
                <input type="text" name="username" required
                       autocomplete="off" placeholder="Tu usuario">
            </div>
            <div class="form-group">
                <label>📧 Correo electrónico *</label>
                <input type="email" name="email" required
                       placeholder="correo@ejemplo.com">
            </div>
            <button type="submit" class="btn btn-primary" id="btnRecover">
                Enviar código de verificación
            </button>
        </form>

        <!-- Paso 2: código + nueva contraseña -->
        <div id="recoverCodeSection" style="display:none">
            <div class="alert alert-info" style="display:flex;margin-bottom:16px">
                📧 Revisa tu correo e ingresa el código de 6 dígitos:
            </div>
            <div class="alert alert-error" id="resetError" style="display:none"></div>
            <form id="resetCodeForm" novalidate>
                <div class="form-group">
                    <label>🔢 Código de verificación *</label>
                    <input type="text" name="code" class="otp-input"
                           required maxlength="6" inputmode="numeric"
                           placeholder="000000">
                </div>
                <div class="divider">nueva contraseña</div>
                <div class="form-group">
                    <label>🔐 Nueva contraseña * <small>(mín. 8 caracteres)</small></label>
                    <div class="field-wrap">
                        <input type="password" name="new_password" required minlength="8"
                               autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="toggle-pw"
                                data-target="[name='new_password']" title="Mostrar">👁️</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>🔐 Confirmar contraseña *</label>
                    <div class="field-wrap">
                        <input type="password" name="new_password2" required minlength="8"
                               autocomplete="new-password" placeholder="••••••••">
                        <button type="button" class="toggle-pw"
                                data-target="[name='new_password2']" title="Mostrar">👁️</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" id="btnResetCode">
                    Cambiar Contraseña
                </button>
                <button type="button" class="btn-link" onclick="location.reload()">
                    ← Volver / Solicitar nuevo código
                </button>
            </form>
        </div>

    </div>

    <div class="login-footer">
        GestionPrestamo &copy; <?= date('Y') ?> — Sistema de Gestión de Préstamos
    </div>

</div><!-- /login-card -->

<script src="js/login.js"></script>
</body>
</html>
