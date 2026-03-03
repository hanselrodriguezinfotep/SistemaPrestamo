<?php
// api/pagos.php
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesion();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? sanitizeInt($_GET['id']) : null;

match ($method) {
    'GET'    => getPagos(),
    'POST'   => registrarPago($usuario),
    'DELETE' => $id ? anularPago($id, $usuario) : jsonError('ID requerido'),
    default  => jsonError('Método no permitido', 405),
};

// ─────────────────────────────────────────────────────────────

function getPagos(): never {
    $db          = getDB();
    $prestamoId  = isset($_GET['prestamo_id']) ? sanitizeInt($_GET['prestamo_id']) : null;
    $page        = max(1, sanitizeInt($_GET['page'] ?? 1));
    $perPage     = min(100, max(10, sanitizeInt($_GET['per_page'] ?? 20)));
    $offset      = ($page - 1) * $perPage;

    $where  = "WHERE pg.anulado = 0";
    $params = [];

    if ($prestamoId) {
        $where  .= " AND pg.prestamo_id = ?";
        $params[] = $prestamoId;
    }

    $totalStmt = $db->prepare("SELECT COUNT(*) FROM pagos pg $where");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT pg.*,
               p.codigo AS codigo_prestamo,
               CONCAT(c.nombre, ' ', c.apellido) AS cliente
        FROM pagos pg
        JOIN prestamos p ON p.id = pg.prestamo_id
        JOIN clientes  c ON c.id = p.cliente_id
        $where
        ORDER BY pg.creado_en DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $perPage, $offset]);
    $pagos = $stmt->fetchAll();

    jsonResponse([
        'data'       => $pagos,
        'paginacion' => paginar($total, $page, $perPage),
    ]);
}

function registrarPago(array $usuario): never {
    $data           = inputJSON();
    $prestamoId     = sanitizeInt($data['prestamo_id'] ?? 0);
    $numCuota       = sanitizeInt($data['numero_cuota'] ?? 0);
    $monto          = sanitizeFloat($data['monto'] ?? 0);
    $fecha          = sanitizeDate($data['fecha_pago'] ?? date('Y-m-d'));
    $metodos        = ['efectivo', 'transferencia', 'cheque', 'tarjeta'];
    $metodo         = in_array($data['metodo_pago'] ?? '', $metodos) ? $data['metodo_pago'] : 'efectivo';
    $esAbonoCapital = !empty($data['es_abono_capital']);
    $referencia     = sanitizeStr($data['referencia'] ?? '', 100);
    $notas          = sanitizeStr($data['notas'] ?? '', 500);

    if ($prestamoId <= 0) jsonError('Préstamo requerido');
    if ($monto     <= 0) jsonError('El monto debe ser mayor a 0');
    if (!$fecha)         jsonError('Fecha inválida');

    $db = getDB();

    // ── Abono a capital ──────────────────────────────────────
    if ($esAbonoCapital) {
        // Verificar préstamo existe y está activo
        $pStmt = $db->prepare("SELECT id, monto_total, total_pagado FROM prestamos WHERE id = ? AND estado = 'activo'");
        $pStmt->execute([$prestamoId]);
        $prestamo = $pStmt->fetch();

        if (!$prestamo) jsonError('Préstamo no encontrado o no está activo', 404);

        $saldo = round((float)$prestamo['monto_total'] - (float)$prestamo['total_pagado'], 2);
        if ($monto > $saldo + 0.01) jsonError("El monto ($monto) supera el saldo pendiente ($saldo)");

        $db->beginTransaction();
        try {
            // Insertar pago: numero_cuota = 1 es requerido por DB (SMALLINT UNSIGNED NOT NULL)
            // Usamos 9999 como convención para abonos a capital extraordinarios
            $ins = $db->prepare("
                INSERT INTO pagos
                    (prestamo_id, numero_cuota, monto, fecha_pago, metodo_pago, referencia, recibido_por, notas)
                VALUES (?, 9999, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([
                $prestamoId,
                round($monto, 2),
                $fecha,
                $metodo,
                $referencia,
                $usuario['id'],
                $notas ?: 'Abono extraordinario a capital',
            ]);

            // Actualizar total_pagado del préstamo
            $db->prepare("UPDATE prestamos SET total_pagado = ROUND(total_pagado + ?, 2) WHERE id = ?")
               ->execute([round($monto, 2), $prestamoId]);

            // Si saldo queda en 0 → marcar como pagado
            $db->prepare("UPDATE prestamos SET estado = 'pagado' WHERE id = ? AND ROUND(monto_total - total_pagado, 2) <= 0.01")
               ->execute([$prestamoId]);

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            jsonError('Error al registrar abono a capital: ' . $e->getMessage(), 500);
        }

        $resStmt = $db->prepare("SELECT * FROM vista_prestamos_resumen WHERE id = ?");
        $resStmt->execute([$prestamoId]);
        $abonoStmt = $db->prepare("SELECT id FROM pagos WHERE prestamo_id = ? AND numero_cuota = 9999 AND anulado = 0 ORDER BY id DESC LIMIT 1");
        $abonoStmt->execute([$prestamoId]);
        $abonoRec = $abonoStmt->fetch();
        jsonResponse(['mensaje' => 'Abono a capital registrado', 'id' => $abonoRec['id'] ?? null, 'prestamo' => $resStmt->fetch()], 201);
    }

    // ── Pago de cuota regular ────────────────────────────────
    if ($numCuota <= 0) jsonError('Número de cuota requerido');

    $stmt = $db->prepare("CALL sp_registrar_pago(?, ?, ?, ?, ?, ?, ?, ?, @resultado)");
    $stmt->execute([
        $prestamoId, $numCuota, $monto, $fecha, $metodo,
        $referencia, $usuario['id'], $notas,
    ]);
    $stmt->closeCursor();

    $result = $db->query("SELECT @resultado AS resultado")->fetch();

    if ($result['resultado'] !== 'OK') {
        match ($result['resultado']) {
            'ERROR:CUOTA_NO_ENCONTRADA' => jsonError('Cuota no encontrada', 404),
            'ERROR:CUOTA_YA_PAGADA'    => jsonError('Esta cuota ya fue pagada'),
            default                    => jsonError('Error al registrar el pago: ' . $result['resultado']),
        };
    }

    // Obtener el ID del pago recién registrado
    $pagoStmt = $db->prepare("SELECT id FROM pagos WHERE prestamo_id = ? AND numero_cuota = ? AND anulado = 0 ORDER BY id DESC LIMIT 1");
    $pagoStmt->execute([$prestamoId, $numCuota]);
    $pagoRec = $pagoStmt->fetch();

    $resStmt = $db->prepare("SELECT * FROM vista_prestamos_resumen WHERE id = ?");
    $resStmt->execute([$prestamoId]);
    jsonResponse(['mensaje' => 'Pago registrado correctamente', 'id' => $pagoRec['id'] ?? null, 'prestamo' => $resStmt->fetch()], 201);
}

function anularPago(int $id, array $usuario): never {
    if ($usuario['rol'] !== 'admin') jsonError('Solo administradores pueden anular pagos', 403);

    $data   = inputJSON();
    $motivo = sanitizeStr($data['motivo'] ?? 'Anulado por administrador');

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM pagos WHERE id = ? AND anulado = 0");
    $stmt->execute([$id]);
    $pago = $stmt->fetch();

    if (!$pago) jsonError('Pago no encontrado o ya anulado', 404);

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE pagos SET anulado = 1, motivo_anulacion = ? WHERE id = ?")
           ->execute([$motivo, $id]);

        // Solo desmarcar cuota si es pago de cuota regular (no abono a capital 9999)
        if ((int)$pago['numero_cuota'] !== 9999) {
            $db->prepare("UPDATE calendario_pagos SET pagado = 0, fecha_pago_real = NULL WHERE prestamo_id = ? AND numero_cuota = ?")
               ->execute([$pago['prestamo_id'], $pago['numero_cuota']]);
        }

        $db->prepare("UPDATE prestamos SET total_pagado = GREATEST(0, total_pagado - ?), estado = 'activo' WHERE id = ?")
           ->execute([$pago['monto'], $pago['prestamo_id']]);

        $db->commit();
        jsonResponse(['mensaje' => 'Pago anulado correctamente']);
    } catch (\Exception $e) {
        $db->rollBack();
        jsonError('Error al anular el pago', 500);
    }
}
