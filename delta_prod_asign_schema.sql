-- ============================================================
-- BASE DE DATOS: Delta_prod_asign
-- Proyecto: Sistema de Gestión de Asignaciones FD
-- Fecha: 2026-04-28
-- ============================================================

CREATE DATABASE IF NOT EXISTS Delta_prod_asign
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_spanish_ci;

USE Delta_prod_asign;

-- ============================================================
-- TABLA: Usuarios
-- Estática. Roles: super, editor, observador
-- ============================================================
CREATE TABLE Usuarios (
    id_usu          VARCHAR(10)  NOT NULL,
    nombre_usu      VARCHAR(60)  NOT NULL,
    rol             ENUM('super','editor','observador') NOT NULL,
    area_asignada   VARCHAR(30)  DEFAULT NULL,
    clave_hash      VARCHAR(255) NOT NULL,          -- bcrypt hash
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login      DATETIME     DEFAULT NULL,
    PRIMARY KEY (id_usu)
) ENGINE=InnoDB;

-- Usuarios iniciales (contraseñas a cambiar en primer ingreso)
-- Hash de 'Cambiar1234!' con bcrypt cost 12
INSERT INTO Usuarios (id_usu, nombre_usu, rol, area_asignada, clave_hash) VALUES
('USR001', 'Administrador', 'super',      'Gerencia',   '$2y$12$placeholder_hash_super'),
('USR002', 'Editor1',       'editor',     'Produccion', '$2y$12$placeholder_hash_editor'),
('USR003', 'Observador1',   'observador', 'Calidad',    '$2y$12$placeholder_hash_obs');


-- ============================================================
-- TABLA: Sesiones
-- Manejo seguro de sesiones del lado servidor
-- ============================================================
CREATE TABLE Sesiones (
    token           CHAR(64)     NOT NULL,   -- SHA-256 hex
    id_usu          VARCHAR(10)  NOT NULL,
    ip_addr         VARCHAR(45)  NOT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME     NOT NULL,
    PRIMARY KEY (token),
    INDEX idx_usu   (id_usu),
    CONSTRAINT fk_ses_usu FOREIGN KEY (id_usu) REFERENCES Usuarios(id_usu)
) ENGINE=InnoDB;


-- ============================================================
-- TABLA: Componentes_STD
-- Estática. Componentes estándar por modelo de bomba.
-- Fuente: imagen CodigosPieza y hoja STOCK 090/100 del Excel.
-- ============================================================
CREATE TABLE Componentes(
    cod_pza             VARCHAR(20)  NOT NULL,   -- Ej: 39035, BAF-080/090_11
    nombre_componente   VARCHAR(60)  NOT NULL,   -- Ej: CABEZAL, CAMPANA
    nombre_pza          VARCHAR(100) DEFAULT NULL,
    descripcion         VARCHAR(150) DEFAULT NULL,
    ensamble            VARCHAR(50)  DEFAULT NULL,
    modelo              ENUM('BAF-080','BAF-090','BAF-100','BAF-112') NOT NULL,
    es_excepcion        TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 si el código se usa en otro modelo
    modelo_original     ENUM('BAF-080','BAF-090','BAF-100','BAF-112') DEFAULT NULL,
    PRIMARY KEY (cod_pza, modelo)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Datos BAF-080 (imagen CodigosPieza)
-- ---------------------------------------------------------------
INSERT INTO Componentes (cod_pza, nombre_componente, modelo) VALUES
('40584',          'ACOPLE SUPERIOR',         'BAF-080'),
('39035',          'CABEZAL',                 'BAF-080'),
('39037',          '(6) BRIDAS DE CUERPO',    'BAF-080'),
('39080',          'CAMPANA',                 'BAF-080'),
('39028',          'ANILLO DE DESGASTE',      'BAF-080'),
('39051',          'RODETE 2DA PARTE',        'BAF-080'),
('39026',          'DIFUSOR 2DA PARTE',       'BAF-080'),
('39029',          'CONO DIFUSOR',            'BAF-080'),
('39038',          'SOPORTE CENTRAL',         'BAF-080'),
('39056',          'CODO 3ERA PARTE',         'BAF-080'),
('38999',          'ALOJAMIENTO PRENSA ESTOPA','BAF-080'),
('BAF-080/090_11', '(11) PUNTA INFERIOR',     'BAF-080'),
('39086',          '(14) TUERCA INFERIOR DE BRONCE','BAF-080'),
('39030',          '(29) ALABES DE IMPULSOR', 'BAF-080'),
('39027',          '(74) JAULA INFERIOR',     'BAF-080'),
('39032',          '(75) JAULA PARTIDA',      'BAF-080'),
('39036',          '(76) TAPA PRENSA ESTOPA', 'BAF-080'),
('CSE080X500',     'CANASTILLA',              'BAF-080'),
('PASTILLAS_ENCASTRES_1"','PASTILLAS DE ENCASTRE','BAF-080');

-- ---------------------------------------------------------------
-- Datos BAF-090
-- ---------------------------------------------------------------
INSERT INTO Componentes (cod_pza, nombre_componente, modelo) VALUES
('40584',          'ACOPLE SUPERIOR',         'BAF-090'),
('38989',          'CABEZAL',                 'BAF-090'),
('39048',          '(6) BRIDAS DE CUERPO',    'BAF-090'),
('39044',          'CAMPANA',                 'BAF-090'),
('39046',          'ANILLO DE DESGASTE',      'BAF-090'),
('39034',          'RODETE 2DA PARTE',        'BAF-090'),
('38834',          'DIFUSOR 2DA PARTE',       'BAF-090'),
('38833',          'CONO DIFUSOR',            'BAF-090'),
('38988',          'SOPORTE CENTRAL',         'BAF-090'),
('38990',          'CODO 3ERA PARTE',         'BAF-090'),
('38999',          'ALOJAMIENTO PRENSA ESTOPA','BAF-090'),
('BAF-080/090_11', '(11) PUNTA INFERIOR',     'BAF-090'),
('38986',          '(14) TUERCA INFERIOR DE BRONCE','BAF-090'),
('39047',          '(29) ALABES IMPULSOR',    'BAF-090'),
('39027',          '(74) JAULA INFERIOR',     'BAF-090'),
('39032',          '(74) JAULA PARTIDA',      'BAF-090'),
('39036',          '(76) TAPA PRENSA ESTOPA', 'BAF-090'),
('CSE090X550',     'CANASTILLA',              'BAF-090'),
('PASTILLAS_ENCASTRES_1.5"','PASTILLAS DE ENCASTRE','BAF-090');

-- ---------------------------------------------------------------
-- Datos BAF-100
-- ---------------------------------------------------------------
INSERT INTO Componentes (cod_pza, nombre_componente, modelo) VALUES
('39223',          'ACOPLE SUPERIOR',         'BAF-100'),
('39214',          'CABEZAL',                 'BAF-100'),
('38844',          '(6) BRIDAS DE CUERPO',    'BAF-100'),
('38854',          'CAMPANA',                 'BAF-100'),
('39212',          'ANILLO DE DESGASTE',      'BAF-100'),
('39213',          'RODETE 2DA PARTE',        'BAF-100'),
('38857',          'DIFUSOR 2DA PARTE',       'BAF-100'),
('38856',          'CONO DIFUSOR',            'BAF-100'),
('38859',          'SOPORTE CENTRAL',         'BAF-100'),
('39216',          'CODO 3RA PARTE',          'BAF-100'),
('39215',          'ALOJAMIENTO PRENSA ESTOPA','BAF-100'),
('BAF-100/112_11', '(11) PUNTA INFERIOR',     'BAF-100'),
('39218',          '(14) TUERCA INFERIOR DE BRONCE','BAF-100'),
('39219',          '(29) ALABES IMPULSOR',    'BAF-100'),
('39220',          '(74) JAULA INFERIOR',     'BAF-100'),
('39221',          '(75) JAULA PARTIDA',      'BAF-100'),
('39222',          '(76) TAPA PRENSA ESTOPA', 'BAF-100'),
('CSE100X600',     'CANASTILLA',              'BAF-100'),
('PASTILLAS_ENCASTRES_1.5"','PASTILLAS DE ENCASTRE','BAF-100');

-- ---------------------------------------------------------------
-- Datos BAF-112
-- ---------------------------------------------------------------
INSERT INTO Componentes (cod_pza, nombre_componente, modelo) VALUES
('39223',          'ACOPLE SUPERIOR',         'BAF-112'),
('39288',          'CABEZAL',                 'BAF-112'),
('39291',          '(6) BRIDAS DE CUERPO',    'BAF-112'),
('39282',          'CAMPANA',                 'BAF-112'),
('39283',          'ANILLO DE DESGASTE',      'BAF-112'),
('39284',          'RODETE 2DA PARTE',        'BAF-112'),
('39285',          'DIFUSOR 2DA PARTE',       'BAF-112'),
('39286',          'CONO DIFUSOR',            'BAF-112'),
('39287',          'SOPORTE CENTRAL',         'BAF-112'),
('39289',          'CODO 3RA PARTE',          'BAF-112'),
('39215',          'ALOJAMIENTO PRENSA ESTOPA','BAF-112'),
('BAF-100/112_11', '(11) PUNTA INFERIOR',     'BAF-112'),
('39218',          '(14) TUERCA INFERIOR DE BRONCE','BAF-112'),
('39290',          '(29) ALABES IMPULSOR',    'BAF-112'),
('39220',          '(74) JAULA INFERIOR',     'BAF-112'),
('39221',          '(75) JAULA PARTIDA',      'BAF-112'),
('39222',          '(76) TAPA PRENSA ESTOPA', 'BAF-112'),
('CSE112X650',     'CANASTILLA',              'BAF-112'),
('PASTILLAS_ENCASTRES_1.5"','PASTILLAS DE ENCASTRE','BAF-112');


-- ============================================================
-- TABLA: Asignaciones_FD
-- Tabla principal de escritura. Lee OT_FD de JB_Delta,
-- el usuario ingresa plano_asignado y se graban los demás.
-- Una FD+componente puede tener hasta 2 filas (cant_fab = 2).
-- OT_FD = concatenación de FD + '_' + componente
-- ============================================================
CREATE TABLE Asignaciones_FD (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Identificadores clave
    OT_FD           VARCHAR(40)  NOT NULL,   -- FD + '_' + componente, leído de JB_Delta
    FD              VARCHAR(25)  NOT NULL,   -- Ej: FD2405A, FD2411B
    componente      VARCHAR(50)  NOT NULL,   -- Ej: CAMPANA, ANILLO DESG, DIFUSOR...
    cant_fab        TINYINT      NOT NULL DEFAULT 1 CHECK (cant_fab IN (1,2)),
    fila_num        TINYINT      NOT NULL DEFAULT 1 CHECK (fila_num IN (1,2)),
                                            -- 1 o 2 cuando cant_fab=2

    -- Datos del plano asignado (ingresados por el usuario)
    plano_asignado  VARCHAR(15)  DEFAULT NULL,  -- Ej: BA-1390, BM-178

    -- Datos derivados de JB_Delta (solo lectura en la interfaz)
    cod_pieza       VARCHAR(20)  DEFAULT NULL,
    status_OT       VARCHAR(20)  DEFAULT NULL,
    area_actual     VARCHAR(30)  DEFAULT NULL,
    status_tarea_actual VARCHAR(40) DEFAULT NULL,
    fecha_entrega   DATE         DEFAULT NULL,
    modelo_bomba    ENUM('BAF-080','BAF-090','BAF-100','BAF-112') DEFAULT NULL,

    -- Campo "Soldado" manejado por el usuario en la tabla de asignación
    soldado         TINYINT(1)   NOT NULL DEFAULT 0,
    comentario_soldado VARCHAR(255) DEFAULT NULL,

    -- Auditoría
    id_usu_creacion VARCHAR(10)  DEFAULT NULL,
    id_usu_ultima_mod VARCHAR(10) DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- Una combinación OT_FD + fila_num debe ser única
    UNIQUE KEY uq_otfd_fila (OT_FD, fila_num),

    INDEX idx_fd         (FD),
    INDEX idx_plano      (plano_asignado),
    INDEX idx_modelo     (modelo_bomba),
    INDEX idx_componente (componente),

    CONSTRAINT fk_asig_creacion FOREIGN KEY (id_usu_creacion)
        REFERENCES Usuarios(id_usu) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_asig_mod FOREIGN KEY (id_usu_ultima_mod)
        REFERENCES Usuarios(id_usu) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
-- TABLA: Historial_Fechas
-- Registra cambios de fecha_entrega en Planificación
-- para mostrar el histórico tachado (como en la imagen)
-- ============================================================
CREATE TABLE Historial_Fechas (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plano           VARCHAR(15)  NOT NULL,  -- plano de Info_Bombas
    fecha_anterior  DATE         NOT NULL,
    fecha_nueva     DATE         NOT NULL,
    id_usu          VARCHAR(10)  NOT NULL,
    cambiado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_plano_hist (plano),
    CONSTRAINT fk_hist_usu FOREIGN KEY (id_usu)
        REFERENCES Usuarios(id_usu) ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- TABLA: Desc_Personalizada
-- Permite que el usuario edite la descripción de una OT
-- sin tocar JB_Delta (lectura pura)
-- ============================================================
CREATE TABLE Desc_Personalizada (
    plano           VARCHAR(15)  NOT NULL,
    descripcion_usu VARCHAR(300) DEFAULT NULL,
    id_usu          VARCHAR(10)  DEFAULT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (plano),
    CONSTRAINT fk_desc_usu FOREIGN KEY (id_usu)
        REFERENCES Usuarios(id_usu) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- ============================================================
-- VISTA: v_asignaciones_completas
-- Une Asignaciones_FD con Componentes_STD para consultas
-- ============================================================
CREATE OR REPLACE VIEW v_asignaciones_completas AS
SELECT
    a.id,
    a.OT_FD,
    a.FD,
    a.componente,
    a.cant_fab,
    a.fila_num,
    a.plano_asignado,
    a.cod_pieza,
    a.status_OT,
    a.area_actual,
    a.status_tarea_actual,
    a.fecha_entrega,
    a.modelo_bomba,
    a.soldado,
    a.comentario_soldado,
    a.id_usu_creacion,
    a.id_usu_ultima_mod,
    a.created_at,
    a.updated_at,
    c.nombre_pza,
    c.descripcion     AS desc_componente,
    c.ensamble
FROM Asignaciones_FD a
LEFT JOIN Componentes_STD c
    ON a.cod_pieza = c.cod_pza
   AND a.modelo_bomba = c.modelo;


-- ============================================================
-- PERMISOS (ajustar usuario PHP según entorno)
-- ============================================================
-- CREATE USER IF NOT EXISTS 'delta_web'@'localhost' IDENTIFIED BY 'CAMBIAR_PASSWORD';
-- GRANT SELECT, INSERT, UPDATE ON Delta_prod_asign.* TO 'delta_web'@'localhost';
-- GRANT DELETE ON Delta_prod_asign.Sesiones TO 'delta_web'@'localhost';
-- FLUSH PRIVILEGES;