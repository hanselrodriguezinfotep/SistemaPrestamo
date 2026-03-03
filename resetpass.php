<?php
// resetpass.php — GestionPrestamo | Reset directo de contraseña
// Coloca este archivo en: C:\xampp4\htdocs\GestionPrestamo\resetpass.php
// ELIMÍNALO del servidor una vez que restablezcas la contraseña.

require_once __DIR__ . '/config/db.php';

$mensaje  = '';
$tipo     = '';
$usuarios = [];

try {
    $db       = getDB();
    $stmt     = $db->query('SELECT id, username, nombre, rol, activo FROM usuarios ORDER BY id ASC');
    $usuarios = $stmt->fetchAll();
} catch (Throwable $e) {
    $mensaje = 'Error conectando a la BD: ' . $e->getMessage();
    $tipo    = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id'], $_POST['nueva_pass'])) {
    $id   = (int)$_POST['usuario_id'];
    $pass = trim($_POST['nueva_pass']);

    if (strlen($pass) < 4) {
        $mensaje = 'La contraseña debe tener al menos 4 caracteres.';
        $tipo    = 'error';
    } else {
        try {
            $db   = getDB();
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE usuarios SET password = ?, cambiar_password = 0 WHERE id = ?')
               ->execute([$hash, $id]);
            $row     = $db->prepare('SELECT username FROM usuarios WHERE id = ?');
            $row->execute([$id]);
            $usuario = $row->fetch();
            $mensaje = '✅ Contraseña de <strong>' . htmlspecialchars($usuario['username']) . '</strong> restablecida.';
            $tipo    = 'success';
        } catch (Throwable $e) {
            $mensaje = 'Error: ' . $e->getMessage();
            $tipo    = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — GestionPrestamo</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{min-height:100vh;background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif;padding:20px}
        .card{background:#fff;border-radius:16px;padding:36px 40px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.4)}
        .logo{text-align:center;margin-bottom:28px}
        .logo span{font-size:2.4rem;display:block;margin-bottom:8px}
        .logo h1{font-size:1.4rem;color:#1a1a2e;font-weight:700}
        .logo p{font-size:.82rem;color:#6b7280;margin-top:4px}
        .badge-warn{background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:8px;padding:10px 14px;font-size:.8rem;margin-bottom:24px;display:flex;gap:8px}
        label{display:block;font-size:.84rem;font-weight:600;color:#374151;margin-bottom:6px}
        select,input[type=password]{width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:.95rem;color:#1f2937;background:#f9fafb;outline:none;transition:border .2s;margin-bottom:18px}
        select:focus,input[type=password]:focus{border-color:#4f46e5;background:#fff}
        .field-wrap{position:relative;margin-bottom:18px}
        .field-wrap input{margin-bottom:0;padding-right:44px}
        .toggle-pw{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:1.1rem;color:#6b7280}
        .btn{width:100%;padding:12px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:opacity .2s,transform .1s}
        .btn:hover{opacity:.92;transform:translateY(-1px)}
        .alert{border-radius:8px;padding:12px 16px;font-size:.88rem;margin-bottom:20px}
        .alert.error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
        .alert.success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
        .user-list{margin-top:28px;border-top:1px solid #e5e7eb;padding-top:20px}
        .user-list h3{font-size:.85rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px}
        table{width:100%;border-collapse:collapse;font-size:.83rem}
        th{background:#f3f4f6;color:#374151;font-weight:600;text-align:left;padding:8px 10px}
        td{padding:7px 10px;border-bottom:1px solid #f3f4f6;color:#1f2937}
        tr:last-child td{border-bottom:none}
        .badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.75rem;font-weight:600}
        .badge.superadmin{background:#ede9fe;color:#6d28d9}
        .badge.admin{background:#dbeafe;color:#1d4ed8}
        .badge.cobrador{background:#d1fae5;color:#065f46}
        .badge.cliente{background:#f3f4f6;color:#374151}
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <span>🔐</span>
        <h1>GestionPrestamo</h1>
        <p>Restablecer Contraseña</p>
    </div>

    <div class="badge-warn">
        ⚠️ <span>Archivo de uso administrativo. <strong>Elimínalo del servidor</strong> cuando termines.</span>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert <?= $tipo ?>"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <label for="usuario_id">👤 Seleccionar usuario</label>
        <select name="usuario_id" id="usuario_id" required>
            <option value="">-- Selecciona un usuario --</option>
            <?php foreach ($usuarios as $u): ?>
                <option value="<?= $u['id'] ?>" <?= (isset($_POST['usuario_id']) && $_POST['usuario_id'] == $u['id']) ? 'selected' : '' ?>>
                    #<?= $u['id'] ?> — <?= htmlspecialchars($u['username']) ?> (<?= $u['rol'] ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label for="nueva_pass">🔑 Nueva contraseña</label>
        <div class="field-wrap">
            <input type="password" id="nueva_pass" name="nueva_pass" placeholder="Mínimo 4 caracteres" required>
            <button type="button" class="toggle-pw" onclick="togglePw()">👁</button>
        </div>

        <button type="submit" class="btn">Restablecer contraseña</button>
    </form>

    <?php if ($usuarios): ?>
    <div class="user-list">
        <h3>Usuarios en el sistema</h3>
        <table>
            <tr><th>ID</th><th>Username</th><th>Nombre</th><th>Rol</th></tr>
            <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= $u['id'] ?></td>
                <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                <td><?= htmlspecialchars($u['nombre']) ?></td>
                <td><span class="badge <?= $u['rol'] ?>"><?= $u['rol'] ?></span></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
function togglePw(){
    const i=document.getElementById('nueva_pass');
    const b=document.querySelector('.toggle-pw');
    i.type=i.type==='password'?'text':'password';
    b.textContent=i.type==='password'?'👁':'🙈';
}
</script>
</body>
</html>