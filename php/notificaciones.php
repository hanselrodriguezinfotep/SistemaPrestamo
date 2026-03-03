<?php
/**
 * php/notificaciones.php — Sistema de Notificaciones GestionPrestamo
 * ─────────────────────────────────────────────────────────────────────
 * Funciones disponibles (todas usan id_empresa para leer configuración):
 *
 *   notif_credenciales(int $id_persona, string $username, string $password, int $id_empresa): array
 *   notif_pago(int $id_cliente, float $monto, string $concepto, string $fecha, int $id_empresa): array
 *   notif_contrato(int $id_cliente, string $periodo, string $seccion, int $id_empresa): array
 *   notif_cuota_vencer(int $id_cliente, string $concepto, float $monto, string $fecha_vence, int $id_empresa): array
 *
 * Cada función retorna:
 *   ['email' => bool, 'whatsapp' => bool, 'errores' => string[]]
 *
 * Requiere PHPMailer (incluido como clase interna ligera basada en sockets).
 * Compatible con MySQL 5.4+ y PHP 8.1+.
 */

require_once __DIR__ . '/../config/db.php';

// ══════════════════════════════════════════════════════════════════════════════
// OBTENER CONFIGURACIÓN DEL CENTRO
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Lee la configuración de notificaciones y SMTP del centro.
 * Retorna null si no existe configuración.
 */
function _notif_cfg(int $id_empresa): ?array {
    static $cache = [];
    if (isset($cache[$id_empresa])) return $cache[$id_empresa];
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT id_empresa, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from_name,
                    smtp_security, email, nombre_empresa, callmebot_key,
                    whatsapp_prefix, notif_canal,
                    notif_credenciales, notif_pago, notif_cuota_vencer
             FROM configuracion_empresa
             WHERE id_empresa = ? LIMIT 1'
        );
        $stmt->execute([$id_empresa]);
        $row = $stmt->fetch();
        $cache[$id_empresa] = $row ?: null;
        return $cache[$id_empresa];
    } catch (\Throwable) {
        return null;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// OBTENER CONTACTOS DE UNA PERSONA
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Retorna todos los contactos de una persona por tipo.
 * Tipos válidos: 'Email', 'WhatsApp', 'Telefono'
 * Retorna array de strings (puede haber múltiples).
 */
function _notif_contactos(int $id_persona, string $tipo): array {
    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT valor FROM contactos_persona
             WHERE id_persona = ? AND tipo_contacto = ?
             ORDER BY id ASC"
        );
        $stmt->execute([$id_persona, $tipo]);
        return array_column($stmt->fetchAll(), 'valor');
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Dado un id_cliente, retorna el id_persona del cliente
 * y los del(los) tutor(es) asociados.
 * Retorna array de id_persona únicos.
 */
function _notif_personas_cliente(int $id_cliente, int $id_empresa): array {
    try {
        $db = getDB();

        // Persona directa del cliente
        $s = $db->prepare(
            'SELECT est.id_persona
             FROM personas est
             WHERE est.id = ? AND est.id_empresa = ?
             LIMIT 1'
        );
        $s->execute([$id_cliente, $id_empresa]);
        $row = $s->fetch();
        if (!$row) return [];

        $ids = [(int)$row['id_persona']];

        // Tutores vinculados
        $t = $db->prepare(
            'SELECT t.id_persona
             FROM tutores t
             JOIN tutor_cliente te ON te.id_garante = t.id
             WHERE te.id_cliente = ?'
        );
        $t->execute([$id_cliente]);
        foreach ($t->fetchAll() as $tr) {
            $p = (int)$tr['id_persona'];
            if (!in_array($p, $ids, true)) $ids[] = $p;
        }

        return $ids;
    } catch (\Throwable) {
        return [];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ENVÍO DE EMAIL (implementación nativa con fsockopen/stream — sin Composer)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Envía un correo usando los datos SMTP de configuracion_empresa.
 *
 * @param array  $cfg       Fila de configuracion_empresa
 * @param string $to        Dirección destino
 * @param string $toName    Nombre del destinatario
 * @param string $subject   Asunto
 * @param string $bodyHtml  Cuerpo HTML
 * @param string $bodyText  Cuerpo texto plano (fallback)
 * @return true|string      true si OK, string con error si falla
 */
function _notif_sendEmail(array $cfg, string $to, string $toName, string $subject, string $bodyHtml, string $bodyText = ''): true|string {
    $host     = $cfg['smtp_host']      ?? '';
    $port     = (int)($cfg['smtp_port'] ?? 465);
    $user     = $cfg['smtp_user']      ?? '';
    $pass     = $cfg['smtp_pass']      ?? '';
    $fromName = $cfg['smtp_from_name'] ?? $cfg['nombre_empresa'] ?? 'Sistema';
    $from     = $cfg['email']          ?? $user;
    $sec      = strtoupper($cfg['smtp_security'] ?? 'SSL');

    if (!$host || !$user || !$pass) {
        return 'SMTP no configurado (falta host, usuario o contraseña).';
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return "Dirección de email inválida: $to";
    }

    if (!$bodyText) {
        $bodyText = strip_tags(str_replace(['<br>', '<br/>', '<br />','</p>','</div>'], "\n", $bodyHtml));
    }

    try {
        $mailer = new _CeMailer($host, $port, $user, $pass, $sec);
        $mailer->from     = $from;
        $mailer->fromName = $fromName;
        $mailer->to       = $to;
        $mailer->toName   = $toName;
        $mailer->subject  = $subject;
        $mailer->bodyHtml = $bodyHtml;
        $mailer->bodyText = $bodyText;
        return $mailer->send();
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ENVÍO DE WHATSAPP (CallMeBot API)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Envía un mensaje de WhatsApp vía CallMeBot.
 * El número debe estar en formato internacional (sin espacios): +18091234567
 * La API Key se obtiene en configuracion_empresa.callmebot_key (por centro).
 *
 * IMPORTANTE: el cliente debe haber activado CallMeBot en su número primero
 * enviando "I allow callmebot to send me messages" al +34 644 91 23 35.
 *
 * @return true|string true si OK, string con error si falla
 */
function _notif_sendWhatsApp(string $phone, string $message, string $apiKey): true|string {
    if (!$apiKey)   return 'API Key de CallMeBot no configurada.';
    if (!$phone)    return 'Número de WhatsApp vacío.';

    // Limpiar número: quitar espacios y guiones
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    if (!preg_match('/^\+?\d{7,15}$/', $phone)) {
        return "Número inválido: $phone";
    }

    $url = 'https://api.callmebot.com/whatsapp.php?'
         . http_build_query([
               'phone'  => $phone,
               'text'   => $message,
               'apikey' => $apiKey,
           ]);

    // Usar cURL si está disponible, si no file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err)       return "cURL error: $err";
        if ($code >= 400) return "CallMeBot HTTP $code: " . substr($resp ?: '', 0, 200);
        return true;
    }

    // Fallback: file_get_contents
    $ctx = stream_context_create(['http' => [
        'timeout'        => 10,
        'ignore_errors'  => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return 'No se pudo conectar a CallMeBot.';
    return true;
}

// ══════════════════════════════════════════════════════════════════════════════
// DISPATCHER GENÉRICO
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Envía notificación a todas las personas asociadas a la persona/cliente.
 *
 * @param array  $ids_persona   IDs de personas a notificar
 * @param array  $cfg           Config del centro
 * @param string $subject       Asunto del email
 * @param string $bodyHtml      Cuerpo HTML del email
 * @param string $msgWhatsApp   Mensaje de texto para WhatsApp
 * @return array ['email'=>bool,'whatsapp'=>bool,'errores'=>string[]]
 */
function _notif_dispatch(array $ids_persona, array $cfg, string $subject, string $bodyHtml, string $msgWhatsApp): array {
    $canal     = $cfg['notif_canal'] ?? 'email';
    $apiKey    = $cfg['callmebot_key'] ?? '';
    $prefix    = $cfg['whatsapp_prefix'] ?? '+1';
    $id_empresa = (int)($cfg['id_empresa'] ?? 0);
    $resultado = ['email' => false, 'whatsapp' => false, 'errores' => []];

    foreach ($ids_persona as $idp) {
        // ── Email ──────────────────────────────────────────────────────────
        $emails = _notif_contactos($idp, 'Email');
        // Fallback a personas.email si no hay en contactos_persona
        if (empty($emails)) {
            try {
                $db = getDB();
                $s  = $db->prepare('SELECT email FROM personas WHERE id = ? AND email IS NOT NULL LIMIT 1');
                $s->execute([$idp]);
                $r = $s->fetchColumn();
                if ($r) $emails = [$r];
            } catch (\Throwable) {}
        }

        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            // Obtener nombre de la persona
            $nombre = _notif_nombre_persona($idp);
            $res = _notif_sendEmail($cfg, $email, $nombre, $subject, $bodyHtml);
            if ($res === true) {
                $resultado['email'] = true;
            } else {
                $resultado['errores'][] = "Email a $email: $res";
            }
        }

        // ── WhatsApp ───────────────────────────────────────────────────────
        if (str_contains($canal, 'whatsapp') && $apiKey) {
            $numeros = _notif_contactos($idp, 'WhatsApp');
            foreach ($numeros as $num) {
                // Añadir prefijo si no tiene código de país
                $phone = $num;
                if (!str_starts_with($phone, '+')) {
                    $phone = $prefix . ltrim($phone, '0');
                }
                $res = _notif_sendWhatsApp($phone, $msgWhatsApp, $apiKey);
                if ($res === true) {
                    $resultado['whatsapp'] = true;
                } else {
                    $resultado['errores'][] = "WhatsApp $num: $res";
                }
            }
        }
    }

    // ── Broadcast CallMeBot a todos los números activos de la tabla ───────────
    // Esto se ejecuta UNA vez por notificación (sin importar cuántas personas haya),
    // usando la api key del centro para enviar a cada número registrado.
    if (str_contains($canal, 'whatsapp') && $apiKey && $id_empresa) {
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT phone FROM callmebot_numeros
                 WHERE id_empresa = ? AND activo = 1
                 ORDER BY id ASC"
            );
            $stmt->execute([$id_empresa]);
            $numsCbm = array_column($stmt->fetchAll(), 'phone');
            foreach ($numsCbm as $num) {
                $phone = $num;
                if (!str_starts_with($phone, '+')) {
                    $phone = $prefix . ltrim($phone, '0');
                }
                $res = _notif_sendWhatsApp($phone, $msgWhatsApp, $apiKey);
                if ($res === true) {
                    $resultado['whatsapp'] = true;
                } else {
                    $resultado['errores'][] = "CallMeBot[$phone]: $res";
                }
            }
        } catch (\Throwable) {}
    }

    return $resultado;
}

/** Retorna el nombre completo de una persona */
function _notif_nombre_persona(int $id_persona): string {
    try {
        $db = getDB();
        $s  = $db->prepare('SELECT nombre, apellido FROM personas WHERE id = ? LIMIT 1');
        $s->execute([$id_persona]);
        $r = $s->fetch();
        return $r ? trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? '')) : '';
    } catch (\Throwable) {
        return '';
    }
}

/** Retorna nombre completo del cliente dado su id en tabla clientes */
function _notif_nombre_cliente(int $id_cliente): string {
    try {
        $db = getDB();
        $s  = $db->prepare(
            'SELECT p.nombre, p.apellido
             FROM personas e
             JOIN personas p ON p.id = e.id_persona
             WHERE e.id = ? LIMIT 1'
        );
        $s->execute([$id_cliente]);
        $r = $s->fetch();
        return $r ? trim(($r['nombre'] ?? '') . ' ' . ($r['apellido'] ?? '')) : "Cliente #$id_cliente";
    } catch (\Throwable) {
        return "Cliente #$id_cliente";
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PLANTILLAS HTML PARA EMAILS
// ══════════════════════════════════════════════════════════════════════════════

function _notif_email_wrap(string $titulo, string $contenido, string $empresa, string $pie = ''): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif}
  .wrap{max-width:560px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08)}
  .header{background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:28px 32px;text-align:center;color:#fff}
  .header h1{margin:0;font-size:1.3rem;letter-spacing:.02em}
  .header p{margin:6px 0 0;font-size:.85rem;opacity:.85}
  .body{padding:28px 32px;color:#334155;font-size:.9rem;line-height:1.7}
  .field{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;margin:14px 0}
  .field label{display:block;font-size:.72rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
  .field span{font-size:1rem;font-weight:700;color:#1e40af}
  .badge{display:inline-block;background:#dbeafe;color:#1e40af;border-radius:20px;padding:4px 14px;font-size:.8rem;font-weight:700;margin-top:4px}
  .footer{background:#f8fafc;padding:16px 32px;text-align:center;font-size:.72rem;color:#94a3b8;border-top:1px solid #e2e8f0}
  .btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:11px 24px;border-radius:8px;font-weight:700;font-size:.88rem;margin-top:16px}
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>🏢 {$empresa}</h1>
    <p>{$titulo}</p>
  </div>
  <div class="body">{$contenido}</div>
  <div class="footer">
    {$pie}<br>© {$empresa} — Sistema de Gestión de Préstamos
  </div>
</div>
</body>
</html>
HTML;
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCIONES PÚBLICAS DE NOTIFICACIÓN
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Notifica al usuario sus credenciales de acceso recién creadas.
 * Disparar desde api/usuarios.php al crear usuario.
 *
 * @param int    $id_persona  ID de la persona en tabla personas
 * @param string $username    Nombre de usuario asignado
 * @param string $password    Contraseña en texto plano (antes del hash)
 * @param int    $id_empresa   ID del empresa
 */
function notif_credenciales(int $id_persona, string $username, string $password, int $id_empresa): array {
    $cfg = _notif_cfg($id_empresa);
    if (!$cfg || !$cfg['notif_credenciales']) {
        return ['email' => false, 'whatsapp' => false, 'errores' => []];
    }

    $empresa = $cfg['nombre_empresa'] ?? 'Centro Educativo';
    $nombre  = _notif_nombre_persona($id_persona);
    $url     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/GestionPrestamo/login.php';

    // Email
    $contenido = "
        <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
        <p>Tu cuenta de acceso al sistema <strong>" . htmlspecialchars($empresa) . "</strong> ha sido creada exitosamente.</p>
        <div class='field'>
          <label>Usuario</label>
          <span>" . htmlspecialchars($username) . "</span>
        </div>
        <div class='field'>
          <label>Contraseña temporal</label>
          <span>" . htmlspecialchars($password) . "</span>
        </div>
        <p style='font-size:.82rem;color:#64748b'>
          ⚠️ Por seguridad, te recomendamos cambiar tu contraseña al ingresar por primera vez.
        </p>
        <a href='" . htmlspecialchars($url) . "' class='btn'>🔑 Ir al sistema</a>
    ";
    $bodyHtml = _notif_email_wrap(
        'Credenciales de Acceso',
        $contenido,
        $empresa,
        'Este mensaje fue generado automáticamente.'
    );

    // WhatsApp
    $msgWA = "🏢 *{$empresa}*\n\n"
           . "Hola {$nombre}, tu cuenta ha sido creada.\n\n"
           . "👤 Usuario: `{$username}`\n"
           . "🔑 Contraseña: `{$password}`\n\n"
           . "Ingresa en: {$url}\n"
           . "_Cambia tu contraseña al ingresar._";

    return _notif_dispatch([$id_persona], $cfg, "Credenciales de acceso — {$empresa}", $bodyHtml, $msgWA);
}

/**
 * Notifica la confirmación de un pago registrado.
 * Disparar desde api/modulos.php al guardar un pago nuevo.
 *
 * @param int    $id_cliente ID en tabla clientes
 * @param float  $monto         Monto pagado
 * @param string $concepto      Nombre del concepto de cobro
 * @param string $fecha         Fecha del pago (Y-m-d)
 * @param int    $id_empresa     ID del centro
 * @param string $moneda        Símbolo de moneda (DOP, USD, etc.)
 */
function notif_pago(int $id_cliente, float $monto, string $concepto, string $fecha, int $id_empresa, string $moneda = 'RD$'): array {
    $cfg = _notif_cfg($id_empresa);
    if (!$cfg || !$cfg['notif_pago']) {
        return ['email' => false, 'whatsapp' => false, 'errores' => []];
    }

    $empresa   = $cfg['nombre_empresa'] ?? 'Centro Educativo';
    $nombreEst = _notif_nombre_cliente($id_cliente);
    $montoFmt  = number_format($monto, 2);
    $fechaFmt  = date('d/m/Y', strtotime($fecha));
    // Símbolo legible según moneda
    $simbolo   = match($moneda) {
        'USD' => 'US$', 'EUR' => '€', 'DOP' => 'RD$',
        default => $moneda
    };

    // Email
    $contenido = "
        <p>Se ha registrado exitosamente el siguiente pago:</p>
        <div class='field'>
          <label>Cliente</label>
          <span>" . htmlspecialchars($nombreEst) . "</span>
        </div>
        <div class='field'>
          <label>Concepto</label>
          <span>" . htmlspecialchars($concepto) . "</span>
        </div>
        <div class='field'>
          <label>Monto pagado</label>
          <span>{$simbolo} {$montoFmt}</span>
        </div>
        <div class='field'>
          <label>Fecha</label>
          <span>{$fechaFmt}</span>
        </div>
        <p style='font-size:.82rem;color:#64748b'>
          Conserve este mensaje como comprobante de su pago.
        </p>
    ";
    $bodyHtml = _notif_email_wrap(
        "Confirmación de Pago — {$empresa}",
        $contenido,
        $empresa,
        'Este recibo fue generado automáticamente por el sistema.'
    );

    // WhatsApp
    $msgWA = "🏢 *{$empresa}*\n\n"
           . "✅ *Pago recibido*\n\n"
           . "👤 Cliente: {$nombreEst}\n"
           . "📋 Concepto: {$concepto}\n"
           . "💰 Monto: {$simbolo} {$montoFmt}\n"
           . "📅 Fecha: {$fechaFmt}\n\n"
           . "_Guarde este mensaje como comprobante._";

    $ids = _notif_personas_cliente($id_cliente, $id_empresa);
    return _notif_dispatch($ids, $cfg, "Confirmación de pago — {$empresa}", $bodyHtml, $msgWA);
}

/**
 * Notifica que una cuota está próxima a vencer.
 * Llamar desde un cron o proceso de revisión de cuotas.
 *
 * @param int    $id_cliente
 * @param string $concepto
 * @param float  $monto
 * @param string $fecha_vence   Fecha límite (Y-m-d)
 * @param int    $id_empresa
 * @param string $moneda
 */
function notif_cuota_vencer(int $id_cliente, string $concepto, float $monto, string $fecha_vence, int $id_empresa, string $moneda = 'RD$'): array {
    $cfg = _notif_cfg($id_empresa);
    if (!$cfg || !$cfg['notif_cuota_vencer']) {
        return ['email' => false, 'whatsapp' => false, 'errores' => []];
    }

    $empresa   = $cfg['nombre_empresa'] ?? 'Centro Educativo';
    $nombreEst = _notif_nombre_cliente($id_cliente);
    $montoFmt  = number_format($monto, 2);
    $fechaFmt  = date('d/m/Y', strtotime($fecha_vence));
    $diasRest  = max(0, (int)ceil((strtotime($fecha_vence) - time()) / 86400));
    $simbolo   = match($moneda) {
        'USD' => 'US$', 'EUR' => '€', 'DOP' => 'RD$', default => $moneda
    };

    $alerta = $diasRest <= 3 ? "⚠️ ¡Vence en {$diasRest} día(s)!" : "📅 Vence en {$diasRest} días.";

    // Email
    $contenido = "
        <p>Le recordamos que tiene un pago pendiente:</p>
        <div class='field'>
          <label>Cliente</label>
          <span>" . htmlspecialchars($nombreEst) . "</span>
        </div>
        <div class='field'>
          <label>Concepto</label>
          <span>" . htmlspecialchars($concepto) . "</span>
        </div>
        <div class='field'>
          <label>Monto</label>
          <span>{$simbolo} {$montoFmt}</span>
        </div>
        <div class='field'>
          <label>Fecha límite</label>
          <span>{$fechaFmt}</span>
        </div>
        <p style='color:" . ($diasRest <= 3 ? '#b91c1c' : '#92400e') . ";font-weight:700;font-size:.9rem'>
          {$alerta}
        </p>
        <p style='font-size:.82rem;color:#64748b'>
          Por favor realice su pago antes de la fecha límite para evitar recargos.
        </p>
    ";
    $bodyHtml = _notif_email_wrap(
        "Recordatorio de Pago Pendiente",
        $contenido,
        $empresa
    );

    // WhatsApp
    $msgWA = "🏢 *{$empresa}*\n\n"
           . "🔔 *Recordatorio de pago*\n\n"
           . "👤 Cliente: {$nombreEst}\n"
           . "📋 Concepto: {$concepto}\n"
           . "💰 Monto: {$simbolo} {$montoFmt}\n"
           . "📅 Vence: {$fechaFmt}\n"
           . "{$alerta}\n\n"
           . "_Realice su pago antes de la fecha límite._";

    $ids = _notif_personas_cliente($id_cliente, $id_empresa);
    return _notif_dispatch($ids, $cfg, "Recordatorio de pago — {$empresa}", $bodyHtml, $msgWA);
}

/**
 * Notifica la contrato exitosa de un cliente.
 * Disparar desde api/modulos.php al registrar contrato nueva.
 *
 * @param int    $id_cliente
 * @param string $periodo       Ej: "2025-2026"
 * @param string $producto         Ej: "1ro de Secundaria"
 * @param string $seccion       Ej: "A"
 * @param int    $id_empresa
 */
function notif_contrato(int $id_cliente, string $periodo, string $producto, string $seccion, int $id_empresa): array {
    $cfg = _notif_cfg($id_empresa);
    // La contrato siempre notifica si hay SMTP configurado (no tiene flag propio en BD)
    if (!$cfg) {
        return ['email' => false, 'whatsapp' => false, 'errores' => []];
    }

    $empresa   = $cfg['nombre_empresa'] ?? 'Centro Educativo';
    $nombreEst = _notif_nombre_cliente($id_cliente);

    // Email
    $contenido = "
        <p>La contrato del siguiente cliente ha sido registrada exitosamente:</p>
        <div class='field'>
          <label>Cliente</label>
          <span>" . htmlspecialchars($nombreEst) . "</span>
        </div>
        <div class='field'>
          <label>Período escolar</label>
          <span>" . htmlspecialchars($periodo) . "</span>
        </div>
        <div class='field'>
          <label>Grado</label>
          <span>" . htmlspecialchars($producto) . "</span>
        </div>
        <div class='field'>
          <label>Sección</label>
          <span>" . htmlspecialchars($seccion) . "</span>
        </div>
        <p style='font-size:.82rem;color:#64748b'>
          Bienvenido al año escolar {$periodo}. ¡Mucho éxito!
        </p>
    ";
    $bodyHtml = _notif_email_wrap(
        "Contrato Confirmada — {$periodo}",
        $contenido,
        $empresa
    );

    // WhatsApp
    $msgWA = "🏢 *{$empresa}*\n\n"
           . "✅ *Contrato confirmada*\n\n"
           . "👤 Cliente: {$nombreEst}\n"
           . "📅 Período: {$periodo}\n"
           . "💰 Grado: {$producto}\n"
           . "🏢 Sección: {$seccion}\n\n"
           . "_¡Bienvenido al nuevo año escolar!_";

    $ids = _notif_personas_cliente($id_cliente, $id_empresa);
    return _notif_dispatch($ids, $cfg, "Contrato confirmada — {$empresa}", $bodyHtml, $msgWA);
}

// ══════════════════════════════════════════════════════════════════════════════
// CLASE INTERNA DE CORREO (sin Composer, usa sockets PHP nativos)
// Soporta SSL (puerto 465) y TLS/STARTTLS (puerto 587)
// ══════════════════════════════════════════════════════════════════════════════

class _CeMailer {
    public string $from     = '';
    public string $fromName = '';
    public string $to       = '';
    public string $toName   = '';
    public string $subject  = '';
    public string $bodyHtml = '';
    public string $bodyText = '';

    private string $host;
    private int    $port;
    private string $user;
    private string $pass;
    private string $sec;   // 'SSL' | 'TLS' | 'NONE'

    public function __construct(string $host, int $port, string $user, string $pass, string $sec = 'SSL') {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->pass = $pass;
        $this->sec  = strtoupper($sec);
    }

    public function send(): true|string {
        $errMsg = '';
        $errNo  = 0;
        $timeout = 15;

        // Abrir socket
        if ($this->sec === 'SSL') {
            $sock = @fsockopen("ssl://{$this->host}", $this->port, $errNo, $errMsg, $timeout);
        } else {
            $sock = @fsockopen($this->host, $this->port, $errNo, $errMsg, $timeout);
        }

        if (!$sock) return "No se pudo conectar al servidor SMTP: {$errMsg} ({$errNo})";

        stream_set_timeout($sock, $timeout);

        try {
            $read = $this->_read($sock);
            if (!str_starts_with($read, '2')) return "SMTP saludo: $read";

            $domain = gethostname() ?: 'localhost';
            $this->_cmd($sock, "EHLO $domain", '250');

            // STARTTLS para TLS
            if ($this->sec === 'TLS') {
                $this->_cmd($sock, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'No se pudo iniciar TLS.';
                }
                $this->_cmd($sock, "EHLO $domain", '250');
            }

            // AUTH LOGIN
            $this->_cmd($sock, 'AUTH LOGIN', '334');
            $this->_cmd($sock, base64_encode($this->user), '334');
            $this->_cmd($sock, base64_encode($this->pass), '235');

            // Envío
            $from = $this->from ?: $this->user;
            $this->_cmd($sock, "MAIL FROM:<{$from}>", '250');
            $this->_cmd($sock, "RCPT TO:<{$this->to}>", '250');
            $this->_cmd($sock, 'DATA', '354');

            // Construir mensaje MIME multipart
            $boundary = 'ce_' . md5(uniqid('', true));
            $headers  = $this->_buildHeaders($boundary);
            $body     = $this->_buildBody($boundary);

            $this->_write($sock, $headers . "\r\n" . $body . "\r\n.");
            $resp = $this->_read($sock);
            if (!str_starts_with($resp, '2')) return "DATA error: $resp";

            $this->_cmd($sock, 'QUIT', '221', false);
            return true;

        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } finally {
            fclose($sock);
        }
    }

    private function _buildHeaders(string $boundary): string {
        $from    = $this->from ?: $this->user;
        $fn      = $this->fromName ? $this->_encode($this->fromName) . " <{$from}>" : "<{$from}>";
        $tn      = $this->toName  ? $this->_encode($this->toName)   . " <{$this->to}>" : "<{$this->to}>";
        $subj    = $this->_encode($this->subject);
        $msgId   = '<' . time() . '.' . mt_rand() . '@' . ($this->host) . '>';
        $date    = date('r');

        return "Date: {$date}\r\n"
             . "From: {$fn}\r\n"
             . "To: {$tn}\r\n"
             . "Message-ID: {$msgId}\r\n"
             . "Subject: {$subj}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
             . "X-Mailer: GestionPrestamo-Mailer/1.0";
    }

    private function _buildBody(string $boundary): string {
        $plain = wordwrap($this->bodyText, 76, "\r\n", false);
        $html  = $this->bodyHtml;

        return "--{$boundary}\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($plain)) . "\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($html)) . "\r\n"
             . "--{$boundary}--";
    }

    private function _encode(string $str): string {
        if (mb_detect_encoding($str, 'ASCII', true)) return $str;
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }

    private function _write($sock, string $data): void {
        fwrite($sock, $data . "\r\n");
    }

    private function _read($sock): string {
        $out = '';
        while ($line = fgets($sock, 512)) {
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return trim($out);
    }

    private function _cmd($sock, string $cmd, string $expect, bool $throw = true): string {
        $this->_write($sock, $cmd);
        $resp = $this->_read($sock);
        if ($throw && !str_starts_with($resp, $expect)) {
            throw new \RuntimeException("SMTP cmd '{$cmd}' esperaba {$expect}, recibió: {$resp}");
        }
        return $resp;
    }
}
