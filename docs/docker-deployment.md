# Despliegue Docker

Guia para correr esta API ARCA/AFIP en contenedores sin depender de Laravel
Cloud.

## Requisitos de imagen

- PHP 8.3 o superior.
- Composer 2.
- Extensiones PHP:
  - `pdo_mysql`
  - `openssl`
  - `curl`
  - `dom`
  - `xml`
  - `mbstring`
  - `fileinfo`
  - `redis` si van a usar Redis para cache/colas.
- Salida HTTPS desde el contenedor hacia ARCA/AFIP:
  - `wsaahomo.afip.gov.ar`
  - `wsaa.afip.gov.ar`
  - `wswhomo.afip.gov.ar`
  - `servicios1.afip.gov.ar`

## Variables importantes

Usar `.env.example` como base. No subir secretos reales al repositorio.

Variables criticas:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://apiarca.dominio.com

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=apiarca
DB_USERNAME=apiarca
DB_PASSWORD=...

FISCAL_API_TOKENS=token-largo-o-sha256:<hash>
FISCAL_ADMIN_ENABLED=true
FISCAL_ADMIN_TOKEN=token-admin-largo

OPENSSL_CONF=/var/www/html/openssl.cnf
FISCAL_OPENSSL_CONF=/var/www/html/openssl.cnf
```

En Docker, `DB_HOST` debe ser el nombre del service o host de base de datos. No
usar `127.0.0.1` salvo que MySQL corra dentro del mismo contenedor.

## APP_KEY

`APP_KEY` no es opcional. Laravel lo usa para cifrar:

- Certificados fiscales.
- Claves privadas.
- Passphrases.
- Token y sign WSAA guardados en base de datos.

Si cambian `APP_KEY` despues de tener datos cargados, esos datos cifrados dejan
de poder leerse. La rotacion de llave requiere una migracion controlada.

## OpenSSL 3

El repo incluye `openssl.cnf` para evitar errores de TLS contra WSFEv1
productivo con OpenSSL 3, por ejemplo `dh key too small`.

El archivo debe existir dentro del contenedor y `OPENSSL_CONF` debe estar
definido antes de iniciar PHP-FPM, workers o comandos artisan.

Ejemplo:

```env
OPENSSL_CONF=/var/www/html/openssl.cnf
FISCAL_OPENSSL_CONF=/var/www/html/openssl.cnf
```

`OPENSSL_CONF` afecta el handshake TLS de cURL/Guzzle. `FISCAL_OPENSSL_CONF`
afecta operaciones OpenSSL internas de la app, como CSR y firma WSAA.

## Build recomendado

Comandos esperados dentro de la imagen:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan package:discover --ansi
```

Si la imagen final no sirve assets web, pueden evaluar omitir Node/Vite. La API
fiscal no depende de frontend compilado para operar.

## Arranque del contenedor

Antes de servir trafico:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Despues iniciar PHP-FPM, Octane o el runtime elegido.

Si usan `config:cache`, cualquier cambio de variables requiere regenerar cache:

```bash
php artisan config:clear
php artisan config:cache
```

## Workers y colas

El proyecto usa `QUEUE_CONNECTION=database` por defecto. Si agregan trabajos
asincronicos, levantar un contenedor worker separado:

```bash
php artisan queue:work --tries=3 --timeout=120
```

Hoy la emision fiscal principal es HTTP sincrona, pero mantener worker separado
facilita crecimiento y jobs futuros.

## Permisos y volumenes

El usuario que corre PHP debe poder escribir en:

- `storage/`
- `bootstrap/cache/`

No hace falta montar certificados `.key` o `.crt` como archivos persistentes si
se usa el flujo actual: la API los guarda cifrados en base de datos.

## Proxy / HTTPS

Si el contenedor queda detras de Nginx, Traefik, ALB o Cloudflare:

- Configurar `APP_URL` con la URL publica HTTPS.
- Enviar headers `X-Forwarded-Proto`, `X-Forwarded-Host` y `X-Forwarded-Port`.
- No exponer directamente PHP-FPM a internet.
- Proteger `/api/admin/` con `FISCAL_ADMIN_TOKEN` y, si es posible, restriccion
  de IP o VPN.

## Base de datos

Despues de levantar DB:

```bash
php artisan migrate --force
```

Tablas fiscales relevantes:

- `fiscal_companies`
- `fiscal_credentials`
- `access_tickets`
- `fiscal_documents`
- `fiscal_document_iva_items`
- `fiscal_purchases`
- `fiscal_purchase_iva_items`
- `fiscal_api_logs`

Hacer backup regular de la base. La base contiene informacion fiscal sensible y
credenciales cifradas.

## Checklist de homologacion

1. Crear empresa fiscal en ambiente `testing`.
2. Generar CSR con:

```http
POST /api/fiscal/companies/{company}/credentials/csr
```

3. Cargar CSR en ARCA homologacion y descargar certificado.
4. Subir certificado:

```http
PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate
```

5. Ejecutar diagnostico:

```http
GET /api/fiscal/companies/{company}/diagnostics
```

6. Emitir una factura de prueba.
7. Revisar Libro IVA Ventas:

```http
GET /api/fiscal/documents/iva-sales?business_id=...
```

8. Cargar una compra manual y revisar Libro IVA Compras:

```http
POST /api/fiscal/purchases
GET /api/fiscal/purchases/iva-book?business_id=...
```

## Checklist de produccion

1. `APP_ENV=production`.
2. `APP_DEBUG=false`.
3. `APP_KEY` generado y persistido.
4. `FISCAL_API_TOKENS` y `FISCAL_ADMIN_TOKEN` largos y secretos.
5. `OPENSSL_CONF` y `FISCAL_OPENSSL_CONF` apuntando a un archivo existente.
6. Empresa fiscal creada con `environment=production`.
7. Certificado productivo emitido por ARCA y asociado al CUIT correcto.
8. Punto de venta productivo habilitado para WSFEv1.
9. `php artisan migrate --force` ejecutado.
10. Diagnostico fiscal OK.
11. Backup de DB configurado.

## Comandos utiles

```bash
php artisan migrate:status
php artisan route:list --path=api
php artisan config:show fiscal
php artisan test
```

## Riesgos principales

- Cambiar `APP_KEY` rompe lectura de credenciales cifradas.
- No configurar `OPENSSL_CONF` puede romper llamadas a WSFEv1 productivo.
- Usar endpoints productivos con empresa configurada como `testing`, o al reves,
  debe quedar bloqueado por `FISCAL_STRICT_ENDPOINT_ENV_CHECK=true`.
- No correr migraciones deja incompletas tablas de IVA Ventas/Compras.
- Exponer `/api/admin/` sin token o sin restriccion de red en produccion.
