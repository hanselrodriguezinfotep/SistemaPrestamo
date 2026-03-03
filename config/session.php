<?php
// config/session.php — Sesiones seguras GestionPrestamo

function iniciarSesionSegura(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_name('GP_SESSION');
        session_start();
    }
}

function verificarSesion(): array {
    iniciarSesionSegura();
    if (empty($_SESSION['usuario_id'])) {
        header('Location: /GestionPrestamo/login.php?error=timeout');
        exit;
    }
    return sesionActual();
}

function sesionActual(): array {
    return [
        'usuario_id'   => $_SESSION['usuario_id']   ?? null,
        'persona_id'   => $_SESSION['persona_id']   ?? null,
        'id_empresa'   => $_SESSION['id_empresa']   ?? null,
        'nombre'       => $_SESSION['nombre']       ?? '',
        'tipo_persona' => $_SESSION['tipo_persona'] ?? '',
        'rol_id'       => $_SESSION['rol_id']       ?? null,
        'rol'          => $_SESSION['rol']          ?? '',
        'permisos'     => $_SESSION['permisos']     ?? [],
        'cambiar_pass' => $_SESSION['cambiar_pass'] ?? false,
        'foto_path'    => $_SESSION['foto_path']    ?? null,  // ← AGREGAR ESTO
    ];
}
function rolActual(): string   { return $_SESSION['rol'] ?? ''; }
function esSuperadmin(): bool  { return ($_SESSION['rol'] ?? '') === 'superadmin'; }
function esAdmin(): bool       { return ($_SESSION['rol'] ?? '') === 'admin'; }
function esAdminOSuperadmin(): bool {
    return in_array($_SESSION['rol'] ?? '', ['superadmin','admin'], true);
}
function esDirector(): bool {
    return in_array($_SESSION['rol'] ?? '', ['gerente','superadmin'], true);
}
function esAsesor(): bool     { return ($_SESSION['rol'] ?? '') === 'asesor'; }
function esCliente(): bool    { return ($_SESSION['rol'] ?? '') === 'cliente'; }

function tienePermiso(string $permiso): bool {
    return in_array($permiso, $_SESSION['permisos'] ?? [], true);
}

function cerrarSesion(): void {
    iniciarSesionSegura();
    if (!empty($_SESSION['usuario_id'])) {
        try {
            require_once __DIR__ . '/db.php';
            getDB()->prepare(
                'INSERT INTO audit_log (id_empresa,id_usuario,accion,ip,exitoso,fecha) VALUES(?,?,?,?,1,NOW())'
            )->execute([$_SESSION['id_empresa'] ?? null, $_SESSION['usuario_id'],'logout',$_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (\Throwable) {}
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    header('Location: /GestionPrestamo/login.php');
    exit;
}
