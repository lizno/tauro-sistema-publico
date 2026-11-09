-- Foto de totales al cerrar una secuencia (control de caja por período).
CREATE TABLE IF NOT EXISTS `secuencias_cierre` (
  `id` int NOT NULL AUTO_INCREMENT,
  `secuencia_id` int NOT NULL,
  `fecha_cierre` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_facturas` int NOT NULL DEFAULT 0,
  `total_facturado` decimal(14,4) NOT NULL DEFAULT 0,
  `total_cobrado` decimal(14,4) NOT NULL DEFAULT 0,
  `total_reportado` decimal(14,4) NOT NULL DEFAULT 0,
  `total_pendiente` decimal(14,4) NOT NULL DEFAULT 0,
  `notas` text,
  `usuario_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_secuencia` (`secuencia_id`),
  CONSTRAINT `cierre_sec_fk` FOREIGN KEY (`secuencia_id`) REFERENCES `secuencias` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cierre_usr_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
