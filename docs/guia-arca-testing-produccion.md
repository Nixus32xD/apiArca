# Guia ARCA - Testing y produccion

Fecha: 2026-07-01

Esta guia resume el alta operativa de ARCA/AFIP para usar esta API fiscal con
WSAA y WSFEv1. No reemplaza la documentacion oficial ni la configuracion de
Clave Fiscal del contribuyente.

## Fuentes oficiales revisadas

- Documentacion Web Services SOAP ARCA:
  https://www.afip.gob.ar/ws/documentacion/
- Arquitectura general:
  https://www.afip.gob.ar/ws/documentacion/arquitectura-general.asp
- Certificados:
  https://www.afip.gob.ar/ws/documentacion/certificados.asp
- WSAA:
  https://www.afip.gob.ar/ws/documentacion/wsaa.asp
- WSFEv1 Factura Electronica:
  https://www.afip.gob.ar/ws/documentacion/ws-factura-electronica.asp
- Manual desarrollador WSFEv1 v4.4:
  https://www.afip.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG.pdf

## Conceptos base

- ARCA usa Web Services SOAP sobre HTTPS.
- El acceso a los web services de negocio se autentica con WSAA.
- WSAA devuelve Token y Sign para un servicio, por ejemplo `wsfe`.
- Cada entorno usa certificados y endpoints distintos.
- El Ticket de Acceso de WSAA es temporal; esta API lo cachea en
  `access_tickets`.

## Entorno testing / homologacion

Objetivo: emitir y validar comprobantes contra homologacion sin impacto fiscal
real.

1. Crear o actualizar empresa fiscal en esta API.

```http
POST /api/fiscal/companies
```

Payload minimo:

```json
{
  "external_business_id": "tenant-demo",
  "cuit": "20123456789",
  "legal_name": "Empresa Demo SA",
  "fiscal_condition": "responsable_inscripto",
  "environment": "testing",
  "default_point_of_sale": 1,
  "enabled": true
}
```

2. Generar CSR desde esta API.

```http
POST /api/fiscal/companies/{company}/credentials/csr
```

```json
{
  "key_name": "tenant-demo-testing.key",
  "common_name": "tenant-demo-testing",
  "organization_name": "Empresa Demo SA",
  "country_name": "AR"
}
```

La clave privada queda cifrada dentro de la API. El SaaS no debe guardarla.

3. Gestionar certificado de homologacion en ARCA.

Segun la documentacion oficial, para testing/homologacion se usa WSASS
(Autoservicio de Acceso a APIs de Homologacion), solicitado desde el
Administrador de Relaciones de Clave Fiscal con clave fiscal de una persona
fisica.

Pasos operativos:

- Ingresar al Administrador de Relaciones de Clave Fiscal.
- Adherir el servicio WSASS.
- En WSASS cargar el CSR generado por la API.
- Descargar el certificado de homologacion.
- Asociar el certificado al web service de negocio `wsfe`.

4. Cargar certificado en esta API.

```http
PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate
```

```json
{
  "certificate": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
  "active": true
}
```

La API valida que el certificado corresponda a la clave privada generada.

5. Probar credenciales y punto de venta.

```http
POST /api/fiscal/companies/{company}/credentials/test
GET /api/fiscal/companies/{company}/status
GET /api/fiscal/companies/{company}/diagnostics
GET /api/fiscal/companies/{company}/points-of-sale
GET /api/fiscal/companies/{company}/activities
```

6. Emitir comprobante de prueba.

```http
POST /api/fiscal/documents
```

Para Responsable Inscripto, enviar `invoice_mode=auto` y detalle de IVA por
alicuota cuando corresponda:

```json
{
  "business_id": "tenant-demo",
  "origin": { "type": "sale", "id": "sale-1000" },
  "invoice_mode": "auto",
  "customer": {
    "name": "Cliente SA",
    "document_type": "CUIT",
    "document_number": "30712345671",
    "iva_condition": "responsable_inscripto"
  },
  "amounts": {
    "imp_total": 121,
    "imp_neto": 100,
    "imp_iva": 21,
    "iva_items": [
      { "id": 5, "base_imp": 100, "importe": 21 }
    ]
  },
  "idempotency_key": "sale-1000-invoice"
}
```

7. Ver datos fiscales.

```http
GET /api/admin/
GET /api/fiscal/documents/iva-sales?business_id=tenant-demo
```

## Entorno produccion

Objetivo: emitir comprobantes reales con impacto fiscal.

1. Confirmar requisitos del contribuyente.

- CUIT correcto.
- Condicion fiscal correcta: `responsable_inscripto`, `monotributo` o
  `exento`.
- Punto de venta electronico habilitado para WSFEv1.
- Certificado digital de produccion vigente.
- Servicio `wsfe` delegado/asociado al certificado.

2. Crear o actualizar empresa fiscal con `environment=production`.

```json
{
  "external_business_id": "tenant-prod",
  "cuit": "20123456789",
  "legal_name": "Empresa Real SA",
  "fiscal_condition": "responsable_inscripto",
  "environment": "production",
  "default_point_of_sale": 1,
  "enabled": true
}
```

3. Generar CSR desde esta API para produccion.

Usar un `key_name` distinto al de testing.

4. Gestionar certificado de produccion en ARCA.

Segun la documentacion oficial, los certificados de produccion se gestionan con
"Administracion de Certificados Digitales" y "Administrador de Relaciones de
Clave Fiscal".

Pasos operativos:

- Ingresar con clave fiscal al CUIT correspondiente.
- Usar Administracion de Certificados Digitales.
- Cargar el CSR generado por esta API.
- Descargar el certificado de produccion.
- Delegar/asociar el servicio `wsfe` en Administrador de Relaciones.
- Confirmar que el punto de venta productivo esta habilitado.

5. Cargar certificado productivo en esta API.

```http
PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate
```

6. Validar antes de emitir.

```http
GET /api/fiscal/companies/{company}/status
GET /api/fiscal/companies/{company}/diagnostics
GET /api/fiscal/companies/{company}/points-of-sale
```

No emitir si `ready=false` o si diagnostics marca errores de certificado,
token, punto de venta o WSFEv1.

7. Emitir comprobantes reales.

- Usar `invoice_mode=auto` para que la API resuelva A/B/C.
- Usar `document_kind=invoice`, `credit_note` o `debit_note`.
- Para notas de credito/debito enviar `associated_vouchers`.
- Enviar `idempotency_key` estable por operacion comercial.
- Si un documento queda `uncertain`, no emitir otro comprobante; usar
  `reconcile` y luego `retry` si corresponde.

## Endpoints ARCA configurados en esta API

Testing:

- WSAA: `https://wsaahomo.afip.gov.ar/ws/services/LoginCms`
- WSFEv1: `https://wswhomo.afip.gov.ar/wsfev1/service.asmx`

Produccion:

- WSAA: `https://wsaa.afip.gov.ar/ws/services/LoginCms`
- WSFEv1: `https://servicios1.afip.gov.ar/wsfev1/service.asmx`

Estos valores estan en `config/fiscal.php` y pueden sobreescribirse por `.env`.

## Variables `.env` recomendadas

Testing:

```env
FISCAL_API_TOKENS=token-interno
FISCAL_WSAA_SERVICE=wsfe
FISCAL_WSAA_TESTING_URL=https://wsaahomo.afip.gov.ar/ws/services/LoginCms
FISCAL_WSFEV1_TESTING_URL=https://wswhomo.afip.gov.ar/wsfev1/service.asmx
FISCAL_DEFAULT_CURRENCY=PES
FISCAL_DEFAULT_IVA_ID=5
```

Produccion:

```env
FISCAL_API_TOKENS=token-interno
FISCAL_WSAA_SERVICE=wsfe
FISCAL_WSAA_PRODUCTION_URL=https://wsaa.afip.gov.ar/ws/services/LoginCms
FISCAL_WSFEV1_PRODUCTION_URL=https://servicios1.afip.gov.ar/wsfev1/service.asmx
FISCAL_DEFAULT_CURRENCY=PES
FISCAL_DEFAULT_IVA_ID=5
OPENSSL_CONF=/ruta/al/openssl.cnf
```

## Checklist previo a produccion

- Empresa creada con `environment=production`.
- `fiscal_condition` correcto.
- Certificado de produccion activo y vigente.
- Certificado corresponde a la clave privada generada.
- Punto de venta productivo correcto y no bloqueado.
- `GET /status` devuelve `ready=true`.
- `GET /diagnostics` sin errores bloqueantes.
- Emision probada en testing con el mismo flujo.
- SaaS guarda `fiscal_document_id`, `cbte_type`, punto de venta, numero, CAE,
  vencimiento CAE, importes e IVA por alicuota.
- SaaS implementa conciliacion para estado `uncertain`.

## Riesgos operativos

- Usar certificado de testing en produccion, o viceversa.
- Habilitar un punto de venta distinto al configurado.
- Reemitir comprobantes inciertos sin conciliar.
- No enviar detalle de IVA por alicuota para Responsable Inscripto.
- No asociar comprobante original en notas de credito/debito.
- Cambiar `APP_KEY` sin migrar datos cifrados: rompe certificados y tickets.
