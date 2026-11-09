# TAURO — Sistema de Gestión

Sistema de gestión comercial para una empresa de importación y venta de cauchos,
repuestos y lubricantes. Está hecho en **PHP + MySQL** sin frameworks, pensado para
funcionar en cualquier hosting compartido o VPS con Apache/Nginx.

> Este repositorio es una **versión pública de demostración**. No incluye datos reales
> de clientes, credenciales ni configuraciones de producción.

## Módulos

| Módulo | Qué hace |
|---|---|
| **Inventario** | Productos, stock, stock mínimo, precios mayorista/detal, fotos, importación desde Excel, lista de precios en PDF y Excel |
| **Compras** | Órdenes de compra, recepción, actualización de stock y costos |
| **Ventas** | Cotización → despacho → venta, facturas con numeración por secuencia, descuentos, IGTF |
| **Cobranza** | Secuencias de pago, abonos por vendedor, comisiones, deudas pendientes |
| **Clientes y proveedores** | RIF, teléfonos, direcciones, límite de crédito, saldo |
| **Vendedores** | Cédula, zona, comisión, tipo de precio por defecto |
| **Documentos** | Impresión de facturas, notas de entrega, plantillas configurables, exportación a Word |
| **Reportes** | Ventas por período, vendedor, cliente, producto; inventario valorizado; auditoría |
| **Configuración** | Datos de la empresa, logo, moneda, tasas de cambio, zonas, departamentos |
| **Usuarios y roles** | Permisos por módulo y acción, bloqueo por intentos fallidos, auditoría de acciones |

## Requisitos

- PHP **8.1 o superior** con `pdo_mysql`, `mbstring`, `gd`, `json`, `zip`
- MySQL **8.0** o MariaDB **10.5+**
- Apache con `mod_rewrite` o Nginx

## Instalación

```bash
# 1. Código
git clone https://github.com/lizno/tauro-sistema-publico.git tauro
cd tauro

# 2. Base de datos
mysql -u root -p -e "CREATE DATABASE taurosystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p taurosystem < install/schema.sql
mysql -u root -p taurosystem < install/seed.sql

# 3. Configuración
cp config/config.example.php config/config.php
# edita config/config.php con tus datos de conexión y APP_URL

# 4. Primer usuario
php install/crear-admin.php
```

Abre `http://localhost/tauro` y entra con el usuario que acabas de crear.

### Permisos de escritura

```bash
chmod -R 775 assets/uploads storage
```

El usuario de PHP necesita escribir en `assets/uploads/` (fotos de productos, logo, perfiles).

## Estructura

```
api/            Endpoints AJAX (JSON)
assets/         CSS, imágenes y uploads
config/         Configuración (config.php no se versiona)
functions/      Lógica de negocio: db, auth, helpers, documentos, imágenes
includes/       Header, footer y páginas de error
install/        Esquema, datos base y creador de administrador
*.php           Módulos (inventario, compras, ventas, pagos, clientes, reportes...)
```

## Seguridad

- Consultas con **prepared statements** (PDO, sin emulación).
- Contraseñas con `password_hash()` (bcrypt).
- Token CSRF en todos los formularios que modifican datos.
- Bloqueo temporal de la cuenta tras intentos fallidos.
- Sesiones con cookies `httponly` y `samesite=Strict`.
- Registro de auditoría de las acciones importantes.

## Licencia

[MIT](LICENSE) © 2026 Carlos Lizcano.

Puedes usar, copiar, modificar y distribuir el código, manteniendo el aviso de
copyright y la licencia.
