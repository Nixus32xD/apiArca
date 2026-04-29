# Cambios requeridos en el SaaS para integrarse con la API fiscal ARCA

## 1) Variables y configuración operativa
- Definir por tenant/empresa un mapeo explícito de ambiente fiscal (`testing` o `production`) y **no mezclarlo** en runtime.
- Guardar y enviar siempre `business_id`/`external_business_id` consistente por tenant.
- Versionar configuración de punto de venta y tipo de comprobante por ambiente.

## 2) Flujo de onboarding de certificados
- Mantener flujo CSR -> carga en ARCA -> carga de `.crt` en API.
- No almacenar private keys en el SaaS cuando se use el flujo CSR server-side.
- Agregar pantalla de “estado fiscal” consumiendo:
  - `GET /api/fiscal/companies/{company}/status`
  - `GET /api/fiscal/companies/{company}/diagnostics`

## 3) Flujo de emisión robusto
- Tratar `POST /api/fiscal/documents` como operación potencialmente lenta y con estados intermedios.
- Implementar idempotencia estricta en SaaS (`idempotency_key` estable por operación comercial).
- Si el documento queda `uncertain`, ejecutar estrategia de conciliación:
  1. `POST /api/fiscal/documents/{id}/reconcile`
  2. Si corresponde, `POST /api/fiscal/documents/{id}/retry`
- No reintentar “a ciegas” en frontend sin revisar estado previo.

## 4) Timeouts y UX del lado SaaS
- Configurar timeout de cliente HTTP del SaaS mayor al promedio esperado, pero con UX asíncrona:
  - mostrar estado “procesando”
  - usar polling al endpoint `GET /api/fiscal/documents/{id}`
- Evitar bloqueos largos de UI esperando CAE en una única request.

## 5) Observabilidad y trazabilidad
- Enviar `X-Trace-Id` en todas las llamadas a la API fiscal.
- Registrar en SaaS: `trace_id`, `business_id`, `document_id`, `idempotency_key`, `endpoint`, `status`.
- Correlacionar logs SaaS con `fiscal_api_logs` de la API.

## 6) Reglas de negocio que debe validar el SaaS antes de emitir
- Punto de venta habilitado para la empresa y ambiente.
- Tipo de comprobante coherente con operación y cliente.
- Importes consistentes (total, neto, IVA, tributos, exentos).
- Datos fiscales mínimos del receptor según tipo de comprobante.

## 7) Recomendaciones de integración por etapas
1. **Etapa 1**: onboarding y diagnóstico en UI administrativa.
2. **Etapa 2**: emisión con idempotencia + estados + polling.
3. **Etapa 3**: manejo de `uncertain` con reconcile/retry automático controlado.
4. **Etapa 4**: alertas operativas (timeouts, errores ARCA frecuentes, certificados próximos a vencer).

## 8) Checklist mínimo de salida a producción
- [ ] Tenant fiscal configurado con ambiente correcto.
- [ ] Credencial activa validada con `credentials/test`.
- [ ] Punto de venta validado con `points-of-sale`.
- [ ] Actividades vigentes verificadas con `activities`.
- [ ] `idempotency_key` estable y única por transacción.
- [ ] `X-Trace-Id` implementado en todas las requests.
- [ ] Flujo de conciliación implementado para estados inciertos.
