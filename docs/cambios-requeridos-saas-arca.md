# Contrato SaaS - API fiscal ARCA

Este documento registra el contrato esperado entre el SaaS comercial y esta API
fiscal. La regla operativa actual es que el SaaS no conoce WSAA, WSFEv1, SOAP,
certificados ni claves privadas: solo llama a esta API por HTTP.

## 1) Configuracion operativa

- El SaaS debe enviar siempre un `business_id` o `external_business_id`
  consistente por tenant.
- El ambiente fiscal (`testing` o `production`) se configura por empresa fiscal
  en la API y se sincroniza desde el SaaS como dato de tenant.
- Punto de venta, CUIT y condicion fiscal del emisor se sincronizan por empresa.
- El tipo de comprobante A/B/C se resuelve en la API con `invoice_mode=auto`.
  El SaaS no debe enviar `cbte_type` en ventas comunes.

## 2) Onboarding de certificados

- El onboarding de CSR, certificados y claves privadas queda bajo control de la
  API fiscal.
- El SaaS comercial puede llamar como proxy administrativo a:
  - `POST /api/fiscal/companies/{company}/credentials/csr`
  - `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`
- El SaaS comercial no debe llamar a:
  - `POST /api/fiscal/companies/{company}/credentials/test`
- El SaaS no debe generar claves, almacenar `.key`, almacenar `.crt` ni conocer
  WSAA/WSFEv1/SOAP.
- Para UI administrativa del SaaS se debe consumir:
  - `GET /api/fiscal/companies/{company}/status`
  - `GET /api/fiscal/companies/{company}/diagnostics`

## 3) Flujo de emision

- El SaaS emite mediante `POST /api/fiscal/documents`.
- Debe enviar `invoice_mode=auto`; si no envia datos fiscales del cliente, la
  API usa consumidor final sin identificar.
- Debe enviar `idempotency_key` estable por operacion comercial.
- Debe enviar `origin_type` y `origin_id` para poder recuperar documentos con
  `GET /api/fiscal/documents/by-origin`.
- Si un documento queda `uncertain`, el SaaS debe conciliar antes de reintentar:
  1. `POST /api/fiscal/documents/{id}/reconcile`
  2. `POST /api/fiscal/documents/{id}/retry`, solo si corresponde.

## 4) Observabilidad

- El SaaS debe enviar `X-Trace-Id` en todas las llamadas.
- La API registra auditoria en `fiscal_api_logs` y conserva intentos/eventos por
  documento fiscal.
- Los logs permiten correlacionar `business_id`, `document_id`,
  `idempotency_key`, endpoint, estado y `trace_id`.

## 5) Endpoints consumibles por el SaaS

- `POST /api/fiscal/companies`
- `PUT /api/fiscal/companies/{company}`
- `GET /api/fiscal/companies/{company}/status`
- `GET /api/fiscal/companies/{company}/diagnostics`
- `GET /api/fiscal/companies/{company}/activities`
- `GET /api/fiscal/companies/{company}/points-of-sale`
- `POST /api/fiscal/documents`
- `GET /api/fiscal/documents/{id}`
- `GET /api/fiscal/documents/by-origin`
- `POST /api/fiscal/documents/{id}/reconcile`
- `POST /api/fiscal/documents/{id}/retry`

## 6) Checklist de salida

- [ ] Tenant fiscal configurado con ambiente correcto.
- [ ] Empresa fiscal sincronizada en la API.
- [ ] Estado fiscal `ready=true` o diagnostico visible para operar.
- [ ] Punto de venta validado con `points-of-sale`.
- [ ] Actividades vigentes verificadas con `activities` cuando apliquen.
- [ ] `idempotency_key` estable y unico por transaccion.
- [ ] `origin_type` y `origin_id` enviados por el SaaS.
- [ ] `X-Trace-Id` implementado en todas las requests.
- [ ] Flujo de conciliacion implementado para estados inciertos.
