# Fiscal API ARCA/AFIP

API Laravel para emitir comprobantes por WSAA + WSFEv1 con soporte multiempresa.

## Flujo de emision

1. El SaaS llama `POST /api/fiscal/documents` con `Authorization: Bearer <token>`.
2. La API resuelve la empresa por `business_id` o `external_business_id`.
3. Se valida que la empresa este habilitada y tenga credenciales activas.
4. `TokenCacheService` reutiliza un `access_ticket` vigente o renueva contra WSAA.
5. `WSFEv1Service` consulta `FECompUltimoAutorizado`.
6. `FiscalInvoiceService` asigna el siguiente numero y llama `FECAESolicitar`.
7. Se persisten request normalizado, request ARCA, response, CAE, observaciones, errores, intentos y eventos.
8. Si hay timeout o estado incierto, el documento queda `uncertain`; no se reemite a ciegas. Se debe usar `reconcile` o `retry`, que concilia primero cuando hay numero asignado.

## Autenticacion interna

Configurar `FISCAL_API_TOKENS` con uno o mas tokens separados por coma.

Se aceptan:

```text
FISCAL_API_TOKENS=token-largo-random
FISCAL_API_TOKENS=sha256:<hash-del-token>
```

Todos los endpoints `/api/fiscal/*` pasan por auditoria en `fiscal_api_logs`.

## Endpoints

```text
POST /api/fiscal/companies
PUT /api/fiscal/companies/{company}
POST /api/fiscal/companies/{company}/credentials/csr
PUT /api/fiscal/companies/{company}/credentials
PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate
GET /api/fiscal/companies/{company}/activities
GET /api/fiscal/companies/{company}/points-of-sale
GET /api/fiscal/companies/{company}/status
GET /api/fiscal/companies/{company}/diagnostics
POST /api/fiscal/companies/{company}/credentials/test

POST /api/fiscal/documents
GET /api/fiscal/documents/{id}
GET /api/fiscal/documents/by-origin
POST /api/fiscal/documents/{id}/retry
POST /api/fiscal/documents/{id}/reconcile
```

`{company}` puede ser `external_business_id` o el id interno numerico.

## Onboarding de certificados ARCA

Flujo recomendado para que el SaaS no custodie claves privadas:

1. El SaaS crea o actualiza la empresa fiscal.
2. El SaaS llama `POST /api/fiscal/companies/{company}/credentials/csr` con un `key_name` seguro.
3. La API genera la clave privada, la guarda cifrada e inactiva, genera el CSR y lo devuelve.
4. El cliente carga el CSR en ARCA/AFIP y descarga el certificado `.crt`.
5. El SaaS envia el contenido del `.crt` a `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`.
6. La API valida que el certificado matchee con la clave privada generada. Si matchea, activa la credencial.
7. El SaaS puede ejecutar `POST /api/fiscal/companies/{company}/credentials/test`.

Generar o reutilizar CSR:

```json
{
  "key_name": "empresa-demo.key",
  "common_name": "empresa-demo-prod",
  "organization_name": "Empresa Demo SA",
  "country_name": "AR"
}
```

Si `key_name` ya existe para la empresa y tiene CSR, se devuelve el mismo CSR y `meta.created=false`.
El `key_name` es un identificador logico; no se usa como path de filesystem.

Cargar certificado:

```json
{
  "certificate": "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----",
  "active": true
}
```

Si el certificado no corresponde a la clave privada guardada, la API responde `409` con `certificate_private_key_mismatch`.

## Payload minimo de emision

```json
{
  "business_id": "tenant-123",
  "sale_id": "sale-1000",
  "document_type": "invoice_b",
  "concept": 1,
  "cbte_type": 6,
  "point_of_sale": 1,
  "customer": {
    "doc_type": 99,
    "doc_number": 0,
    "name": "Consumidor Final"
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

El email del receptor no es obligatorio. Si no se informa receptor, se usa consumidor final: `DocTipo=99`, `DocNro=0`.

`activities` es opcional y se traduce a `Actividades/Actividad/Id` de WSFEv1. Los codigos vigentes del emisor se consultan con `GET /api/fiscal/companies/{company}/activities`, que llama a `FEParamGetActividades`.

Los puntos de venta habilitados para emision via WSFEv1 se consultan con `GET /api/fiscal/companies/{company}/points-of-sale`, que llama a `FEParamGetPtosVenta`.

## Diagnostico fiscal

`GET /api/fiscal/companies/{company}/diagnostics` devuelve checks separados para:

- empresa/CUIT configurada
- credencial activa
- certificado vigente y consistente con la clave privada
- WSAA/token
- `FEDummy`
- WSFEv1 autenticado con `FEParamGetPtosVenta`

Este endpoint esta pensado para que el SaaS muestre errores accionables sin intentar emitir un comprobante.

## CAEA

La capa WSFEv1 expone metodos para las operaciones CAEA:

- `FECAEASolicitar`
- `FECAEAConsultar`
- `FECAEARegInformativo`
- `FECAEASinMovimientoInformar`
- `FECAEASinMovimientoConsultar`

`FiscalCaeaService` reutiliza WSAA, el cliente SOAP XML y los logs outbound. La persistencia queda preparada en `fiscal_documents` con campos genericos de autorizacion y campos CAEA especificos para reportes posteriores.

## Tablas

- `fiscal_companies`: empresa fiscal, CUIT, ambiente, defaults, estado y metadata.
- `fiscal_credentials`: certificado, clave privada, passphrase, CSR, key name y estado de onboarding.
- `access_tickets`: Token + Sign cifrados, generacion, vencimiento y reutilizaciones.
- `fiscal_documents`: documento fiscal, origen, numeracion, autorizacion CAE/CAEA, estado, payloads y errores.
- `fiscal_document_attempts`: intentos por operacion, duracion, request/response y error.
- `fiscal_document_events`: eventos de trazabilidad del documento.
- `fiscal_api_logs`: auditoria inbound/outbound con payloads resumidos y sanitizados.

## Estados de documento

- `processing`: aceptado y en curso.
- `authorized`: WSFEv1 aprobo y devolvio CAE.
- `rejected`: WSFEv1 rechazo explicitamente.
- `error`: error local, WSAA o WSFEv1 sin autorizacion.
- `uncertain`: timeout o respuesta inconclusa; requiere conciliacion.

Ademas, `fiscal_status` normaliza estados para integraciones nuevas:

- `pending`
- `authorized`
- `rejected`
- `uncertain`
- `pending_report`
- `reported`
- `failed`

## Seguridad

- Certificado, clave privada, passphrase, token y sign usan casts `encrypted`.
- La clave privada generada para CSR queda solo en la API.
- Las responses no exponen credenciales.
- Los logs sanitizan datos sensibles por nombre de campo.
- Los endpoints ARCA estan centralizados en `config/fiscal.php`.
- El almacenamiento esta en base de datos cifrada por `APP_KEY`; para rotacion de llave hay que planificar migracion de cifrados.

## Pendientes recomendados

- Habilitar `pdo_sqlite` o configurar una base de test dedicada para ejecutar los tests de feature completos.
- Validar con certificados reales de homologacion antes de pasar a produccion.
- Ajustar reglas de IVA por tipo de comprobante/condicion fiscal si el SaaS no envia `amounts.iva_items`.
- Agregar endpoints administrativos mas finos si se necesita separar permisos de onboarding y emision.
- Implementar colas para emision asincronica si el SaaS no debe esperar llamadas a ARCA.
