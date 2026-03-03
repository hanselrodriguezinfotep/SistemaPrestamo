<?php
// api/reformular.php — Reformula el calendario de un préstamo tras abono a capital
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$data       = inputJSON();
$prestamoId = sanitizeInt($data['prestamo_id'] ?? 0);
$plazoNuevo = sanitizeInt($data['plazo_nuevo'] ?? 0); // 0 = mantener cuotas pendientes

if ($prestamoId <= 0) jsonError('Préstamo requerido');

$db = getDB();

// Obtener datos del préstamo
$stmt = $db->prepare("
    SELECT p.id, p.tasa_interes, p.plazo_meses, p.total_pagado, p.monto_total,
           p.monto_principal, p.fecha_inicio, p.cuota_mensual,
           ROUND(p.monto_total - p.total_pagado, 2) AS saldo_real
    FROM prestamos p
    WHERE p.id = ? AND p.estado = 'activo'
");
$stmt->execute([$prestamoId]);
$prestamo = $stmt->fetch();

if (!$prestamo) jsonError('Préstamo no encontrado o no está activo', 404);

$saldoActual  = (float) $prestamo['saldo_real'];
$tasaAnual    = (float) $prestamo['tasa_interes'];

// Cuotas ya pagadas (para determinar cuotas restantes)
$cq = $db->prepare("
    SELECT COUNT(*) FROM calendario_pagos
    WHERE prestamo_id = ? AND pagado = 1
");
$cq->execute([$prestamoId]);
$cuotasPagadas = (int)$cq->fetchColumn();

// Cuotas pendientes restantes
$cuotasRestantes = $plazoNuevo > 0 ? $plazoNuevo
    : ((int)$prestamo['plazo_meses'] - $cuotasPagadas);

if ($cuotasRestantes <= 0) jsonError('No hay cuotas pendientes para reformular');
if ($saldoActual <= 0.01)  jsonError('El saldo ya está en cero');

// ── Calcular nueva cuota con amortización francesa ────────────
if ($tasaAnual == 0) {
    $nuevaCuota = round($saldoActual / $cuotasRestantes, 2);
} else {
    $tasaMensual = ($tasaAnual / 100) / 12;
    $nuevaCuota  = round(
        $saldoActual * ($tasaMensual * pow(1 + $tasaMensual, $cuotasRestantes))
                     / (pow(1 + $tasaMensual, $cuotasRestantes) - 1),
        2
    );
}

$nuevoTotal = round($nuevaCuota * $cuotasRestantes, 2);

// ── Aplicar reformulación en transacción ─────────────────────
$db->beginTransaction();
try {
    // 1. Eliminar cuotas pendientes (no pagadas)
    $db->prepare("
        DELETE FROM calendario_pagos
        WHERE prestamo_id = ? AND pagado = 0
    ")->execute([$prestamoId]);

    // 2. Recalcular y reinsertar cuotas desde hoy
    $fechaBase = new DateTime();
    $saldo     = $saldoActual;
    $numInicio = $cuotasPagadas + 1;

    $insStmt = $db->prepare("
        INSERT INTO calendario_pagos
            (prestamo_id, numero_cuota, fecha_vencimiento,
             monto_capital, monto_interes, monto_cuota, saldo_pendiente)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    for ($i = 1; $i <= $cuotasRestantes; $i++) {
        $fechaCuota = clone $fechaBase;
        $fechaCuota->modify("+{$i} month");

        if ($tasaAnual == 0) {
            $interes = 0;
            $capital = $nuevaCuota;
        } else {
            $interes = round($saldo * $tasaMensual, 2);
            $capital = round($nuevaCuota - $interes, 2);
        }

        // Última cuota: ajustar para no dejar saldo residual
        if ($i === $cuotasRestantes) {
            $capital = $saldo;
            $interes = round($nuevaCuota - $capital, 2);
            if ($interes < 0) $interes = 0;
        }

        $saldo = round($saldo - $capital, 2);
        if ($saldo < 0) $saldo = 0;

        $insStmt->execute([
            $prestamoId,
            $numInicio + $i - 1,
            $fechaCuota->format('Y-m-d'),
            $capital,
            $interes,
            $nuevaCuota,
            $saldo,
        ]);
    }

    // 3. Actualizar datos del préstamo
    $db->prepare("
        UPDATE prestamos SET
            cuota_mensual     = ?,
            monto_total       = ROUND(total_pagado + ?, 2),
            fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL ? MONTH),
            plazo_meses       = ?,
            actualizado_en    = NOW()
        WHERE id = ?
    ")->execute([$nuevaCuota, $nuevoTotal, $cuotasRestantes, $numInicio + $cuotasRestantes - 1, $prestamoId]);

    $db->commit();
} catch (\Exception $e) {
    $db->rollBack();
    jsonError('Error al reformular: ' . $e->getMessage(), 500);
}

// Retornar préstamo actualizado
$res = $db->prepare("SELECT * FROM vista_prestamos_resumen WHERE id = ?");
$res->execute([$prestamoId]);
$pActualizado = $res->fetch();

// Retornar también el nuevo calendario
$cal = $db->prepare("SELECT * FROM calendario_pagos WHERE prestamo_id = ? ORDER BY numero_cuota");
$cal->execute([$prestamoId]);
$pActualizado['calendario_pagos'] = $cal->fetchAll();

jsonResponse([
    'mensaje'          => 'Préstamo reformulado correctamente',
    'nueva_cuota'      => $nuevaCuota,
    'cuotas_restantes' => $cuotasRestantes,
    'nuevo_total'      => $nuevoTotal,
    'prestamo'         => $pActualizado,
], 200);
