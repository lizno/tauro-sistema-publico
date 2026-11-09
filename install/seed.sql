-- Datos base de TAURO (roles, departamentos, categorias y zonas).
-- No contiene datos personales ni de clientes.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `roles` (`id`,`nombre`,`descripcion`,`permisos`) VALUES
(1,'administrador','Acceso total al sistema','{"inventario":["ver","crear","editar","eliminar"],"clientes":["ver","crear","editar","eliminar"],"vendedores":["ver","crear","editar","eliminar"],"proveedores":["ver","crear","editar","eliminar"],"zonas":["ver","crear","editar","eliminar"],"ventas":["ver","crear","editar","eliminar","anular"],"compras":["ver","crear","editar","eliminar"],"pagos":["ver","crear","editar","eliminar"],"devoluciones":["ver","crear","editar","aprobar","rechazar"],"moneda":["ver","crear","editar"],"usuarios":["ver","crear","editar","eliminar"],"auditoria":["ver"],"reportes":["ver","exportar"]}'),
(2,'supervisor','Supervisión y gerencia de Tauro','{"inventario": ["ver"], "clientes": ["ver", "crear", "editar"], "vendedores": ["ver", "crear", "editar"], "proveedores": ["ver", "crear", "editar"], "zonas": ["ver", "crear", "editar"], "ventas": ["ver", "crear", "anular"], "compras": ["ver"], "pagos": ["ver"], "devoluciones": ["ver", "aprobar", "rechazar"], "moneda": ["ver", "crear"], "usuarios": [], "auditoria": ["ver"], "reportes": ["ver", "exportar"], "secuencias": ["ver", "crear"]}'),
(3,'vendedor','Acceso a sus propias ventas y clientes','{"inventario":["ver"],"clientes":["ver","crear"],"vendedores":[],"proveedores":[],"zonas":[],"ventas":["ver_propias","crear"],"compras":[],"pagos":["ver"],"devoluciones":["ver","crear"],"moneda":["ver"],"usuarios":[],"auditoria":[],"reportes":["ver_propias"]}'),
(4,'almacen','Gestión de inventario y compras','{"inventario":["ver","crear","editar"],"clientes":["ver"],"vendedores":[],"proveedores":["ver","crear","editar"],"zonas":[],"ventas":["ver"],"compras":["ver","crear","editar"],"pagos":[],"devoluciones":["ver","crear"],"moneda":["ver"],"usuarios":[],"auditoria":[],"reportes":["ver","exportar"]}'),
(5,'caja','Pagos y cobranza','{"inventario":["ver"],"clientes":["ver"],"vendedores":[],"proveedores":[],"zonas":[],"ventas":["ver"],"compras":[],"pagos":["ver","crear","editar"],"devoluciones":["ver"],"moneda":["ver","crear"],"usuarios":[],"auditoria":[],"reportes":["ver","exportar"]}');

INSERT INTO `departamentos` (`id`,`nombre`,`descripcion`,`color`) VALUES
(1,'TAURO','Tauro Import Motor''s C.A. - Cauchos, Rodamientos, Cadenas','#c0392b'),
(2,'JHON MOTORS','Jhon Motors - Repuestos y Accesorios','#2980b9'),
(3,'TRANSPORTE','Departamento de Transporte y Logística','#27ae60');

INSERT INTO `categorias` (`id`,`nombre`,`departamento_id`) VALUES
(1,'Cauchos (Llantas)',1),
(2,'Rodamientos',1),
(3,'Cadenas',1),
(4,'Kit Cadena/Piñón',1),
(5,'Piñones',1),
(6,'Amortiguadores',1);

INSERT INTO `zonas` (`id`,`nombre`) VALUES
(1,'SANTA BÁRBARA DE BARINAS'),
(2,'BARINAS'),
(3,'APURE'),
(4,'TÁCHIRA'),
(5,'MERIDA'),
(6,'MONAGAS'),
(7,'GUARICO'),
(8,'BOLIVAR'),
(9,'CARABOBO'),
(10,'LARA'),
(11,'PORTUGUESA'),
(12,'ZULIA'),
(13,'ANZOATEGUI'),
(14,'COJEDES');

SET FOREIGN_KEY_CHECKS = 1;