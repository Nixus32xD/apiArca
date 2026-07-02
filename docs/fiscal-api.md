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

## OpenSSL 3 y TLS contra ARCA/AFIP

Si produccion usa OpenSSL 3, WSFEv1 puede rechazar conexiones a
`https://servicios1.afip.gov.ar/wsfev1/service.asmx` con:

```text
cURL error 35: OpenSSL/3.0.x: error:0A00018A:SSL routines::dh key too small
```

El repo incluye `openssl.cnf` en la raiz para permitir esa conexion con
`CipherString = DEFAULT@SECLEVEL=1` y `MinProtocol = TLSv1.2`.

En Laravel Cloud configurar la variable de entorno apuntando a la ruta absoluta
real del archivo en el deploy:

```env
OPENSSL_CONF=/ruta/al/openssl.cnf
```

Importante: `FISCAL_OPENSSL_CONF` solo alimenta la configuracion OpenSSL usada
por la aplicacion para CSR/firma CMS/WSAA. No afecta el handshake TLS de
cURL/Guzzle que usa `SoapXmlClient` para llamar a WSFEv1. Para cURL/Guzzle debe
estar definido `OPENSSL_CONF` a nivel de proceso antes de iniciar PHP.

## Endpoints

Todas las rutas bajo `/api/fiscal/*` requieren `Authorization: Bearer <token>`
y pasan por auditoria en `fiscal_api_logs`. `{company}` puede ser
`external_business_id` o el id interno numerico.

### Admin fiscal

- `GET /api/admin/`: renderiza una vista HTML operativa para soporte y
  administracion fiscal. Permite filtrar por empresa y fechas, revisar estado
  local, credenciales/tickets, IVA ventas/compras, saldo IVA estimado, IVA por
  alicuota, medios de pago, ultimos comprobantes, compras recientes, errores y
  accesos directos a endpoints JSON. En `local` y `testing` queda abierto; en
  otros ambientes requiere `FISCAL_ADMIN_ENABLED=true` y `FISCAL_ADMIN_TOKEN`.

### Empresas fiscales

- `POST /api/fiscal/companies`: crea o actualiza una empresa fiscal usando
  `business_id`/`external_business_id`, CUIT, razon social, condicion fiscal,
  ambiente, punto de venta default y tipo de comprobante default.
- `PUT /api/fiscal/companies/{company}`: actualiza una empresa existente. Se usa
  cuando el SaaS ya conoce el identificador interno o externo de la empresa.
- `GET /api/fiscal/companies/{company}/status`: devuelve un resumen para el
  dashboard del SaaS: empresa, habilitacion, defaults, credencial activa,
  vencimiento de certificado, ticket WSAA cacheado y metadata operativa.
- `GET /api/fiscal/companies/{company}/diagnostics`: ejecuta diagnosticos
  operativos: empresa, credencial, certificado, WSAA, FEDummy y WSFEv1. Sirve
  para soporte tecnico antes de emitir.
- `GET /api/fiscal/companies/{company}/activities`: consulta en ARCA/WSFEv1 las
  actividades fiscales habilitadas del emisor.
- `GET /api/fiscal/companies/{company}/points-of-sale`: consulta en ARCA/WSFEv1
  los puntos de venta habilitados del emisor.

### Credenciales ARCA

- `POST /api/fiscal/companies/{company}/credentials/csr`: genera una clave
  privada cifrada y un CSR para cargar en ARCA. La credencial queda inactiva
  hasta recibir el certificado `.crt`.
- `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`:
  recibe el certificado devuelto por ARCA, valida que corresponda a la clave
  privada generada por el CSR y activa la credencial si matchea.
- `PUT /api/fiscal/companies/{company}/credentials`: guarda manualmente una
  credencial completa. Es una ruta administrativa interna; para SaaS conviene
  usar el flujo CSR + certificate para no exponer claves privadas.
- `POST /api/fiscal/companies/{company}/credentials/test`: prueba credenciales
  contra ARCA. Es una operacion interna para soporte, no un flujo de usuario
  final.

### Emision y consulta de comprobantes

- `POST /api/fiscal/documents`: emite un comprobante fiscal. Resuelve empresa,
  tipo de comprobante A/B/C, factura/nota de credito/nota de debito, valida
  importes, IVA por alicuota, medios de pago y comprobantes asociados. Llama a
  WSFEv1 o usa CAEA, persiste request normalizado, request ARCA, response, CAE,
  vencimiento, intentos, eventos y errores.
- `GET /api/fiscal/documents/{id}`: devuelve el detalle local de un comprobante:
  empresa, cliente, importes, IVA discriminado, CAE/CAEA, estado, intentos,
  eventos, request/response y observaciones.
- `GET /api/fiscal/documents/by-origin`: busca comprobantes por origen del SaaS,
  por ejemplo `origin_type=appointment` y `origin_id=<id del turno>`. Sirve para
  evitar duplicados y reconstruir el estado fiscal de una operacion comercial.
- `GET /api/fiscal/documents/iva-sales`: devuelve el Libro IVA Ventas por empresa
  y rango de fechas, con totales firmados, detalle por comprobante e IVA por
  alicuota. El frontend no debe recalcular estos importes.
- `POST /api/fiscal/documents/{id}/retry`: reintenta una emision fallida o
  incierta. Si el comprobante ya tenia numero asignado, primero concilia contra
  ARCA para evitar duplicar comprobantes.
- `POST /api/fiscal/documents/{id}/reconcile`: consulta ARCA para actualizar el
  estado local de un comprobante con numero asignado cuando hubo timeout o
  respuesta incierta.
- `POST /api/fiscal/documents/{id}/caea/report`: informa a ARCA un comprobante
  emitido con CAEA que quedo pendiente de reporte informativo.

### IVA Compras

- `GET /api/fiscal/purchases`: lista comprobantes de proveedores cargados
  manualmente. Permite filtrar por empresa y fechas.
- `POST /api/fiscal/purchases`: crea un comprobante de proveedor para computar
  IVA Compras. Guarda CUIT proveedor, tipo y numero de comprobante, fecha, neto,
  IVA por alicuota, total, tributos, moneda, medio de pago y comprobantes
  asociados si aplica.
- `GET /api/fiscal/purchases/{id}`: consulta una compra con su detalle de IVA.
- `PUT /api/fiscal/purchases/{id}`: actualiza una compra y reemplaza su detalle
  de IVA por alicuota.
- `DELETE /api/fiscal/purchases/{id}`: elimina una compra manual y sus items de
  IVA asociados.
- `GET /api/fiscal/purchases/iva-book`: devuelve el Libro IVA Compras por empresa
  y rango de fechas, con totales e IVA por alicuota.

### CAEA

- `POST /api/fiscal/companies/{company}/caea/request`: solicita un CAEA para un
  periodo/quincena y lo guarda localmente para usarlo en contingencia.
- `GET /api/fiscal/companies/{company}/caea/consult`: consulta en ARCA un CAEA
  existente para periodo/quincena.
- `POST /api/fiscal/companies/{company}/caea/without-movement`: informa a ARCA
  que un periodo CAEA no tuvo movimiento.
- `GET /api/fiscal/companies/{company}/caea/without-movement`: consulta si el
  periodo CAEA sin movimiento fue informado correctamente.

### Rutas web locales

Estas rutas estan en `routes/web.php`, responden 404 fuera de `local` y son solo
para debugging manual:

- `GET /`: pantalla default de Laravel.
- `GET /fiscal/companies/{company}/status`: diagnostico local rapido de empresa,
  credencial y access ticket.
- `GET /fiscal/documents`: listado local de los ultimos comprobantes fiscales.
- `GET /fiscal/documents/{document}`: detalle local completo de un comprobante,
  con intentos, eventos y payloads.

Los endpoints de credenciales quedan dentro de la API fiscal. El SaaS comercial
puede invocar `credentials/csr` y `credentials/{credential}/certificate`
unicamente como proxy administrativo para operadores que no tienen acceso directo
a esta API: no debe generar claves, almacenar `.key`, almacenar `.crt` ni llamar
a ARCA/AFIP. `credentials/test` queda como operacion interna de la API, no como
flujo del SaaS.

## Onboarding de certificados ARCA

Flujo recomendado para que el SaaS no custodie claves privadas ni certificados:

1. El SaaS crea o actualiza la empresa fiscal.
2. El SaaS llama como proxy a `POST /api/fiscal/companies/{company}/credentials/csr` con un `key_name` seguro.
3. La API genera la clave privada, la guarda cifrada e inactiva, genera el CSR y lo devuelve.
4. Un operador o proceso administrativo carga el CSR en ARCA/AFIP y descarga el certificado `.crt`.
5. El SaaS reenvia el contenido del `.crt` a `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`.
6. La API valida que el certificado matchee con la clave privada generada. Si matchea, activa la credencial.
7. La API puede ejecutar `POST /api/fiscal/companies/{company}/credentials/test` como prueba operativa interna.

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
  "origin": {
    "type": "sale",
    "id": "1000"
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

El email del receptor no es obligatorio. Si no se informa receptor, se usa consumidor final: `DocTipo=99`, `DocNro=0`.

Con `invoice_mode=auto`, la API resuelve `cbte_type` segun la condicion fiscal del emisor (`fiscal_condition` en `fiscal_companies`) y la condicion IVA del receptor:

- emisor `monotributo` o `exento`: Factura C (`cbte_type=11`);
- emisor `responsable_inscripto` + receptor `responsable_inscripto`: Factura A (`cbte_type=1`);
- emisor `responsable_inscripto` + receptor no RI: Factura B (`cbte_type=6`).

`document_kind` permite emitir tambien notas:

- `invoice`: Facturas A/B/C (`1`, `6`, `11`);
- `debit_note`: Notas de Debito A/B/C (`2`, `7`, `12`);
- `credit_note`: Notas de Credito A/B/C (`3`, `8`, `13`).

Para notas de credito/debito se debe enviar `associated_vouchers` con al menos un comprobante asociado.

El payload legacy con `origin_type`, `origin_id`, `document_type`, `cbte_type`, `customer.doc_type`, `customer.doc_number` y `customer.tax_condition_id` se mantiene por compatibilidad, pero el SaaS comercial debe preferir `invoice_mode=auto`.

`origin_type` y `origin_id` identifican la operacion comercial estable del SaaS
y tienen prioridad sobre los identificadores legacy `sale_id` y `payment_id`.
Esto permite recuperar documentos con `GET /api/fiscal/documents/by-origin`
aunque el SaaS haya perdido o no haya persistido todavia el `fiscal_document_id`.

`activities` es opcional y se traduce a `Actividades/Actividad/Id` de WSFEv1. Los codigos vigentes del emisor se consultan con `GET /api/fiscal/companies/{company}/activities`, que llama a `FEParamGetActividades`.

La respuesta de `activities` queda normalizada para el SaaS en
`data.activities[]` con `id`, `code` y `name`.

Los puntos de venta habilitados para emision via WSFEv1 se consultan con `GET /api/fiscal/companies/{company}/points-of-sale`, que llama a `FEParamGetPtosVenta`.

La respuesta de `points-of-sale` queda normalizada para el SaaS en
`data.points_of_sale[]` con `id`, `number`, `type`, `emission_type`,
`blocked` y `disabled_at`.

## IVA y medios de pago

Para empresas Responsable Inscripto, la API valida que:

- `ImpTotal` cierre con neto gravado, no gravado, exento, tributos e IVA;
- `ImpIVA` cierre con la suma de `amounts.iva_items`;
- la suma de bases de `amounts.iva_items` cierre con `ImpNeto`;
- el importe de cada alicuota corresponda a su ID ARCA: 21%, 10.5%, 27%, 5%, 2.5% o 0%.

En comprobantes C no se informa IVA. Si llega un payload legacy con IVA, la API lo absorbe al subtotal sin discriminarlo para evitar rechazos de ARCA.

Los medios de pago (`cash`, `bank_transfer`, `debit_card`, `credit_card`, `other`) se guardan como dato operativo/auditoria. WSFEv1 no los usa para resolver el tipo de comprobante A/B/C ni para calcular IVA.

## IVA Compras

La API permite cargar comprobantes de proveedores manualmente:

```json
{
  "business_id": "tenant-123",
  "voucher_date": "2026-04-10",
  "cbte_type": 1,
  "point_of_sale": 2,
  "document_number": 123,
  "supplier": {
    "cuit": "30712345671",
    "name": "Proveedor SA",
    "iva_condition": "responsable_inscripto"
  },
  "amounts": {
    "imp_total": 121,
    "imp_neto": 100,
    "imp_iva": 21,
    "iva_items": [
      { "id": 5, "base_imp": 100, "importe": 21 }
    ]
  }
}
```

Libros IVA:

```text
GET /api/fiscal/documents/iva-sales?business_id=tenant-123&date_from=2026-04-01&date_to=2026-04-30
GET /api/fiscal/purchases/iva-book?business_id=tenant-123&date_from=2026-04-01&date_to=2026-04-30
```

Las notas de credito se informan con signo negativo en los libros; facturas y notas de debito con signo positivo.

## Estado fiscal para el SaaS

`GET /api/fiscal/companies/{company}/status` devuelve una vista resumida para el
dashboard del SaaS:

```json
{
  "data": {
    "business_id": "tenant-123",
    "fiscal_condition": "monotributo",
    "environment": "testing",
    "enabled": true,
    "ready": true,
    "status_label": "Listo",
    "message": "Empresa fiscal operativa."
  }
}
```

El SaaS debe usar `ready`, `status_label`, `message`, `environment` y
`defaults`. Los detalles de credenciales y tickets pueden venir en la respuesta
para diagnostico, pero no forman parte del flujo comercial del SaaS.

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

La API soporta el ciclo CAEA:

- `FECAEASolicitar`
- `FECAEAConsultar`
- `FECAEARegInformativo`
- `FECAEASinMovimientoInformar`
- `FECAEASinMovimientoConsultar`

Solicitar CAEA:

```json
{
  "period": "202604",
  "order": 1
}
```

Emitir documento con CAEA:

```json
{
  "business_id": "tenant-123",
  "origin_type": "sale",
  "origin_id": "1000",
  "authorization_type": "CAEA",
  "caea": {
    "code": "12345678901234",
    "period": "202604",
    "order": 1,
    "from": 20260401,
    "to": 20260415,
    "due_date": "2026-04-15",
    "report_deadline": "2026-04-20",
    "report_now": true
  },
  "amounts": {
    "imp_total": 121,
    "imp_neto": 100
  },
  "idempotency_key": "sale-1000-invoice-caea"
}
```

`report_now` viene `true` por defecto: la API crea el documento con `authorization_type=CAEA`, arma `FECAEARegInformativo`, lo informa a ARCA y deja `fiscal_status=reported` si la respuesta es aprobada. Si se manda `false`, el documento queda `fiscal_status=pending_report` y luego puede informarse con `POST /api/fiscal/documents/{id}/caea/report`.

### Reporte automatico CAEA

Cada CAEA solicitado o consultado se persiste en `fiscal_caeas`.

El comando programado:

```bash
php artisan arca:caea:report-due
```

corre todos los dias a las 01:00. Reporta automaticamente los CAEA vencidos por fin de periodo o por fecha tope:

- si existen documentos con `fiscal_status=pending_report`, ejecuta `FECAEARegInformativo`;
- si no existen documentos para ese CAEA, ejecuta `FECAEASinMovimientoInformar` usando el punto de venta y tipo de comprobante default de la empresa fiscal.

Para validar sin llamar a ARCA:

```bash
php artisan arca:caea:report-due --dry-run
```

En produccion debe estar activo el scheduler de Laravel:

```bash
php artisan schedule:run
```

## Tablas

- `fiscal_companies`: empresa fiscal, CUIT, condicion fiscal del emisor, ambiente, defaults, estado y metadata.
- `fiscal_credentials`: certificado, clave privada, passphrase, CSR, key name y estado de onboarding.
- `access_tickets`: Token + Sign cifrados, generacion, vencimiento y reutilizaciones.
- `fiscal_documents`: documento fiscal, origen, numeracion, autorizacion CAE/CAEA, estado, payloads y errores.
- `fiscal_document_iva_items`: IVA ventas discriminado por alicuota.
- `fiscal_purchases`: comprobantes de proveedores para IVA Compras.
- `fiscal_purchase_iva_items`: IVA compras discriminado por alicuota.
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
- Agregar endpoints administrativos mas finos si se necesita separar permisos de onboarding y emision.
- Implementar colas para emision asincronica si el SaaS no debe esperar llamadas a ARCA.
