<?php
// php/helpers.php — Utilidades compartidas GestionPrestamo
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';

function apiHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

function jsonResponse(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $mensaje, int $code = 400): never {
    jsonResponse(['error' => $mensaje], $code);
}

function verificarSesionAPI(): array {
    iniciarSesionSegura();
    if (empty($_SESSION['usuario_id'])) jsonError('No autorizado', 401);
    return sesionActual();
}

function isDebugMode(): bool {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db   = getDB();
        $cols = array_column(
            $db->query("SHOW COLUMNS FROM configuracion_empresa LIKE 'debug_errors'")->fetchAll(),
            'Field'
        );
        if (empty($cols)) { $cached = false; return false; }
        $stmt = $db->prepare("SELECT debug_errors FROM configuracion_empresa WHERE id_empresa <=> ? LIMIT 1");
        $stmt->execute([$_SESSION['id_empresa'] ?? null]);
        $row  = $stmt->fetch();
        $cached = (bool)($row['debug_errors'] ?? false);
    } catch (\Throwable) { $cached = false; }
    return $cached;
}

function friendlyDbError(\Throwable $e): string {
    if (isDebugMode()) {
        return '[DEBUG] '.get_class($e).': '.$e->getMessage()
            .' ('.basename($e->getFile()).':'.$e->getLine().')';
    }
    $msg = $e->getMessage();
    if (preg_match("/Duplicate entry '(.+?)' for key '(.+?)'/", $msg, $m)) {
        $v = $m[1]; $c = strtolower($m[2]);
        if (str_contains($c,'uq_cedula'))   return "La cédula «{$v}» ya está registrada.";
        if (str_contains($c,'uq_username')) return "El usuario «{$v}» ya está en uso.";
        return "Ya existe un registro con el valor «{$v}».";
    }
    if (str_contains($msg,'foreign key constraint')) return 'El registro está relacionado con otros datos.';
    if (str_contains($msg,"doesn't exist"))          return 'Tabla no encontrada — verifica las migraciones.';
    if (str_contains($msg,'Data too long'))          return 'Un campo excede el límite de caracteres.';
    return $msg ?: 'Error al guardar. Verifica los datos e intenta de nuevo.';
}

function inputJSON(): array {
    $raw  = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitizeStr(mixed $v, int $maxLen = 255): string {
    return mb_substr(trim(strip_tags((string)$v)), 0, $maxLen);
}

function sanitizeEmail(mixed $v): string|false {
    $e = filter_var(trim((string)$v), FILTER_SANITIZE_EMAIL);
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? strtolower($e) : false;
}

function checkRateLimit(string $key, int $max = 5, int $window = 300): void {
    iniciarSesionSegura();
    $now  = time();
    $data = $_SESSION['rl'][$key] ?? ['count'=>0,'start'=>$now];
    if ($now - $data['start'] > $window) $data = ['count'=>0,'start'=>$now];
    $data['count']++;
    $_SESSION['rl'][$key] = $data;
    if ($data['count'] > $max) {
        $wait = $window - ($now - $data['start']);
        jsonError("Demasiados intentos. Espera {$wait} segundos.", 429);
    }
}

function resetRateLimit(string $key): void { unset($_SESSION['rl'][$key]); }

function registrarAudit(int $usuarioId, string $accion, int $exitoso, mixed $detalle = null): void {
    $ds = null;
    if ($detalle !== null) {
        $ds = is_string($detalle)
            ? mb_substr($detalle, 0, 65535)
            : mb_substr(json_encode($detalle, JSON_UNESCAPED_UNICODE), 0, 65535);
    }
    // Obtener id_empresa de la sesion activa
    $idEmpresa = isset($_SESSION['id_empresa']) ? (int)$_SESSION['id_empresa'] : null;
    try {
        getDB()->prepare(
            'INSERT INTO audit_log (id_empresa,id_usuario,accion,detalle,ip,user_agent,exitoso,fecha)
             VALUES (?,?,?,?,?,?,?,NOW())'
        )->execute([
            $idEmpresa,
            $usuarioId, $accion, $ds,
            $_SERVER['REMOTE_ADDR']    ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            $exitoso,
        ]);
    } catch (\Throwable) {}
}

function limpiarBrandCache(?int $idCentro = null): void {
    iniciarSesionSegura();
    unset($_SESSION['brand_'.($idCentro ?? 'sa')]);
}