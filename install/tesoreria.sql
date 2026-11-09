-- Movimientos manuales de tesorería: entregas de dinero a vendedores,
-- gastos e ingresos que no vienen de una factura.
CREATE TABLE IF NOT EXISTS `tesoreria_movimientos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `tipo` enum('ingreso','egreso') COLLATE utf8mb4_unicode_ci NOT NULL,
  `categoria` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'otro',
  `vendedor_id` int DEFAULT NULL,
  `monto_usd` decimal(12,4) NOT NULL,
  `referencia` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notas` text COLLATE utf8mb4_unicode_ci,
  `usuario_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tesoreria_fecha` (`fecha`),
  KEY `idx_tesoreria_vendedor` (`vendedor_id`),
  CONSTRAINT `tesoreria_vendedor_fk` FOREIGN KEY (`vendedor_id`) REFERENCES `vendedores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tesoreria_usuario_fk` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
