# API Fiscal ARCA/AFIP

API Laravel para gestionar empresas fiscales y emitir comprobantes electronicos contra ARCA/AFIP usando WSAA y WSFEv1. El servicio esta pensado como una capa interna entre un SaaS y los web services fiscales, con soporte multiempresa, credenciales por empresa, auditoria e idempotencia.

La referencia extendida esta en [docs/fiscal-api.md](docs/fiscal-api.md).

## Stack

- PHP 8.3
- Laravel 13
- MySQL por defecto
- Pest para tests
- Vite/Tailwind para assets base de Laravel

## Que resuelve

- Alta y actualizacion de empresas fiscales por `external_business_id`.
- Carga de certificados y claves privadas, o generacion de CSR para que el SaaS no custodie claves privadas.
- Cache de tickets WSAA por empresa y servicio.
- Emision de comprobantes CAE por WSFEv1.
- Consulta de actividades, puntos de venta, estado y diagnostico fiscal.
- Reintento seguro y conciliacion de comprobantes con estado incierto.
- Auditoria inbound/outbound con payloads resumidos y sanitizados.

## Instalacion local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Para levantar la aplicacion:

```bash
php artisan serve
```

Tambien existe el script de desarrollo del proyecto:

```bash
composer run dev
```

Ese script levanta servidor Laravel, queue listener, logs con Pail y Vite en paralelo.

## Configuracion

Variables fiscales principales:

```env
FISCAL_API_TOKENS=
FISCAL_SOAP_TIMEOUT=30
FISCAL_SOAP_CONNECT_TIMEOUT=10
OPENSSL_CONF=/ruta/absoluta/al/openssl.cnf
FISCAL_OPENSSL_CONF=
FISCAL_OPENSSL_PRIVATE_KEY_BITS=2048
FISCAL_WSAA_SERVICE=wsfe
FISCAL_WSAA_TICKET_TTL_MINUTES=720
FISCAL_WSAA_RENEW_WITHIN_MINUTES=30
FISCAL_WSAA_TESTING_URL=https://wsaahomo.afip.gov.ar/ws/services/LoginCms
FISCAL_WSAA_PRODUCTION_URL=https://wsaa.afip.gov.ar/ws/services/LoginCms
FISCAL_WSFEV1_TESTING_URL=https://wswhomo.afip.gov.ar/wsfev1/service.asmx
FISCAL_WSFEV1_PRODUCTION_URL=https://servicios1.afip.gov.ar/wsfev1/service.asmx
FISCAL_DEFAULT_CONCEPT=1
FISCAL_DEFAULT_CURRENCY=PES
FISCAL_DEFAULT_CURRENCY_RATE=1
FISCAL_CONSUMER_FINAL_DOC_TYPE=99
FISCAL_CONSUMER_FINAL_DOC_NUMBER=0
FISCAL_CONSUMER_FINAL_TAX_CONDITION_ID=5
FISCAL_DEFAULT_IVA_ID=5
```

`APP_KEY` es importante porque Laravel lo usa para cifrar certificados, claves privadas, passphrases, tokens y signs guardados en base de datos.

### OpenSSL 3 y ARCA/AFIP en produccion

En entornos con OpenSSL 3, algunos endpoints productivos de WSFEv1 pueden fallar con `dh key too small` si el nivel de seguridad queda en el valor por defecto. Este repo incluye `openssl.cnf` para bajar solo el `SECLEVEL` del proceso a `1` y mantener `MinProtocol = TLSv1.2`.

En Laravel Cloud configurar la variable de entorno con la ruta absoluta real del deploy:

```env
OPENSSL_CONF=/ruta/al/openssl.cnf
```

`FISCAL_OPENSSL_CONF` se usa como ruta de configuracion para operaciones OpenSSL de la aplicacion, como generacion de CSR y firma CMS/WSAA. No modifica por si sola el handshake TLS de cURL/Guzzle usado para WSFEv1; para eso debe estar configurado `OPENSSL_CONF` en el entorno del proceso PHP antes de iniciar la app.

## Autenticacion

Todos los endpoints bajo `/api/fiscal/*` usan autenticacion interna por token.

Configurar uno o mas tokens separados por coma:

```env
FISCAL_API_TOKENS=token-largo-random
```

Tambien se aceptan hashes SHA-256:

```env
FISCAL_API_TOKENS=sha256:<hash-del-token>
```

El cliente puede enviar el token como bearer token o con el header `X-Fiscal-Token`:

```http
Authorization: Bearer token-largo-random
```

## Flujo de emision

1. El SaaS llama `POST /api/fiscal/documents`.
2. La API resuelve la empresa fiscal por `business_id` o `external_business_id`.
3. Se valida que la empresa este habilitada y tenga una credencial activa.
4. Se reutiliza un ticket WSAA vigente o se solicita uno nuevo.
5. Se consulta `FECompUltimoAutorizado` para calcular el proximo numero.
6. Se arma y envia `FECAESolicitar`.
7. Se persisten request, response, CAE, observaciones, errores, intentos y eventos.
8. Si ARCA responde con timeout o estado incierto, el documento queda `uncertain` y debe conciliarse antes de reintentar.

## Endpoints

| Metodo | Ruta | Uso |
| --- | --- | --- |
| `POST` | `/api/fiscal/companies` | Crear o actualizar empresa fiscal por `external_business_id`. |
| `PUT` | `/api/fiscal/companies/{company}` | Actualizar empresa fiscal existente. |
| `POST` | `/api/fiscal/companies/{company}/credentials/csr` | Generar o reutilizar CSR. |
| `PUT` | `/api/fiscal/companies/{company}/credentials` | Guardar certificado y clave privada provistos por el cliente. |
| `PUT` | `/api/fiscal/companies/{company}/credentials/{credential}/certificate` | Guardar certificado emitido por ARCA para una clave generada por CSR. |
| `GET` | `/api/fiscal/companies/{company}/activities` | Consultar actividades habilitadas en WSFEv1. |
| `GET` | `/api/fiscal/companies/{company}/points-of-sale` | Consultar puntos de venta habilitados en WSFEv1. |
| `GET` | `/api/fiscal/companies/{company}/status` | Ver estado local de empresa, credencial y ticket WSAA. |
| `GET` | `/api/fiscal/companies/{company}/diagnostics` | Ejecutar diagnosticos de empresa, certificado, WSAA y WSFEv1. |
| `POST` | `/api/fiscal/companies/{company}/credentials/test` | Validar credenciales contra WSAA y `FEDummy`. |
| `POST` | `/api/fiscal/documents` | Emitir comprobante fiscal. |
| `GET` | `/api/fiscal/documents/{document}` | Obtener un comprobante por id interno. |
| `GET` | `/api/fiscal/documents/by-origin` | Buscar comprobantes por origen (`sale`, `payment`, `manual`). |
| `POST` | `/api/fiscal/documents/{document}/retry` | Reintentar emision de forma segura. |
| `POST` | `/api/fiscal/documents/{document}/reconcile` | Conciliar el comprobante contra ARCA. |

`{company}` puede ser el `external_business_id` o el id numerico interno de la empresa.

## Alta de empresa fiscal

```http
POST /api/fiscal/companies
Authorization: Bearer token-largo-random
Content-Type: application/json
```

```json
{
  "external_business_id": "tenant-123",
  "cuit": "20123456789",
  "legal_name": "Empresa Demo SA",
  "environment": "testing",
  "default_point_of_sale": 1,
  "default_voucher_type": 6,
  "enabled": true,
  "onboarding_metadata": {
    "source": "panel-admin"
  }
}
```

Respuesta:

```json
{
  "data": {
    "id": 1,
    "business_id": "tenant-123",
    "cuit": "20123456789",
    "legal_name": "Empresa Demo SA",
    "fiscal_condition": "monotributo",
    "environment": "testing",
    "enabled": true,
    "defaults": {
      "point_of_sale": 1,
      "cbte_type": 6
    },
    "onboarding_metadata": {
      "source": "panel-admin"
    }
  }
}
```

## Onboarding de credenciales con CSR

Flujo recomendado:

1. Crear o actualizar la empresa fiscal.
2. Llamar `POST /api/fiscal/companies/{company}/credentials/csr`.
3. Cargar el CSR devuelto en ARCA/AFIP.
4. Descargar el certificado `.crt`.
5. Enviar el certificado a `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`.
6. Ejecutar `POST /api/fiscal/companies/{company}/credentials/test`.

Generar CSR:

```json
{
  "key_name": "empresa-demo.key",
  "common_name": "empresa-demo-prod",
  "organization_name": "Empresa Demo SA",
  "country_name": "AR"
}
```

Si `key_name` ya existe y tiene CSR, se devuelve el mismo CSR con `meta.created=false`.

Cargar certificado:

```json
{
  "certificate": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
  "active": true
}
```

La API valida que el certificado corresponda a la clave privada guardada. Si no coincide, responde `409` con `certificate_private_key_mismatch`.

## Carga directa de credenciales

Cuando el cliente ya posee certificado y clave privada:

```http
PUT /api/fiscal/companies/{company}/credentials
Authorization: Bearer token-largo-random
Content-Type: application/json
```

```json
{
  "certificate": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----",
  "passphrase": null,
  "certificate_expires_at": "2027-04-28",
  "active": true
}
```

Si `active` es `true`, las demas credenciales de la empresa se desactivan.

## Emision de comprobantes

```http
POST /api/fiscal/documents
Authorization: Bearer token-largo-random
Content-Type: application/json
X-Trace-Id: trace-opcional
```

Payload minimo:

```json
{
  "business_id": "tenant-123",
  "sale_id": "sale-1000",
  "origin": {
    "type": "sale",
    "id": "sale-1000"
  },
  "invoice_mode": "auto",
  "concept": 1,
  "point_of_sale": 1,
  "customer": {
    "name": "Cliente SA",
    "document_type": "CUIT",
    "document_number": "30712345671",
    "iva_condition": "responsable_inscripto",
    "address": "Av. Fiscal 123"
  },
  "amounts": {
    "imp_total": 121,
    "imp_neto": 100,
    "imp_iva": 21,
    "imp_trib": 0,
    "imp_op_ex": 0,
    "imp_tot_conc": 0
  },
  "currency": "PES",
  "currency_rate": 1,
  "activities": [
    620100
  ],
  "idempotency_key": "sale-1000-invoice"
}
```

Campos importantes:

- `business_id` o `external_business_id`: identifica la empresa fiscal.
- `origin.type` y `origin.id`: definen el origen del comprobante. `origin_type`/`origin_id`, `sale_id` y `payment_id` quedan como compatibilidad.
- `invoice_mode=auto`: la API resuelve Factura A/B/C segun emisor y receptor.
- `idempotency_key`: evita emitir dos veces el mismo comprobante.
- `point_of_sale`: puede venir en el payload o tomarse del default de la empresa.
- `customer`: es opcional. Si falta, se usa consumidor final (`DocTipo=99`, `DocNro=0`).
- `customer.document_type`: `CUIT`, `DNI` o `CONSUMIDOR_FINAL`.
- `customer.iva_condition`: `responsable_inscripto`, `monotributo`, `consumidor_final` o `exento`.
- `amounts.iva_items`: es opcional. Si no se envia y `imp_iva` es mayor a cero, se genera una alicuota por defecto con `FISCAL_DEFAULT_IVA_ID`.

Respuesta de ejemplo:

```json
{
  "data": {
    "id": 10,
    "business_id": "tenant-123",
    "company": {
      "id": 1,
      "cuit": "20123456789",
      "legal_name": "Empresa Demo SA",
      "fiscal_condition": "responsable_inscripto",
      "environment": "testing"
    },
    "origin": {
      "type": "sale",
      "id": "sale-1000"
    },
    "document_type": "invoice_b",
    "point_of_sale": 1,
    "cbte_type": 6,
    "concept": 1,
    "number": 11,
    "status": "authorized",
    "fiscal_status": "authorized",
    "authorization_type": "CAE",
    "authorization_code": "12345678901234",
    "authorization_expires_at": "2026-05-10",
    "cae": "12345678901234",
    "cae_expires_at": "2026-05-10",
    "idempotency_key": "sale-1000-invoice",
    "error": {
      "code": null,
      "message": null
    },
    "processed_at": "2026-04-28T10:00:00-03:00"
  },
  "meta": {
    "idempotent_replay": false
  }
}
```

Si se repite la misma `idempotency_key` para la misma empresa, la API devuelve el comprobante existente con `meta.idempotent_replay=true` y no llama de nuevo a ARCA.

## Consulta por origen

```http
GET /api/fiscal/documents/by-origin?business_id=tenant-123&origin_type=sale&origin_id=sale-1000
Authorization: Bearer token-largo-random
```

`origin_type` acepta `sale`, `payment` o `manual`. La respuesta devuelve hasta 50 comprobantes ordenados por fecha descendente.

## Reintentos y conciliacion

Estados principales del documento:

- `processing`: aceptado y en curso.
- `authorized`: ARCA aprobo y devolvio CAE.
- `rejected`: ARCA rechazo explicitamente.
- `error`: error local, WSAA o WSFEv1 sin autorizacion.
- `uncertain`: timeout o respuesta inconclusa.

Reglas de seguridad:

- Un documento `authorized` no se vuelve a emitir.
- Un documento `rejected` no se reintenta a ciegas.
- Un documento `uncertain` con numero asignado se concilia primero con `FECompConsultar`.
- Si la conciliacion no confirma que ARCA no tiene el comprobante, el retry se bloquea para evitar duplicados.

Endpoints:

```http
POST /api/fiscal/documents/{document}/reconcile
POST /api/fiscal/documents/{document}/retry
```

## Diagnosticos

```http
GET /api/fiscal/companies/{company}/diagnostics
Authorization: Bearer token-largo-random
```

Este endpoint ejecuta checks para:

- empresa habilitada y CUIT valida
- credencial activa
- certificado vigente y consistente con la clave privada
- ticket WSAA
- `FEDummy`
- WSFEv1 autenticado con consulta de puntos de venta

Sirve para mostrar errores accionables antes de intentar emitir.

## Errores

Los errores controlados tienen esta forma:

```json
{
  "message": "Fiscal company was not found.",
  "error_code": "company_not_found",
  "context": {
    "identifier": "tenant-123"
  }
}
```

Codigos frecuentes:

- `company_not_found`
- `company_disabled`
- `credentials_missing`
- `credentials_pending_certificate`
- `certificate_missing`
- `certificate_expired`
- `certificate_private_key_mismatch`
- `point_of_sale_required`
- `voucher_type_required`
- `document_rejected`
- `document_without_number`
- `reconcile_required_before_retry`
- `arca_timeout`
- `arca_http_error`

## Auditoria y seguridad

- Todos los endpoints fiscales pasan por auditoria en `fiscal_api_logs`.
- Los logs sanitizan campos como certificados, claves privadas, passphrases, tokens, signs, passwords y secrets.
- Las credenciales y tickets se guardan cifrados con casts `encrypted`.
- Las respuestas publicas no exponen certificado, clave privada ni passphrase.
- Los endpoints de ARCA se centralizan en `config/fiscal.php`.

## Persistencia principal

- `fiscal_companies`: empresas fiscales, CUIT, ambiente, defaults y estado.
- `fiscal_credentials`: certificados, claves privadas, CSR, key name y estado.
- `access_tickets`: token y sign WSAA cifrados.
- `fiscal_documents`: comprobantes, numeracion, autorizacion, payloads y estado.
- `fiscal_document_attempts`: intentos de operaciones fiscales.
- `fiscal_document_events`: eventos de trazabilidad.
- `fiscal_api_logs`: auditoria inbound/outbound.

## Tests

```bash
php artisan test
```

O usando el script de Composer:

```bash
composer test
```

Los tests de feature fiscales usan Pest y pueden requerir `pdo_sqlite` si se ejecutan con la configuracion de PHPUnit por defecto.
