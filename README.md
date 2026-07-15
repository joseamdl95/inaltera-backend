# InAltera - Backend

API REST para la gestión de facturación electrónica con enfoque en inmutabilidad, trazabilidad y cumplimiento del sistema Veri\*factu. Permite crear, modificar y emitir facturas con hash y código QR, leer facturas en PDF mediante IA para extraer datos, y exportar a XML.

## Stack tecnológico

- PHP 8.2 (sin framework)
- MySQL (BBDD inmutable)
- JWT (firebase/php-jwt)
- Librerías: QR, hashing, lectura de PDF (IA)
- Logs de auditoría de cada operación

## Instalación local

1. Clona el repositorio:
   ```bash
   git clone https://github.com/joseamdl95/Verifactu_Back.git
Copia el archivo de entorno y ajusta tus credenciales:

bash
cp .env.example .env
Edita .env con los datos de tu base de datos MySQL.

Importa la estructura de la base de datos:

Ejecuta database.sql en tu gestor (phpMyAdmin, MySQL Workbench, etc.)

Arranca el servidor PHP:

bash
php -S localhost:8000
La API estará disponible en http://localhost:8000.

Funcionalidades principales
Gestión de facturas (crear, modificar, emitir) con inmutabilidad (cada cambio registra una nueva versión)

Generación de código QR y hash para cada factura

Lectura de PDF mediante IA para extraer datos y crear la factura desde cero

Exportación a XML con todos los datos de la factura

Registro de logs detallados de cada acción (quién, cuándo, qué)

Gestión de usuarios y clientes (sin panel de administración)

Seguridad
Autenticación JWT

Contraseñas cifradas

BBDD inmutable (no se elimina información, solo se añaden nuevas versiones)

Logs encadenados para auditoría
