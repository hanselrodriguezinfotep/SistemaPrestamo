-- ============================================================
-- GestionPrestamo — Base de Datos Unificada
-- MySQL 5.4+ | InnoDB | utf8mb4
-- Filtrado global por id_centro
-- superadmin opera SIEMPRE sobre id_centro = 1
-- ============================================================
SET NAMES utf8mb4;
SET SESSION sql_mode = '';

CREATE DATABASE IF NOT EXISTS gestion_prestamo
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gestion_prestamo;

-- TABLA: empresa
CREATE TABLE IF NOT EXISTS empresa (
    id             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(150) NOT NULL,
    ruc            VARCHAR(30)      NULL,
    telefono       VARCHAR(20)      NULL,
    email          VARCHAR(150)     NULL,
    direccion      TEXT             NULL,
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa (id, nombre, activo) VALUES (1, 'Central / SuperAdmin', 1);

-- TABLA: usuarios
CREATE TABLE IF NOT EXISTS usuarios (
    id               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro        INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_persona       INT(11) UNSIGNED     NULL,
    username         VARCHAR(50)  NOT NULL,
    password         VARCHAR(255) NOT NULL,
    nombre           VARCHAR(100) NOT NULL,
    email            VARCHAR(150)     NULL,
    foto             VARCHAR(255)     NULL,
    rol              ENUM('superadmin','admin','cobrador') NOT NULL DEFAULT 'cobrador',
    activo           TINYINT(1)   NOT NULL DEFAULT 1,
    cambiar_password TINYINT(1)   NOT NULL DEFAULT 0,
    ultimo_login     DATETIME         NULL,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username_centro (username, id_centro),
    CONSTRAINT fk_usuarios_centro FOREIGN KEY (id_centro) REFERENCES empresa(id) ON DELETE RESTRICT,
    INDEX idx_rol    (rol),
    INDEX idx_activo (activo),
    INDEX idx_centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO usuarios (id_centro, username, password, nombre, rol) VALUES
(1,'superadmin','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Super Administrador','superadmin'),
(1,'admin',     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Administrador','admin');

-- TABLA: configuracion_empresa
CREATE TABLE IF NOT EXISTS configuracion_empresa (
    id              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro       INT(11) UNSIGNED     NULL,
    nombre_empresa  VARCHAR(150)     NULL,
    slogan          VARCHAR(255)     NULL,
    moneda          VARCHAR(10)  NOT NULL DEFAULT 'DOP',
    simbolo_moneda  VARCHAR(10)  NOT NULL DEFAULT 'RD$',
    pie_recibo      TEXT             NULL,
    logo_base64     MEDIUMTEXT       NULL,
    telefono        VARCHAR(20)      NULL,
    email           VARCHAR(150)     NULL,
    direccion       TEXT             NULL,
    rnc             VARCHAR(30)      NULL,
    color_primario  VARCHAR(20)  NOT NULL DEFAULT '#5b4ef8',
    smtp_host       VARCHAR(150)     NULL,
    smtp_port       SMALLINT UNSIGNED NULL,
    smtp_user       VARCHAR(150)     NULL,
    smtp_pass       VARCHAR(255)     NULL,
    smtp_from_name  VARCHAR(100)     NULL,
    smtp_from_email VARCHAR(150)     NULL,
    whatsapp_apikey VARCHAR(255)     NULL,
    debug_errors    TINYINT(1)   NOT NULL DEFAULT 0,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_centro (id_centro),
    CONSTRAINT fk_cfg_centro FOREIGN KEY (id_centro) REFERENCES empresa(id) ON DELETE CASCADE,
    INDEX idx_centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_empresa (id_centro, nombre_empresa, slogan, moneda, simbolo_moneda, color_primario)
VALUES (1,'GestionPrestamo','Tu sistema de prestamos de confianza','DOP','RD$','#5b4ef8');

-- TABLA: personas
CREATE TABLE IF NOT EXISTS personas (
    id               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro        INT(11) UNSIGNED NOT NULL DEFAULT 1,
    nombre           VARCHAR(100) NOT NULL,
    apellido         VARCHAR(100) NOT NULL,
    cedula           VARCHAR(30)      NULL,
    telefono         VARCHAR(20)      NULL,
    email            VARCHAR(150)     NULL,
    direccion        TEXT             NULL,
    tipo_persona     ENUM('cliente','garante','empleado') NOT NULL DEFAULT 'cliente',
    genero           ENUM('M','F','otro') NULL,
    fecha_nacimiento DATE             NULL,
    latitud          DECIMAL(10,8)    NULL,
    longitud         DECIMAL(11,8)    NULL,
    foto             VARCHAR(255)     NULL,
    notas            TEXT             NULL,
    activo           TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cedula_centro (cedula, id_centro),
    CONSTRAINT fk_personas_centro FOREIGN KEY (id_centro) REFERENCES empresa(id) ON DELETE RESTRICT,
    INDEX idx_activo (activo),
    INDEX idx_centro (id_centro),
    FULLTEXT idx_ft (nombre, apellido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: planes_prestamo
CREATE TABLE IF NOT EXISTS planes_prestamo (
    id             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro      INT(11) UNSIGNED     NULL,
    nombre         VARCHAR(100) NOT NULL,
    descripcion    TEXT             NULL,
    tasa_interes   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    tipo_tasa      ENUM('fija','variable') NOT NULL DEFAULT 'fija',
    tipo_amort     ENUM('frances','aleman','americano') NOT NULL DEFAULT 'frances',
    plazo_min      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    plazo_max      SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    monto_min      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_max      DECIMAL(12,2) NOT NULL DEFAULT 999999.99,
    frecuencia     ENUM('diario','semanal','quincenal','mensual') NOT NULL DEFAULT 'mensual',
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_centro FOREIGN KEY (id_centro) REFERENCES empresa(id) ON DELETE RESTRICT,
    INDEX idx_centro (id_centro),
    INDEX idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: prestamos
CREATE TABLE IF NOT EXISTS prestamos (
    id                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro         INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_persona        INT(11) UNSIGNED NOT NULL,
    id_plan           INT(11) UNSIGNED     NULL,
    codigo            VARCHAR(20)  NOT NULL,
    monto_capital     DECIMAL(12,2) NOT NULL,
    tasa_interes      DECIMAL(5,2)  NOT NULL,
    tipo_tasa         ENUM('fija','variable') NOT NULL DEFAULT 'fija',
    plazo_cuotas      SMALLINT UNSIGNED NOT NULL,
    frecuencia        ENUM('diario','semanal','quincenal','mensual') NOT NULL DEFAULT 'mensual',
    cuota_monto       DECIMAL(12,2) NOT NULL,
    monto_total       DECIMAL(12,2) NOT NULL,
    total_pagado      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fecha_inicio      DATE         NOT NULL,
    fecha_vencimiento DATE         NOT NULL,
    estado            ENUM('activo','pagado','vencido','cancelado') NOT NULL DEFAULT 'activo',
    proposito         VARCHAR(255)     NULL,
    notas             TEXT             NULL,
    creado_por        INT(11) UNSIGNED NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_codigo_centro (codigo, id_centro),
    CONSTRAINT fk_prest_persona FOREIGN KEY (id_persona) REFERENCES personas(id)       ON DELETE RESTRICT,
    CONSTRAINT fk_prest_plan    FOREIGN KEY (id_plan)    REFERENCES planes_prestamo(id) ON DELETE SET NULL,
    CONSTRAINT fk_prest_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id)        ON DELETE SET NULL,
    CONSTRAINT fk_prest_centro  FOREIGN KEY (id_centro)  REFERENCES empresa(id)         ON DELETE RESTRICT,
    INDEX idx_persona (id_persona),
    INDEX idx_estado  (estado),
    INDEX idx_centro  (id_centro),
    INDEX idx_f_ini   (fecha_inicio),
    INDEX idx_f_ven   (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: cuotas
CREATE TABLE IF NOT EXISTS cuotas (
    id               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro        INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_prestamo      INT(11) UNSIGNED NOT NULL,
    numero           SMALLINT UNSIGNED NOT NULL,
    fecha_vence      DATE         NOT NULL,
    capital          DECIMAL(12,2) NOT NULL,
    interes          DECIMAL(12,2) NOT NULL,
    monto_total      DECIMAL(12,2) NOT NULL,
    saldo_pendiente  DECIMAL(12,2) NOT NULL,
    estado           ENUM('pendiente','pagado','vencido','mora') NOT NULL DEFAULT 'pendiente',
    fecha_pago_real  DATE             NULL,
    UNIQUE KEY uq_cuota (id_prestamo, numero),
    CONSTRAINT fk_cuota_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos(id) ON DELETE CASCADE,
    CONSTRAINT fk_cuota_centro   FOREIGN KEY (id_centro)   REFERENCES empresa(id)   ON DELETE RESTRICT,
    INDEX idx_estado (estado),
    INDEX idx_centro (id_centro),
    INDEX idx_fecha  (fecha_vence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: pagos
CREATE TABLE IF NOT EXISTS pagos (
    id               INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro        INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_prestamo      INT(11) UNSIGNED NOT NULL,
    id_cuota         INT(11) UNSIGNED     NULL,
    monto            DECIMAL(12,2) NOT NULL,
    metodo_pago      ENUM('efectivo','transferencia','cheque','tarjeta') NOT NULL DEFAULT 'efectivo',
    referencia       VARCHAR(100)     NULL,
    notas            TEXT             NULL,
    registrado_por   INT(11) UNSIGNED NULL,
    anulado          TINYINT(1)   NOT NULL DEFAULT 0,
    motivo_anulacion VARCHAR(255)     NULL,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pago_prestamo FOREIGN KEY (id_prestamo)   REFERENCES prestamos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pago_cuota    FOREIGN KEY (id_cuota)      REFERENCES cuotas(id)    ON DELETE SET NULL,
    CONSTRAINT fk_pago_cobrador FOREIGN KEY (registrado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_pago_centro   FOREIGN KEY (id_centro)     REFERENCES empresa(id)   ON DELETE RESTRICT,
    INDEX idx_prestamo (id_prestamo),
    INDEX idx_fecha    (creado_en),
    INDEX idx_anulado  (anulado),
    INDEX idx_centro   (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: mora_registros
CREATE TABLE IF NOT EXISTS mora_registros (
    id           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro    INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_prestamo  INT(11) UNSIGNED NOT NULL,
    id_cuota     INT(11) UNSIGNED NOT NULL,
    dias_mora    INT(11) NOT NULL DEFAULT 0,
    tasa_mora    DECIMAL(5,2)     NULL,
    monto_mora   DECIMAL(12,2)    NULL,
    estado       ENUM('pendiente','cobrado','exonerado') NOT NULL DEFAULT 'pendiente',
    generado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mora_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos(id) ON DELETE CASCADE,
    CONSTRAINT fk_mora_cuota    FOREIGN KEY (id_cuota)    REFERENCES cuotas(id)    ON DELETE CASCADE,
    CONSTRAINT fk_mora_centro   FOREIGN KEY (id_centro)   REFERENCES empresa(id)   ON DELETE RESTRICT,
    INDEX idx_centro   (id_centro),
    INDEX idx_prestamo (id_prestamo),
    INDEX idx_estado   (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: rutas_cobranza
CREATE TABLE IF NOT EXISTS rutas_cobranza (
    id             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro      INT(11) UNSIGNED NOT NULL DEFAULT 1,
    nombre         VARCHAR(100) NOT NULL,
    descripcion    TEXT             NULL,
    cobrador_id    INT(11) UNSIGNED NULL,
    dia_cobranza   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    activa         TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ruta_cobrador FOREIGN KEY (cobrador_id) REFERENCES usuarios(id)  ON DELETE SET NULL,
    CONSTRAINT fk_ruta_centro   FOREIGN KEY (id_centro)   REFERENCES empresa(id)   ON DELETE RESTRICT,
    INDEX idx_cobrador (cobrador_id),
    INDEX idx_activa   (activa),
    INDEX idx_centro   (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: ruta_clientes
CREATE TABLE IF NOT EXISTS ruta_clientes (
    id          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro   INT(11) UNSIGNED NOT NULL DEFAULT 1,
    ruta_id     INT(11) UNSIGNED NOT NULL,
    cliente_id  INT(11) UNSIGNED NOT NULL,
    orden       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_ruta_cliente (ruta_id, cliente_id),
    CONSTRAINT fk_rc_ruta   FOREIGN KEY (ruta_id)    REFERENCES rutas_cobranza(id) ON DELETE CASCADE,
    CONSTRAINT fk_rc_client FOREIGN KEY (cliente_id) REFERENCES personas(id)       ON DELETE CASCADE,
    CONSTRAINT fk_rc_centro FOREIGN KEY (id_centro)  REFERENCES empresa(id)        ON DELETE RESTRICT,
    INDEX idx_ruta    (ruta_id),
    INDEX idx_cliente (cliente_id),
    INDEX idx_centro  (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: visitas
CREATE TABLE IF NOT EXISTS visitas (
    id            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro     INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_prestamo   INT(11) UNSIGNED NOT NULL,
    cobrador_id   INT(11) UNSIGNED NOT NULL,
    fecha         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resultado     ENUM('cobro_exitoso','no_encontrado','promesa_pago','rehusa_pagar','otro') NOT NULL DEFAULT 'otro',
    monto_cobrado DECIMAL(12,2)    NULL,
    notas         TEXT             NULL,
    latitud       DECIMAL(10,8)    NULL,
    longitud      DECIMAL(11,8)    NULL,
    CONSTRAINT fk_vis_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vis_cobrador FOREIGN KEY (cobrador_id) REFERENCES usuarios(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_vis_centro   FOREIGN KEY (id_centro)   REFERENCES empresa(id)   ON DELETE RESTRICT,
    INDEX idx_prestamo (id_prestamo),
    INDEX idx_cobrador (cobrador_id),
    INDEX idx_centro   (id_centro),
    INDEX idx_fecha    (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: audit_log
CREATE TABLE IF NOT EXISTS audit_log (
    id         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro  INT(11) UNSIGNED     NULL,
    id_usuario INT(11) UNSIGNED     NULL,
    accion     VARCHAR(100) NOT NULL,
    detalle    TEXT             NULL,
    ip         VARCHAR(45)      NULL,
    user_agent VARCHAR(300)     NULL,
    exitoso    TINYINT(1)   NOT NULL DEFAULT 1,
    fecha      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_centro  FOREIGN KEY (id_centro)  REFERENCES empresa(id)  ON DELETE SET NULL,
    CONSTRAINT fk_audit_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_centro  (id_centro),
    INDEX idx_usuario (id_usuario),
    INDEX idx_accion  (accion),
    INDEX idx_fecha   (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: persona_notif_prefs
CREATE TABLE IF NOT EXISTS persona_notif_prefs (
    id          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro   INT(11) UNSIGNED NOT NULL DEFAULT 1,
    id_persona  INT(11) UNSIGNED NOT NULL,
    email       VARCHAR(150)     NULL,
    whatsapp    VARCHAR(20)      NULL,
    canal       ENUM('email','whatsapp','ambos','ninguno') NOT NULL DEFAULT 'ninguno',
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_pref (id_persona, id_centro),
    CONSTRAINT fk_pref_persona FOREIGN KEY (id_persona) REFERENCES personas(id) ON DELETE CASCADE,
    CONSTRAINT fk_pref_centro  FOREIGN KEY (id_centro)  REFERENCES empresa(id)  ON DELETE CASCADE,
    INDEX idx_centro  (id_centro),
    INDEX idx_persona (id_persona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: callmebot_numeros
CREATE TABLE IF NOT EXISTS callmebot_numeros (
    id          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_centro   INT(11) UNSIGNED NOT NULL DEFAULT 1,
    phone       VARCHAR(20)  NOT NULL,
    descripcion VARCHAR(100)     NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_phone_centro (id_centro, phone),
    CONSTRAINT fk_cmb_centro FOREIGN KEY (id_centro) REFERENCES empresa(id) ON DELETE CASCADE,
    INDEX idx_centro (id_centro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VISTAS
-- ============================================================
CREATE OR REPLACE VIEW vista_prestamos_resumen AS
SELECT p.id, p.id_centro, p.codigo,
    CONCAT(pe.nombre,' ',pe.apellido) AS cliente,
    pe.cedula AS documento, pe.telefono,
    p.monto_capital, p.tasa_interes, p.plazo_cuotas, p.frecuencia,
    p.cuota_monto, p.monto_total, p.total_pagado,
    ROUND(p.monto_total - p.total_pagado, 2) AS saldo_pendiente,
    p.fecha_inicio, p.fecha_vencimiento, p.estado, p.proposito,
    (SELECT COUNT(*) FROM cuotas c WHERE c.id_prestamo=p.id AND c.estado!='pagado') AS cuotas_pendientes,
    (SELECT COUNT(*) FROM cuotas c WHERE c.id_prestamo=p.id AND c.estado='pagado')  AS cuotas_pagadas
FROM prestamos p JOIN personas pe ON p.id_persona = pe.id;

CREATE OR REPLACE VIEW vista_cuotas_vencidas AS
SELECT c.id AS cuota_id, c.id_centro, c.id_prestamo,
    p.codigo AS codigo_prestamo,
    CONCAT(pe.nombre,' ',pe.apellido) AS cliente, pe.telefono,
    c.numero, c.fecha_vence, c.monto_total AS monto_cuota,
    DATEDIFF(CURDATE(), c.fecha_vence) AS dias_vencida
FROM cuotas c
JOIN prestamos p  ON c.id_prestamo = p.id
JOIN personas  pe ON p.id_persona  = pe.id
WHERE c.estado != 'pagado' AND c.fecha_vence < CURDATE() AND p.estado = 'activo'
ORDER BY c.fecha_vence ASC;

CREATE OR REPLACE VIEW vista_mora AS
SELECT p.id_centro, p.id AS prestamo_id, p.codigo,
    CONCAT(pe.nombre,' ',pe.apellido) AS cliente, pe.telefono,
    SUM(c.monto_total) AS monto_mora, COUNT(c.id) AS cuotas_vencidas,
    MIN(c.fecha_vence) AS primera_cuota_vencida,
    MAX(DATEDIFF(CURDATE(), c.fecha_vence)) AS dias_max_mora
FROM prestamos p
JOIN personas pe ON p.id_persona  = pe.id
JOIN cuotas   c  ON c.id_prestamo = p.id
WHERE c.estado != 'pagado' AND c.fecha_vence < CURDATE() AND p.estado = 'activo'
GROUP BY p.id, p.id_centro, p.codigo, pe.nombre, pe.apellido, pe.telefono;

-- ============================================================
-- STORED PROCEDURES
-- ============================================================

DELIMITER $$

CREATE PROCEDURE sp_registrar_pago(
    IN  p_id_centro    INT UNSIGNED,
    IN  p_id_prestamo  INT UNSIGNED,
    IN  p_numero       SMALLINT UNSIGNED,
    IN  p_monto        DECIMAL(12,2),
    IN  p_metodo       VARCHAR(20),
    IN  p_referencia   VARCHAR(100),
    IN  p_usuario_id   INT UNSIGNED,
    IN  p_notas        TEXT,
    OUT p_resultado    VARCHAR(50)
)
sp_block: BEGIN
    DECLARE v_cuota_id    INT UNSIGNED;
    DECLARE v_estado_c    VARCHAR(20);
    DECLARE v_centro_pr   INT UNSIGNED;
    DECLARE v_total_c     INT;
    DECLARE v_pagadas     INT;

    SELECT id_centro INTO v_centro_pr FROM prestamos WHERE id = p_id_prestamo;
    IF v_centro_pr IS NULL OR v_centro_pr != p_id_centro THEN
        SET p_resultado = 'ERROR:CENTRO_NO_AUTORIZADO'; LEAVE sp_block;
    END IF;

    SELECT id, estado INTO v_cuota_id, v_estado_c
    FROM cuotas WHERE id_prestamo = p_id_prestamo AND numero = p_numero LIMIT 1;

    IF v_cuota_id IS NULL THEN
        SET p_resultado = 'ERROR:CUOTA_NO_ENCONTRADA'; LEAVE sp_block;
    END IF;
    IF v_estado_c = 'pagado' THEN
        SET p_resultado = 'ERROR:CUOTA_YA_PAGADA'; LEAVE sp_block;
    END IF;

    INSERT INTO pagos (id_centro, id_prestamo, id_cuota, monto, metodo_pago, referencia, notas, registrado_por)
    VALUES (p_id_centro, p_id_prestamo, v_cuota_id, p_monto, p_metodo, p_referencia, p_notas, p_usuario_id);

    UPDATE cuotas SET estado='pagado', fecha_pago_real=CURDATE() WHERE id = v_cuota_id;
    UPDATE prestamos SET total_pagado = total_pagado + p_monto WHERE id = p_id_prestamo;

    SELECT COUNT(*) INTO v_total_c FROM cuotas WHERE id_prestamo = p_id_prestamo;
    SELECT COUNT(*) INTO v_pagadas FROM cuotas WHERE id_prestamo = p_id_prestamo AND estado='pagado';
    IF v_pagadas = v_total_c THEN
        UPDATE prestamos SET estado='pagado' WHERE id = p_id_prestamo;
    END IF;
    SET p_resultado = 'OK';
END$$

CREATE PROCEDURE sp_crear_prestamo(
    IN  p_id_centro    INT UNSIGNED,
    IN  p_id_persona   INT UNSIGNED,
    IN  p_id_plan      INT UNSIGNED,
    IN  p_monto        DECIMAL(12,2),
    IN  p_tasa_anual   DECIMAL(5,2),
    IN  p_plazo        SMALLINT UNSIGNED,
    IN  p_frecuencia   VARCHAR(20),
    IN  p_fecha_inicio DATE,
    IN  p_proposito    VARCHAR(255),
    IN  p_notas        TEXT,
    IN  p_usuario_id   INT UNSIGNED,
    OUT p_prestamo_id  INT UNSIGNED,
    OUT p_codigo       VARCHAR(20)
)
BEGIN
    DECLARE v_tasa  DECIMAL(15,10);
    DECLARE v_cuota DECIMAL(12,2);
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_saldo DECIMAL(12,2);
    DECLARE v_cap   DECIMAL(12,2);
    DECLARE v_int   DECIMAL(12,2);
    DECLARE v_fecha DATE;
    DECLARE v_i     SMALLINT DEFAULT 1;
    DECLARE v_dias  INT;
    DECLARE v_seq   INT;
    DECLARE v_year  INT;

    SET v_year = YEAR(p_fecha_inicio);
    SELECT COALESCE(MAX(CAST(SUBSTRING(codigo,12) AS UNSIGNED)),0)+1
    INTO v_seq FROM prestamos
    WHERE id_centro=p_id_centro AND codigo LIKE CONCAT('PREST-',v_year,'-%');
    SET p_codigo = CONCAT('PREST-',v_year,'-',LPAD(v_seq,4,'0'));

    CASE p_frecuencia
        WHEN 'mensual'   THEN SET v_dias=30;
        WHEN 'quincenal' THEN SET v_dias=15;
        WHEN 'semanal'   THEN SET v_dias=7;
        WHEN 'diario'    THEN SET v_dias=1;
        ELSE                  SET v_dias=30;
    END CASE;

    IF p_tasa_anual = 0 THEN
        SET v_cuota = ROUND(p_monto/p_plazo,2);
    ELSE
        SET v_tasa  = (p_tasa_anual/100)*v_dias/365;
        SET v_cuota = ROUND(p_monto*(v_tasa*POW(1+v_tasa,p_plazo))/(POW(1+v_tasa,p_plazo)-1),2);
    END IF;
    SET v_total = ROUND(v_cuota*p_plazo,2);

    INSERT INTO prestamos
        (id_centro,id_persona,id_plan,codigo,monto_capital,tasa_interes,
         plazo_cuotas,frecuencia,cuota_monto,monto_total,
         fecha_inicio,fecha_vencimiento,proposito,notas,creado_por)
    VALUES
        (p_id_centro,p_id_persona,IF(p_id_plan=0,NULL,p_id_plan),p_codigo,p_monto,p_tasa_anual,
         p_plazo,p_frecuencia,v_cuota,v_total,
         p_fecha_inicio,DATE_ADD(p_fecha_inicio,INTERVAL (v_dias*p_plazo) DAY),
         p_proposito,p_notas,p_usuario_id);
    SET p_prestamo_id = LAST_INSERT_ID();

    SET v_saldo=p_monto; SET v_i=1;
    WHILE v_i<=p_plazo DO
        SET v_fecha=DATE_ADD(p_fecha_inicio,INTERVAL (v_dias*v_i) DAY);
        IF p_tasa_anual=0 THEN
            SET v_int=0; SET v_cap=v_cuota;
        ELSE
            SET v_int=ROUND(v_saldo*v_tasa,2);
            SET v_cap=ROUND(v_cuota-v_int,2);
        END IF;
        IF v_i=p_plazo THEN SET v_cap=v_saldo; SET v_int=ROUND(v_cuota-v_cap,2); END IF;
        SET v_saldo=ROUND(v_saldo-v_cap,2);
        INSERT INTO cuotas
            (id_centro,id_prestamo,numero,fecha_vence,capital,interes,monto_total,saldo_pendiente)
        VALUES
            (p_id_centro,p_prestamo_id,v_i,v_fecha,v_cap,v_int,v_cuota,v_saldo);
        SET v_i=v_i+1;
    END WHILE;
END$$

CREATE PROCEDURE sp_actualizar_vencidos(IN p_id_centro INT UNSIGNED)
BEGIN
    UPDATE cuotas c
    JOIN prestamos p ON c.id_prestamo=p.id
    SET c.estado='vencido'
    WHERE c.estado='pendiente' AND c.fecha_vence<CURDATE()
      AND p.estado='activo'
      AND (p.id_centro=p_id_centro OR p_id_centro=1);

    UPDATE prestamos p SET p.estado='vencido', p.actualizado_en=NOW()
    WHERE p.estado='activo'
      AND (p.id_centro=p_id_centro OR p_id_centro=1)
      AND EXISTS (SELECT 1 FROM cuotas c WHERE c.id_prestamo=p.id AND c.estado='vencido');
END$$

DELIMITER ;
