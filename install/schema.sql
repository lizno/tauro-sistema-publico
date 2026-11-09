-- TAURO Import Motor's C.A. - esquema de base de datos
-- Solo estructura, sin datos. Version publica de demostracion.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- CREATE DATABASE IF NOT EXISTS taurosystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE taurosystem;

DROP TABLE IF EXISTS `auditoria`;
CREATE TABLE `auditoria` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accion` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modulo` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registro_id` int DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `datos_anteriores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `datos_nuevos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `auditoria_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `auditoria_chk_1` CHECK (json_valid(`datos_anteriores`)),
  CONSTRAINT `auditoria_chk_2` CHECK (json_valid(`datos_nuevos`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `categorias`;
CREATE TABLE `categorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `departamento_id` int DEFAULT '1',
  `activo` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `departamento_id` (`departamento_id`),
  CONSTRAINT `categorias_ibfk_1` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `clientes`;
CREATE TABLE `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rif` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` text COLLATE utf8mb4_unicode_ci,
  `zona_id` int DEFAULT NULL,
  `vendedor_id` int DEFAULT NULL,
  `tipo_default` enum('mayorista','detalista') COLLATE utf8mb4_unicode_ci DEFAULT 'detalista',
  `limite_credito` decimal(12,4) DEFAULT '0.0000',
  `saldo_pendiente` decimal(12,4) DEFAULT '0.0000',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `zona_id` (`zona_id`),
  KEY `vendedor_id` (`vendedor_id`),
  CONSTRAINT `clientes_ibfk_1` FOREIGN KEY (`zona_id`) REFERENCES `zonas` (`id`),
  CONSTRAINT `clientes_ibfk_2` FOREIGN KEY (`vendedor_id`) REFERENCES `vendedores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cobros_descuentos`;
CREATE TABLE `cobros_descuentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `pago_id` int DEFAULT NULL,
  `venta_id` int NOT NULL,
  `descuento_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `monto_bruto_usd` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `monto_desc_usd` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `monto_neto_usd` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_cd_cli` (`cliente_id`),
  KEY `fk_cd_pago` (`pago_id`),
  KEY `fk_cd_venta` (`venta_id`),
  KEY `fk_cd_usr` (`usuario_id`),
  CONSTRAINT `fk_cd_cli` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cd_pago` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cd_usr` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cd_venta` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `comisiones_vendedor`;
CREATE TABLE `comisiones_vendedor` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendedor_id` int NOT NULL,
  `secuencia_id` int NOT NULL,
  `fecha` date NOT NULL,
  `monto_usd` decimal(12,4) NOT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendedor_id` (`vendedor_id`),
  KEY `secuencia_id` (`secuencia_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `comisiones_vendedor_ibfk_1` FOREIGN KEY (`vendedor_id`) REFERENCES `vendedores` (`id`),
  CONSTRAINT `comisiones_vendedor_ibfk_2` FOREIGN KEY (`secuencia_id`) REFERENCES `secuencias` (`id`),
  CONSTRAINT `comisiones_vendedor_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `compra_detalles`;
CREATE TABLE `compra_detalles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `compra_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_usd` decimal(10,4) DEFAULT '0.0000',
  `monto_usd` decimal(12,4) DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `compra_id` (`compra_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `compra_detalles_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `compra_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `compras`;
CREATE TABLE `compras` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `proveedor_id` int DEFAULT NULL,
  `fecha` date NOT NULL,
  `total_usd` decimal(12,4) DEFAULT '0.0000',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `estado` enum('recibido','parcial','pendiente') COLLATE utf8mb4_unicode_ci DEFAULT 'recibido',
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`),
  CONSTRAINT `compras_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `configuracion`;
CREATE TABLE `configuracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `clave` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `valor` text COLLATE utf8mb4_general_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `usuario_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clave` (`clave`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `configuracion_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `departamentos`;
CREATE TABLE `departamentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `color` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '#c0392b',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `devolucion_detalles`;
CREATE TABLE `devolucion_detalles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `devolucion_id` int NOT NULL,
  `producto_id` int DEFAULT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_usd` decimal(10,4) NOT NULL,
  `monto_usd` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `devolucion_id` (`devolucion_id`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `devolucion_detalles_ibfk_1` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `devolucion_detalles_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `devoluciones`;
CREATE TABLE `devoluciones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `venta_id` int DEFAULT NULL,
  `cliente_id` int DEFAULT NULL,
  `fecha` date NOT NULL,
  `motivo` text COLLATE utf8mb4_unicode_ci,
  `estado` enum('pendiente','aprobada','rechazada') COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  `total_usd` decimal(12,4) DEFAULT '0.0000',
  `usuario_id` int DEFAULT NULL,
  `aprobado_por` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `aprobado_por` (`aprobado_por`),
  CONSTRAINT `devoluciones_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `devoluciones_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `devoluciones_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `devoluciones_ibfk_4` FOREIGN KEY (`aprobado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documento_historial`;
CREATE TABLE `documento_historial` (
  `id` int NOT NULL AUTO_INCREMENT,
  `venta_id` int NOT NULL,
  `estado_desde` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `estado_hasta` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tipo_desde` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tipo_hasta` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `stock_afectado` tinyint(1) DEFAULT '0',
  `nota` text COLLATE utf8mb4_general_ci,
  `usuario_id` int DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `documento_historial_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `documento_historial_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `jhon_descuentos`;
CREATE TABLE `jhon_descuentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `descuento_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jhon_cli` (`cliente_id`),
  KEY `fk_jhond_usr` (`usuario_id`),
  CONSTRAINT `fk_jhond_cli` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jhond_usr` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jhon_grupos`;
CREATE TABLE `jhon_grupos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `nombre` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_jhong_cli` (`cliente_id`),
  KEY `fk_jhong_usr` (`usuario_id`),
  CONSTRAINT `fk_jhong_cli` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jhong_usr` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jhon_grupos_items`;
CREATE TABLE `jhon_grupos_items` (
  `grupo_id` int NOT NULL,
  `venta_id` int NOT NULL,
  PRIMARY KEY (`grupo_id`,`venta_id`),
  KEY `fk_jhongi_v` (`venta_id`),
  CONSTRAINT `fk_jhongi_g` FOREIGN KEY (`grupo_id`) REFERENCES `jhon_grupos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_jhongi_v` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pagos`;
CREATE TABLE `pagos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `venta_id` int NOT NULL,
  `monto_usd` decimal(12,4) DEFAULT NULL,
  `monto_bs` decimal(15,2) DEFAULT NULL,
  `moneda` enum('USD','BS') COLLATE utf8mb4_unicode_ci DEFAULT 'USD',
  `tipo` enum('efectivo','transferencia','punto','zelle','binance','wally','zinli','otro') COLLATE utf8mb4_unicode_ci NOT NULL,
  `referencia` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha` date NOT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `grupo_ref` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anulado` tinyint(1) NOT NULL DEFAULT '0',
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `venta_id` (`venta_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `idx_pagos_grupo` (`grupo_ref`),
  KEY `idx_pagos_anulado` (`anulado`),
  KEY `idx_pagos_venta_fecha` (`venta_id`,`fecha`),
  CONSTRAINT `pagos_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `pagos_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pagos_vendedor`;
CREATE TABLE `pagos_vendedor` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendedor_id` int NOT NULL,
  `secuencia_id` int NOT NULL,
  `fecha` date NOT NULL,
  `monto_usd` decimal(12,4) NOT NULL,
  `tipo` enum('efectivo','transferencia','punto','zelle','binance','wally','zinli','otro') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'efectivo',
  `moneda` enum('USD','BS') COLLATE utf8mb4_unicode_ci DEFAULT 'USD',
  `tasa_cobro` decimal(15,4) DEFAULT NULL,
  `monto_bs` decimal(15,2) DEFAULT NULL,
  `referencia` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendedor_id` (`vendedor_id`),
  KEY `secuencia_id` (`secuencia_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `pagos_vendedor_ibfk_1` FOREIGN KEY (`vendedor_id`) REFERENCES `vendedores` (`id`),
  CONSTRAINT `pagos_vendedor_ibfk_2` FOREIGN KEY (`secuencia_id`) REFERENCES `secuencias` (`id`),
  CONSTRAINT `pagos_vendedor_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `precios`;
CREATE TABLE `precios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto_id` int NOT NULL,
  `tipo` enum('mayorista','detalista') COLLATE utf8mb4_unicode_ci NOT NULL,
  `precio_usd` decimal(10,2) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `usuario_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_prod_tipo` (`producto_id`,`tipo`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `precios_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  CONSTRAINT `precios_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `productos`;
CREATE TABLE `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imagen` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categoria_id` int DEFAULT NULL,
  `unidad` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'PC',
  `bulto` int DEFAULT '1',
  `stock_actual` decimal(10,2) DEFAULT '0.00',
  `stock_minimo` decimal(10,2) DEFAULT '5.00',
  `departamento_id` int DEFAULT '1',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `categoria_id` (`categoria_id`),
  KEY `departamento_id` (`departamento_id`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `proveedores`;
CREATE TABLE `proveedores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rif` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacto` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pais` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Venezuela',
  `direccion` text COLLATE utf8mb4_unicode_ci,
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb4_unicode_ci,
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`),
  CONSTRAINT `roles_chk_1` CHECK (json_valid(`permisos`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `secuencias`;
CREATE TABLE `secuencias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha_ini` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estado` enum('activa','cerrada') COLLATE utf8mb4_unicode_ci DEFAULT 'activa',
  `notas` text COLLATE utf8mb4_unicode_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `secuencias_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `tasas_cambio`;
CREATE TABLE `tasas_cambio` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tasa_bs` decimal(15,4) NOT NULL,
  `fecha` date NOT NULL,
  `nota` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `tasas_cambio_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `apellido` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_perfil` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rol_id` int NOT NULL,
  `departamento_id` int DEFAULT '1',
  `activo` tinyint(1) DEFAULT '1',
  `ultimo_acceso` timestamp NULL DEFAULT NULL,
  `failed_attempts` int DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `rol_id` (`rol_id`),
  KEY `departamento_id` (`departamento_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `usuarios_ibfk_2` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vendedores`;
CREATE TABLE `vendedores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `nombre` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cedula` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zona_id` int DEFAULT NULL,
  `comision_pct` decimal(5,2) DEFAULT '0.00',
  `tipo_preferencia` enum('mayorista','detalista','ambos') COLLATE utf8mb4_unicode_ci DEFAULT 'ambos',
  `departamento_id` int DEFAULT '1',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `zona_id` (`zona_id`),
  KEY `departamento_id` (`departamento_id`),
  CONSTRAINT `vendedores_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `vendedores_ibfk_2` FOREIGN KEY (`zona_id`) REFERENCES `zonas` (`id`),
  CONSTRAINT `vendedores_ibfk_3` FOREIGN KEY (`departamento_id`) REFERENCES `departamentos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;