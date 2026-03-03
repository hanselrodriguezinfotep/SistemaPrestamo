<?php
// api/prestamos.php — GestionPrestamo
// Soporta: tipo frecuencia, amortización (francés/alemán/americano), período de tasa
require_once __DIR__ . '/../php/helpers.php';

apiHeaders();
$usuario = verificarSesionAPI();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? sanitizeInt($_GET['id']) : null;

match (true) {
    $method === 'GET'    && $id !== null => getPrestamo($id),
    $method === 'GET'                   => getPrestamos(),
    $method === 'POST'                  => crearPrestamo($usuario),
    $method === 'PUT'   && $id !== null => actualizarEstado($id, $usuario),
    $method === 'DELETE'&& $id !== null => cancelarPrestamo($id, $usuario),
    default                             => jsonError('Método no permitido', 405),
};

// ── Períodos por año ──────────────────────────────────────────
function periodosPorAnio(string $p): int {
    return match($p) {
        'anual'          => 1,
        'semestral'      => 2,
        'cuatrimestral'  => 3,
        'trimestral'     => 4,
        'bimestral'      => 6,
        'mensual'        => 12,
        'quincenal'      => 24,
        'semanal'        => 52,
        'diario'         => 365,
        default          => 12,
    };
}

// ── Frecuencias de cuota por año ──────────────────────────────
function freqPorAnio(string $tipo): int {
    return match($tipo) {
        'mensual'    => 12,
        'quincenal'  => 24,
        'semanal'    => 52,
        'diario'     => 365,
        'unico'      => 1,
        'custom'     => 12,
        default      => 12,
    };
}

// ── Calcular cuota según amortización ────────────────────────
function calcularCuota(float $capital, float $tasaP, int $cuotas, string $amort): float {
    if ($cuotas <= 0) return 0.0;
    if ($tasaP == 0)  return round($capital / $cuotas, 2);
    return match($amort) {
        'aleman'    => round(($capital / $cuotas) + $capital * $tasaP, 2), // 1ra cuota
        'americano' => round($capital * $tasaP, 2),                         // solo interés
        default     => round($capital * ($tasaP * pow(1+$tasaP,$cuotas)) / (pow(1+$tasaP,$cuotas)-1), 2), // francés
    };
}

// ── Tasa efectiva por período de cuota ───────────────────────
// Convierte tasa nominal del período indicado a tasa efectiva por cuota
function tasaEfectivaPorCuota(float $tasa, string $periodoTasa, string $tipoFrecuencia): float {
    $n     = periodosPorAnio($periodoTasa);   // veces que se capitaliza en 1 año
    $freq  = freqPorAnio($tipoFrecuencia);    // cuotas por año
    // Tasa efectiva anual
    $tea   = pow(1 + ($tasa / 100) / $n, $n) - 1;
    // Tasa efectiva por período de cuota
    return pow(1 + $tea, 1 / $freq) - 1;
}

// ── GET lista ────────────────────────────────────────────────
function getPrestamos(): never {
    global $usuario;
    $db      = getDB();
    $page    = max(1, sanitizeInt($_GET['page']    ?? 1));
    $perPage = min(100, max(10, sanitizeInt($_GET['per_page'] ?? 15)));
    $offset  = ($page - 1) * $perPage;

    $conds  = [];
    $params = [];

    if (!empty($_GET['estado'])) {
        $conds[]  = 'p.estado = ?';
        $params[] = sanitizeStr($_GET['estado']);
    }
    if (!empty($_GET['cliente_id'])) {
        $conds[]  = 'p.cliente_id = ?';
        $params[] = sanitizeInt($_GET['cliente_id']);
    }
    if (!empty($_GET['q'])) {
        $like     = '%' . sanitizeStr($_GET['q']) . '%';
        $conds[]  = '(CONCAT(c.nombre," ",c.apellido) LIKE ? OR p.codigo LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $total = $db->prepare("SELECT COUNT(*) FROM prestamos p JOIN clientes c ON c.id=p.cliente_id $where");
    $total->execute($params);
    $totalCount = (int)$total->fetchColumn();

    $stmt = $db->prepare("
        SELECT p.*,
               CONCAT(c.nombre,' ',c.apellido) AS cliente,
               c.documento, c.telefono,
               ROUND(p.monto_total - p.total_pagado, 2) AS saldo_pendiente,
               (SELECT COUNT(*) FROM cuotas q WHERE q.prestamo_id=p.id AND q.estado NOT IN ('pagado','cancelado')) AS cuotas_pendientes,
               (SELECT COUNT(*) FROM cuotas q WHERE q.prestamo_id=p.id AND q.estado='pagado') AS cuotas_pagadas
        FROM prestamos p
        JOIN clientes c ON c.id = p.cliente_id
        $where
        ORDER BY p.creado_en DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $perPage, $offset]);

    jsonResponse([
        'data'       => $stmt->fetchAll(),
        'paginacion' => paginar($totalCount, $page, $perPage),
    ]);
}

// ── GET detalle ──────────────────────────────────────────────
function getPrestamo(int $id): never {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT p.*,
               CONCAT(c.nombre,' ',c.apellido) AS cliente,
               c.documento, c.telefono,
               ROUND(p.monto_total - p.total_pagado, 2) AS saldo_pendiente
        FROM prestamos p
        JOIN clientes c ON c.id = p.cliente_id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) jsonError('Préstamo no encontrado', 404);
    jsonResponse($p);
}

// ── POST crear ───────────────────────────────────────────────
function crearPrestamo(array $usuario): never {
    $data = inputJSON();

    // ── Validaciones básicas ──────────────────────────────────
    $clienteId   = sanitizeInt($data['cliente_id']      ?? 0);
    $monto       = (float)($data['monto_principal']      ?? 0);
    $tasa        = (float)($data['tasa_interes']         ?? 0);
    $cuotas      = sanitizeInt($data['plazo_meses']      ?? 0);
    $fechaInicio = sanitizeStr($data['fecha_inicio']     ?? '');
    $tipo        = sanitizeStr($data['tipo']             ?? 'mensual');
    $amort       = sanitizeStr($data['amortizacion']     ?? 'frances');
    $periodoTasa = sanitizeStr($data['periodo_tasa']     ?? 'anual');
    $proposito   = sanitizeStr($data['proposito']        ?? '', 255);
    $notas       = sanitizeStr($data['notas']            ?? '', 1000);

    if (!$clienteId)   jsonError('Cliente requerido');
    if ($monto <= 0)   jsonError('El monto debe ser mayor a 0');
    if ($cuotas < 1)   jsonError('El número de cuotas debe ser al menos 1');
    if (!$fechaInicio) jsonError('La fecha de inicio es requerida');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) jsonError('Fecha de inicio inválida');

    $tiposValidos  = ['mensual','quincenal','semanal','diario','unico','custom'];
    $amortsValidos = ['frances','aleman','americano'];
    if (!in_array($tipo, $tiposValidos, true))   jsonError('Tipo de préstamo inválido');
    if (!in_array($amort, $amortsValidos, true)) jsonError('Tipo de amortización inválido');

    $db = getDB();

    // Verificar cliente
    $chk = $db->prepare("SELECT id FROM clientes WHERE id=? AND activo=1");
    $chk->execute([$clienteId]);
    if (!$chk->fetch()) jsonError('Cliente no encontrado o inactivo');

    // ── Cálculos financieros ──────────────────────────────────
    $tasaP     = tasaEfectivaPorCuota($tasa, $periodoTasa, $tipo);
    $cuotaMonto = calcularCuota($monto, $tasaP, $cuotas, $amort);

    // Total a pagar según amortización
    $montoTotal = match($amort) {
        'americano' => round($cuotaMonto * $cuotas + $monto, 2), // intereses + capital al final
        'aleman'    => round(array_sum(array_map(
                          fn($i) => ($monto/$cuotas) + ($monto - ($monto/$cuotas)*($i-1)) * $tasaP,
                          range(1, $cuotas)
                       )), 2),
        default     => round($cuotaMonto * $cuotas, 2), // francés: cuota fija * n
    };

    // Fecha de vencimiento (última cuota)
    $dt           = new DateTime($fechaInicio);
    $diasPorPeriodo = match($tipo) {
        'diario'   => 1, 'semanal' => 7, 'quincenal' => 15,
        'mensual'  => 0, 'unico'   => 0, 'custom'    => 0, default => 0,
    };
    if ($tipo === 'mensual' || $tipo === 'unico' || $tipo === 'custom') {
        $dt->modify("+{$cuotas} months");
    } else {
        $dt->modify('+' . ($diasPorPeriodo * $cuotas) . ' days');
    }
    $fechaVenc = $dt->format('Y-m-d');

    // Código único: PREST-YYYY-NNNN
    $anio = date('Y');
    $ultStmt = $db->query("SELECT MAX(id) FROM prestamos");
    $nextId  = (int)$ultStmt->fetchColumn() + 1;
    $codigo  = sprintf('PREST-%s-%04d', $anio, $nextId);

    // ── Insertar y generar calendario ────────────────────────
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO prestamos
              (cliente_id, codigo, monto_principal, tasa_interes, plazo_meses,
               cuota_monto, monto_total, fecha_inicio, fecha_vencimiento,
               tipo_frecuencia, tipo_amortizacion, periodo_tasa,
               estado, proposito, notas, creado_por)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?, 'activo',?,?,?)
        ")->execute([
            $clienteId, $codigo, $monto, $tasa, $cuotas,
            $cuotaMonto, $montoTotal, $fechaInicio, $fechaVenc,
            $tipo, $amort, $periodoTasa,
            $proposito, $notas, $usuario['id'],
        ]);

        $prestamoId = (int)$db->lastInsertId();

        // ── Generar cuotas ────────────────────────────────────
        $saldo    = $monto;
        $capFijo  = $amort === 'aleman' ? round($monto / $cuotas, 2) : 0;
        $dtCuota  = new DateTime($fechaInicio);

        for ($i = 1; $i <= $cuotas; $i++) {
            // Avanzar fecha
            if ($tipo === 'mensual' || $tipo === 'unico' || $tipo === 'custom') {
                $dtCuota->modify('+1 month');
            } else {
                $dtCuota->modify('+' . $diasPorPeriodo . ' days');
            }
            $fechaCuota = $dtCuota->format('Y-m-d');

            $interes = round($saldo * $tasaP, 2);

            switch ($amort) {
                case 'frances':
                    $capital  = round($cuotaMonto - $interes, 2);
                    $mCuota   = $cuotaMonto;
                    break;
                case 'aleman':
                    $capital  = $capFijo;
                    $mCuota   = round($capital + $interes, 2);
                    break;
                case 'americano':
                    $capital  = ($i === $cuotas) ? $saldo : 0; // capital al final
                    $mCuota   = round($interes + $capital, 2);
                    break;
                default:
                    $capital  = round($cuotaMonto - $interes, 2);
                    $mCuota   = $cuotaMonto;
            }

            // Ajuste última cuota por redondeo
            if ($i === $cuotas && $amort !== 'americano') {
                $capital = $saldo;
                $mCuota  = round($capital + $interes, 2);
            }

            $saldo = max(0, round($saldo - $capital, 2));

            $db->prepare("
                INSERT INTO cuotas
                  (prestamo_id, numero, fecha_vence, capital, interes,
                   monto_total, saldo_pendiente, estado)
                VALUES (?,?,?,?,?, ?,?,'pendiente')
            ")->execute([
                $prestamoId, $i, $fechaCuota, $capital, $interes,
                $mCuota, $saldo,
            ]);
        }

        $db->commit();

        // Devolver el préstamo creado
        $stmt = $db->prepare("
            SELECT p.*, CONCAT(c.nombre,' ',c.apellido) AS cliente
            FROM prestamos p JOIN clientes c ON c.id=p.cliente_id
            WHERE p.id=?
        ");
        $stmt->execute([$prestamoId]);
        jsonResponse($stmt->fetch(), 201);

    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('Error al crear el préstamo: ' . $e->getMessage());
    }
}

// ── PUT actualizar estado ────────────────────────────────────
function actualizarEstado(int $id, array $usuario): never {
    $rolesAdmin = ['superadmin','admin','gerente','supervisor'];
    if (!in_array($usuario['rol'], $rolesAdmin, true))
        jsonError('Sin permisos para modificar préstamos', 403);

    $data   = inputJSON();
    $estado = sanitizeStr($data['estado'] ?? '');
    $validos = ['activo','pagado','vencido','cancelado'];

    if (!in_array($estado, $validos, true)) jsonError('Estado inválido');

    $db   = getDB();
    $stmt = $db->prepare("UPDATE prestamos SET estado=?, actualizado_en=NOW() WHERE id=?");
    $stmt->execute([$estado, $id]);

    if ($stmt->rowCount() === 0) jsonError('Préstamo no encontrado', 404);

    $stGet = $db->prepare("
        SELECT p.*, CONCAT(c.nombre,' ',c.apellido) AS cliente
        FROM prestamos p JOIN clientes c ON c.id=p.cliente_id WHERE p.id=?
    ");
    $stGet->execute([$id]);
    jsonResponse($stGet->fetch());
}

// ── DELETE cancelar ──────────────────────────────────────────
function cancelarPrestamo(int $id, array $usuario): never {
    $rolesAdmin = ['superadmin','admin','gerente'];
    if (!in_array($usuario['rol'], $rolesAdmin, true))
        jsonError('Sin permisos para cancelar préstamos', 403);

    $db  = getDB();
    $chk = $db->prepare("SELECT id, estado FROM prestamos WHERE id=?");
    $chk->execute([$id]);
    $p   = $chk->fetch();

    if (!$p)                          jsonError('Préstamo no encontrado', 404);
    if ($p['estado'] === 'cancelado') jsonError('El préstamo ya está cancelado');
    if ($p['estado'] === 'pagado')    jsonError('No se puede cancelar un préstamo pagado');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE prestamos SET estado='cancelado', actualizado_en=NOW() WHERE id=?")
           ->execute([$id]);
        $db->prepare("UPDATE cuotas SET estado='cancelado' WHERE prestamo_id=? AND estado='pendiente'")
           ->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('Error al cancelar: ' . $e->getMessage());
    }

    jsonResponse(['mensaje' => 'Préstamo cancelado correctamente']);
}