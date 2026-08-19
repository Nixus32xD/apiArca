# API Fiscal ARCA/AFIP

API Laravel para gestionar empresas fiscales y emitir comprobantes electronicos contra ARCA/AFIP usando WSAA y WSFEv1. El servicio esta pensado como una capa interna entre un SaaS y los web services fiscales, con soporte multiempresa, credenciales por empresa, auditoria e idempotencia.

La referencia extendida esta en [docs/fiscal-api.md](docs/fiscal-api.md). Para
despliegues en contenedores, ver [docs/docker-deployment.md](docs/docker-deployment.md).

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
- Facturas, notas de credito y notas de debito A/B/C.
- Exposicion de importes e IVA por alicuota para Libro IVA Ventas.
- Carga manual de comprobantes de proveedores para Libro IVA Compras.
- PDF fiscal regenerable, QR oficial ARCA y envio por email en Queue.
- Adjuntos privados para compras/proveedores.
- Posicion IVA estimada por periodo reutilizando libros IVA.
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
FISCAL_DOCUMENTS_DISK=local
FISCAL_QR_URL=https://www.arca.gob.ar/fe/qr/
FISCAL_EMAIL_QUEUE=default
FISCAL_ATTACHMENTS_DISK=local
FISCAL_ATTACHMENT_MAX_KB=10240
FISCAL_REQUIRE_COMPANY_SCOPE_FOR_ID_ROUTES=false
FISCAL_ADMIN_ENABLED=false
FISCAL_ADMIN_TOKEN=
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
| `GET` | `/api/fiscal/documents/iva-sales` | Libro IVA Ventas por empresa y periodo. |
| `GET` | `/api/fiscal/iva/position` | Posicion IVA estimada por empresa y periodo. |
| `GET` | `/api/fiscal/documents/{document}` | Obtener un comprobante por id interno. |
| `GET` | `/api/fiscal/documents/{document}/pdf` | Generar/regenerar PDF fiscal autorizado. |
| `GET` | `/api/fiscal/documents/{document}/qr` | Obtener URL, payload y SVG del QR oficial ARCA. |
| `POST` | `/api/fiscal/documents/{document}/email` | Encolar envio del comprobante por email. |
| `POST` | `/api/fiscal/documents/{document}/email/resend` | Reenviar sin reemitir el comprobante. |
| `GET` | `/api/fiscal/documents/by-origin` | Buscar comprobantes por origen (`sale`, `payment`, `manual`, `appointment`). |
| `POST` | `/api/fiscal/documents/{document}/retry` | Reintentar emision de forma segura. |
| `POST` | `/api/fiscal/documents/{document}/reconcile` | Conciliar el comprobante contra ARCA. |
| `GET` | `/api/fiscal/purchases` | Listar comprobantes de proveedores. |
| `POST` | `/api/fiscal/purchases` | Cargar comprobante de proveedor. |
| `GET` | `/api/fiscal/purchases/iva-book` | Libro IVA Compras por empresa y periodo. |
| `GET` | `/api/fiscal/purchases/{purchase}/attachments` | Listar adjuntos privados. |
| `POST` | `/api/fiscal/purchases/{purchase}/attachments` | Subir PDF/JPG/JPEG/PNG privado. |
| `GET` | `/api/fiscal/purchases/{purchase}/attachments/{attachment}` | Descargar adjunto autenticado. |
| `DELETE` | `/api/fiscal/purchases/{purchase}/attachments/{attachment}` | Eliminar adjunto. |
| `PUT` | `/api/fiscal/purchases/{purchase}` | Actualizar comprobante de proveedor. |
| `DELETE` | `/api/fiscal/purchases/{purchase}` | Eliminar comprobante de proveedor. |

Vista operativa local:

```text
GET /api/admin/
```

En `local`/`testing` abre sin token. Fuera de local requiere `FISCAL_ADMIN_ENABLED=true` y `FISCAL_ADMIN_TOKEN`.

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
- `document_kind`: opcional. Acepta `invoice`, `credit_note` o `debit_note`. Para notas se requiere `associated_vouchers`.
- `idempotency_key`: evita emitir dos veces el mismo comprobante.
- `point_of_sale`: puede venir en el payload o tomarse del default de la empresa.
- `customer`: es opcional. Si falta, se usa consumidor final (`DocTipo=99`, `DocNro=0`).
- `customer.document_type`: `CUIT`, `DNI` o `CONSUMIDOR_FINAL`.
- `customer.iva_condition`: `responsable_inscripto`, `monotributo`, `consumidor_final` o `exento`.
- `amounts.iva_items`: es opcional. Si no se envia y `imp_iva` es mayor a cero, se genera una alicuota por defecto con `FISCAL_DEFAULT_IVA_ID`.
- Para emisores Responsable Inscripto, `amounts.iva_items` debe cerrar con `imp_neto` e `imp_iva`. Se soportan las alicuotas ARCA 21, 10.5, 27, 5, 2.5 y 0 por sus IDs oficiales.
- Para comprobantes C no se informa IVA; si llega un payload legacy con IVA, la API lo absorbe al subtotal sin discriminarlo.
- `payment_method` o `payment.method` acepta `cash`, `bank_transfer`, `debit_card`, `credit_card` u `other`. Este dato queda como auditoria operativa y no define el tipo de comprobante ARCA.

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

## PDF, QR y email fiscal

El PDF y el QR solo se generan para comprobantes `authorized`. No se llama a WSFEv1 ni se emite nuevamente; se usan los datos locales ya autorizados.

QR oficial ARCA:

```http
GET /api/fiscal/documents/10/qr?business_id=tenant-123
Authorization: Bearer token-largo-random
```

Respuesta:

```json
{
  "data": {
    "url": "https://www.arca.gob.ar/fe/qr/?p=eyJ2ZXIiOjEsImZlY2hhIjoiMjAyNi0wOC0xOSIsImN1aXQiOjIwMTIzNDU2Nzg5LCJwdG9WdGEiOjEsInRpcG9DbXAiOjEsIm5yb0NtcCI6MTEsImltcG9ydGUiOjEyMSwibW9uZWRhIjoiUEVTIiwiY3R6IjoxLCJ0aXBvQ29kQXV0IjoiRSIsImNvZEF1dCI6MTIzNDU2Nzg5MDEyMzQsInRpcG9Eb2NSZWMiOjgwLCJucm9Eb2NSZWMiOjMwNzEyMzQ1NjcxfQ==",
    "payload": {
      "ver": 1,
      "fecha": "2026-08-19",
      "cuit": 20123456789,
      "ptoVta": 1,
      "tipoCmp": 1,
      "nroCmp": 11,
      "importe": 121,
      "moneda": "PES",
      "ctz": 1,
      "tipoCodAut": "E",
      "codAut": 12345678901234,
      "tipoDocRec": 80,
      "nroDocRec": 30712345671
    },
    "svg": "<svg ...></svg>"
  }
}
```

La especificacion usada es la publicada por ARCA para comprobantes electronicos: URL `https://www.arca.gob.ar/fe/qr/`, parametro `p` y JSON version 1 codificado en Base64.

PDF fiscal:

```http
GET /api/fiscal/documents/10/pdf?business_id=tenant-123
Authorization: Bearer token-largo-random
Accept: application/pdf
```

Devuelve `application/pdf` y guarda una copia privada en `FISCAL_DOCUMENTS_DISK`. El PDF incluye emisor, receptor, tipo/numero, punto de venta, importes, IVA, tributos/percepciones, fecha, CAE/CAEA, vencimiento y QR.

Envio por email:

```http
POST /api/fiscal/documents/10/email/resend
Authorization: Bearer token-largo-random
Content-Type: application/json
```

```json
{
  "business_id": "tenant-123",
  "email": "cliente@example.com"
}
```

Respuesta:

```json
{
  "data": {
    "id": 10,
    "email": {
      "to": "cliente@example.com",
      "status": "pending",
      "attempts": 0,
      "sent_at": null,
      "last_error": null
    }
  }
}
```

El envio corre en `SendFiscalDocumentEmailJob`. Si falla, queda `failed` con `email_last_error`; un reenvio vuelve a encolar el mismo comprobante y nunca reemite ni llama a ARCA.

## Consulta por origen

```http
GET /api/fiscal/documents/by-origin?business_id=tenant-123&origin_type=sale&origin_id=sale-1000
Authorization: Bearer token-largo-random
```

`origin_type` acepta `sale`, `payment`, `manual` o `appointment`. La respuesta devuelve hasta 50 comprobantes ordenados por fecha descendente.

## IVA Compras

Carga manual de comprobante de proveedor:

```http
POST /api/fiscal/purchases
Authorization: Bearer token-largo-random
Content-Type: application/json
```

```json
{
  "business_id": "tenant-123",
  "origin": {
    "type": "purchase",
    "id": "purchase-1000"
  },
  "category": "insumos",
  "concept": "Productos profesionales",
  "voucher_date": "2026-04-10",
  "accounting_date": "2026-04-10",
  "cbte_type": 1,
  "point_of_sale": 2,
  "document_number": 123,
  "supplier": {
    "cuit": "30712345671",
    "name": "Proveedor SA",
    "iva_condition": "responsable_inscripto"
  },
  "amounts": {
    "imp_total": 363.5,
    "imp_neto": 300,
    "imp_iva": 58.5,
    "imp_trib": 5,
    "imp_op_ex": 0,
    "imp_tot_conc": 0,
    "iva_items": [
      { "id": 5, "base_imp": 100, "importe": 21 },
      { "id": 4, "base_imp": 100, "importe": 10.5 },
      { "id": 6, "base_imp": 100, "importe": 27 }
    ],
    "trib_items": [
      {
        "id": 99,
        "desc": "Percepcion IIBB",
        "base_imp": 300,
        "alic": 1.6667,
        "importe": 5
      }
    ]
  },
  "payment_method": "transferencia",
  "payment_status": "paid",
  "due_date": "2026-04-30",
  "idempotency_key": "supplier-30712345671-a-2-123"
}
```

Respuesta:

```json
{
  "data": {
    "id": 55,
    "business_id": "tenant-123",
    "category": "insumos",
    "concept": "Productos profesionales",
    "voucher_date": "2026-04-10",
    "cbte_type": 1,
    "point_of_sale": 2,
    "number": 123,
    "supplier": {
      "cuit": "30712345671",
      "name": "Proveedor SA",
      "iva_condition": "responsable_inscripto"
    },
    "amounts": {
      "imp_total": "363.50",
      "imp_neto": "300.00",
      "imp_iva": "58.50",
      "imp_trib": "5.00",
      "iva_items": [
        { "id": 4, "rate": "10.50", "base_imp": "100.00", "importe": "10.50" },
        { "id": 5, "rate": "21.00", "base_imp": "100.00", "importe": "21.00" },
        { "id": 6, "rate": "27.00", "base_imp": "100.00", "importe": "27.00" }
      ],
      "trib_items": [
        { "Id": 99, "Desc": "Percepcion IIBB", "BaseImp": "300.00", "Alic": "1.67", "Importe": "5.00" }
      ]
    },
    "payment": {
      "method": "bank_transfer",
      "reference": null,
      "status": "paid",
      "due_date": "2026-04-30"
    },
    "idempotency_key": "supplier-30712345671-a-2-123"
  },
  "meta": {
    "idempotent_replay": false
  }
}
```

Si `POST /api/fiscal/purchases` recibe la misma `idempotency_key` para la misma empresa y el mismo payload operativo, devuelve la compra existente con `meta.idempotent_replay=true`. Si el payload cambia, responde `409 idempotency_payload_conflict` y no duplica la compra.

Los totales se validan como:

```text
imp_total = imp_neto + imp_iva + imp_trib + imp_op_ex + imp_tot_conc
```

`imp_trib` debe coincidir con `amounts.trib_items`, que puede representar percepciones.

Adjuntos privados de compras:

```http
POST /api/fiscal/purchases/55/attachments
Authorization: Bearer token-largo-random
Content-Type: multipart/form-data
```

Campos:

```text
business_id=tenant-123
file=@factura-proveedor.pdf
```

Respuesta:

```json
{
  "data": {
    "id": 9,
    "purchase_id": 55,
    "original_name": "factura-proveedor.pdf",
    "mime": "application/pdf",
    "size": 12345,
    "storage_key": "fiscal-purchase-attachments/1/55/550e8400-e29b-41d4-a716-446655440000.pdf",
    "sha256": "4c8f4f0d9c2d4e0d0c5a58f16c8f14c742a9fd7d6c995e0f9f8e3c8cc7b0df52",
    "uploaded_at": "2026-08-19T10:00:00-03:00"
  }
}
```

Tambien disponibles:

```http
GET /api/fiscal/purchases/55/attachments?business_id=tenant-123
GET /api/fiscal/purchases/55/attachments/9?business_id=tenant-123
DELETE /api/fiscal/purchases/55/attachments/9
```

Se aceptan PDF, JPG, JPEG y PNG. Los archivos se guardan en `FISCAL_ATTACHMENTS_DISK` y no quedan servidos por path publico directo.

Libro IVA Compras:

```http
GET /api/fiscal/purchases/iva-book?business_id=tenant-123&date_from=2026-04-01&date_to=2026-04-30
```

Libro IVA Ventas:

```http
GET /api/fiscal/documents/iva-sales?business_id=tenant-123&date_from=2026-04-01&date_to=2026-04-30
```

Posicion IVA estimada:

```http
GET /api/fiscal/iva/position?business_id=tenant-123&date_from=2026-04-01&date_to=2026-04-30
Authorization: Bearer token-largo-random
```

Respuesta:

```json
{
  "data": {
    "company": {
      "id": 1,
      "business_id": "tenant-123",
      "cuit": "20123456789",
      "legal_name": "Empresa Demo SA",
      "fiscal_condition": "responsable_inscripto"
    },
    "period": {
      "date_from": "2026-04-01",
      "date_to": "2026-04-30"
    },
    "sales_totals": {
      "imp_total": "1210.00",
      "imp_neto": "1000.00",
      "imp_iva": "210.00",
      "imp_trib": "0.00",
      "imp_op_ex": "0.00",
      "imp_tot_conc": "0.00",
      "iva_by_aliquot": [
        { "id": 5, "rate": "21.00", "base_imp": "1000.00", "importe": "210.00" }
      ]
    },
    "purchase_totals": {
      "imp_total": "605.00",
      "imp_neto": "500.00",
      "imp_iva": "105.00",
      "imp_trib": "0.00",
      "imp_op_ex": "0.00",
      "imp_tot_conc": "0.00",
      "iva_by_aliquot": [
        { "id": 5, "rate": "21.00", "base_imp": "500.00", "importe": "105.00" }
      ]
    },
    "debit_vat": "210.00",
    "credit_vat": "105.00",
    "estimated_position": "105.00",
    "result": "payable",
    "iva_by_aliquot": [
      {
        "id": 5,
        "rate": "21.00",
        "sales_base_imp": "1000.00",
        "sales_iva": "210.00",
        "purchase_base_imp": "500.00",
        "purchase_iva": "105.00",
        "estimated_position": "105.00"
      }
    ]
  }
}
```

`estimated_position = debit_vat - credit_vat`. `result` puede ser `payable`, `credit` o `zero`.

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
- Las respuestas no JSON, PDFs y descargas no se guardan como binario en auditoria.
- Los uploads se auditan solo como metadatos de archivo, no como contenido.
- Las credenciales y tickets se guardan cifrados con casts `encrypted`.
- Las respuestas publicas no exponen certificado, clave privada ni passphrase.
- Los endpoints de ARCA se centralizan en `config/fiscal.php`.
- En rutas por id se puede enviar `business_id`, `external_business_id`, `X-Fiscal-Business-Id` o `X-Fiscal-Company-Id`; si no coincide con el registro, la API responde `403 company_scope_mismatch`. Para exigir scope en todas las rutas por id, usar `FISCAL_REQUIRE_COMPANY_SCOPE_FOR_ID_ROUTES=true`.

## Persistencia principal

- `fiscal_companies`: empresas fiscales, CUIT, ambiente, defaults y estado.
- `fiscal_credentials`: certificados, claves privadas, CSR, key name y estado.
- `access_tickets`: token y sign WSAA cifrados.
- `fiscal_documents`: comprobantes, numeracion, autorizacion, payloads y estado.
- `fiscal_document_iva_items`: IVA discriminado por alicuota de ventas.
- `fiscal_purchases`: comprobantes de proveedores para IVA Compras.
- `fiscal_purchase_iva_items`: IVA discriminado por alicuota de compras.
- `fiscal_purchase_attachments`: adjuntos privados de compras.
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
