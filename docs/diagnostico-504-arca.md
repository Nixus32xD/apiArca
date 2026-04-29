# Diagnóstico error 504 ARCA/AFIP

## 1. Resumen ejecutivo
La causa más probable del 504 en Laravel Cloud es una **combinación de llamadas SOAP síncronas encadenadas dentro del mismo request HTTP** (WSAA + WSFEv1), junto con **reintentos automáticos y timeouts relativamente altos**. En emisión de comprobantes, un solo request puede disparar hasta 3 llamadas externas secuenciales (renovación WSAA, `FECompUltimoAutorizado`, `FECAESolicitar`), y cada una aplica `retry(3)` con espera de 2 segundos. Bajo latencia/intermitencia de ARCA, esto puede superar fácilmente el límite del gateway/proxy y terminar en 504.

Adicionalmente, hay riesgo de inconsistencias de ambiente (testing/production) por el diseño de resolución de empresa (`external_business_id` único) y cache de ticket por empresa+servicio sin clave explícita de ambiente en la tabla de tickets (depende de que la empresa no cambie de ambiente), lo que puede generar errores difíciles de diagnosticar si se “muta” una empresa ya operativa.

## 2. Rutas revisadas
Rutas API fiscales detectadas:

- `POST /api/fiscal/documents`: alta/emisión de documento fiscal. Ejecuta validación, normalización, búsqueda de último comprobante y solicitud CAE contra WSFEv1.  
- `GET /api/fiscal/documents/by-origin`: consulta documentos por origen.  
- `GET /api/fiscal/documents/{document}`: detalle del documento.  
- `POST /api/fiscal/documents/{document}/retry`: reintento de autorización; puede reconciliar y volver a autorizar.  
- `POST /api/fiscal/documents/{document}/reconcile`: consulta estado en WSFEv1 (`FECompConsultar`).  
- `POST /api/fiscal/companies`: alta/upsert de empresa fiscal.  
- `PUT /api/fiscal/companies/{company}`: actualización empresa fiscal.  
- `POST /api/fiscal/companies/{company}/credentials/csr`: generación/reutilización CSR y private key.  
- `PUT /api/fiscal/companies/{company}/credentials`: carga credencial completa (cert + private key).  
- `PUT /api/fiscal/companies/{company}/credentials/{credential}/certificate`: carga certificado para CSR generado.  
- `GET /api/fiscal/companies/{company}/activities`: consulta actividades ARCA (WSFEv1 autenticado).  
- `GET /api/fiscal/companies/{company}/points-of-sale`: consulta puntos de venta ARCA.  
- `GET /api/fiscal/companies/{company}/status`: estado interno de empresa/credencial/ticket.  
- `GET /api/fiscal/companies/{company}/diagnostics`: diagnóstico integral (empresa, credencial, certificado, WSAA, FEDummy, WSFEv1 autenticado).  
- `POST /api/fiscal/companies/{company}/credentials/test`: prueba credenciales (WSAA + FEDummy).

## 3. Rutas con riesgo de 504

| Ruta | Controller/método | Operación lenta detectada | Riesgo | Recomendación |
|---|---|---|---|---|
| `POST /api/fiscal/documents` | `FiscalDocumentController@store` | Flujo síncrono con WSAA (si renueva ticket) + `FECompUltimoAutorizado` + `FECAESolicitar` | Alto | Separar en job async o reducir timeout/retries y agregar degradación controlada. |
| `POST /api/fiscal/documents/{id}/retry` | `FiscalDocumentController@retry` | Puede ejecutar `reconcile` y luego nueva autorización (múltiples SOAP) | Alto | Limitar reintentos dentro del request y mover retry pesado a cola. |
| `POST /api/fiscal/documents/{id}/reconcile` | `FiscalDocumentController@reconcile` | `FECompConsultar` síncrono + posible renovación WSAA | Medio/Alto | Timeout más agresivo para consultas y circuito de fallback. |
| `GET /api/fiscal/companies/{company}/diagnostics` | `FiscalCompanyController@diagnostics` | Ejecuta varias validaciones externas (WSAA + FEDummy + WSFEv1 autenticado) | Alto | Dividir checks por endpoint o ejecutar check profundo asíncrono. |
| `POST /api/fiscal/companies/{company}/credentials/test` | `FiscalCompanyController@testCredentials` | WSAA + FEDummy en request síncrono | Medio | Short timeout + endpoint quick-check/health separado. |
| `GET /api/fiscal/companies/{company}/activities` | `FiscalCompanyController@activities` | WSAA/token + `FEParamGetActividades` | Medio | Cachear respuestas y controlar timeout por operación. |
| `GET /api/fiscal/companies/{company}/points-of-sale` | `FiscalCompanyController@pointsOfSale` | WSAA/token + `FEParamGetPtosVenta` | Medio | Cache temporal de catálogo y timeout más corto. |

## 4. Posibles causas encontradas

### Variables de entorno
- El sistema depende de variables `FISCAL_*` para endpoints y timeout SOAP.
- Si Laravel Cloud tiene `config:cache` viejo, puede seguir usando valores previos aunque en UI se cambien env vars.
- No hay chequeo explícito de “consistencia de env” en arranque (ej: detectar URL de testing con empresa en production).

### Homologación vs producción
- El ambiente se decide por `fiscal_companies.environment` (`testing` o `production`).
- `external_business_id` es único global; no permite coexistencia explícita por ambiente para la misma empresa lógica. Si se “edita” una empresa de testing a production, se recicla el mismo registro.
- El ticket WSAA se guarda por `fiscal_company_id + service` (sin columna ambiente); depende de que el `environment` de la empresa no cambie operativamente.

### Certificados
- Certificado y key se guardan cifrados en DB (positivo para Cloud, evita path local).
- Para firmar WSAA se crean temporales en `storage/app/private/fiscal-tmp`; si hay problemas de permisos/IO en runtime, la firma puede fallar.
- No se detectó uso de rutas hardcodeadas a archivos de certificado en producción (buena práctica).

### WSAA
- Renovación se hace inline en request cuando ticket está vencido/cerca de vencer.
- No hay lock distribuido para evitar renovación concurrente (race condition entre requests simultáneos).
- Reintentos + timeout de red pueden alargar mucho el tiempo total.

### WSFEv1
- Usa endpoint por ambiente (`testing`/`production`) desde config.
- Emisión normal llama primero a `FECompUltimoAutorizado` y luego `FECAESolicitar` dentro del mismo request.
- Si ARCA está lento y hay retry, se multiplica el tiempo de respuesta final.

### Timeouts
- Sí existen timeouts explícitos, pero con `retry(3)` y espera fija de 2s, el total por operación puede crecer demasiado.
- Como hay llamadas encadenadas, el timeout agregado puede exceder límites de gateway de Laravel Cloud.

### Laravel Cloud
- Posibles diferencias: límite máximo de request HTTP, cold starts, latencia saliente, y `config:cache` stale.
- Si logs de aplicación no van a `stderr` (según despliegue), el diagnóstico en Cloud se vuelve difícil.

### Base de datos
- El flujo de emisión hace múltiples escrituras (documento, intentos, eventos, logs). No parece cuello principal, pero bajo DB lenta podría agravar.
- Logging inbound/outbound persiste en DB en cada request fiscal; si DB está degradada, suma latencia.

### Código bloqueante
- Toda la integración ARCA está en el camino crítico del request HTTP.
- No hay uso actual de jobs para emisión o reconciliación pesada.

## 5. Evidencias en el código

- Definición de rutas fiscales y endpoints sensibles: `routes/api.php`.
- Emisión síncrona desde `store` -> `issue` -> autorización ARCA: `app/Http/Controllers/Api/Fiscal/FiscalDocumentController.php`, `app/Services/Fiscal/FiscalInvoiceService.php`.
- En emisión se encadenan `tokenCache->get`, `lastAuthorized`, `authorize`: `app/Services/Fiscal/FiscalInvoiceService.php`.
- Renovación/reuso WSAA dentro del request y sin lock explícito: `app/Services/Fiscal/TokenCacheService.php`.
- Llamadas SOAP con `Http::retry(3, 2000)` y timeouts por config: `app/Services/Fiscal/Support/SoapXmlClient.php`.
- WSAA firma CMS con archivos temporales en `storage/app/private/fiscal-tmp`: `app/Services/Fiscal/WSAAService.php`.
- Diagnóstico ejecuta múltiples llamadas remotas en cadena (`WSAA`, `FEDummy`, `pointsOfSale`): `app/Services/Fiscal/FiscalDiagnosticsService.php`.
- Selección de endpoint por ambiente en config: `config/fiscal.php`.
- Variables env esperadas para timeouts/endpoints: `.env.example`.
- Riesgo de mezcla por clave de empresa y resolución sin ambiente: `database/migrations/2026_04_23_000001_create_fiscal_companies_table.php`, `app/Services/Fiscal/FiscalCompanyResolver.php`, `app/Http/Controllers/Api/Fiscal/FiscalCompanyController.php`.
- Logs de auditoría/outbound suprimen excepciones (puede ocultar fallos de logging): `app/Http/Middleware/AuditFiscalApiRequest.php`, `app/Services/Fiscal/Support/FiscalApiLogger.php`.

## 6. Diferencias probables entre local y producción
- En local suele haber menor concurrencia y menos presión de latencia; en Cloud la latencia de salida a ARCA + límite del gateway puede disparar 504.
- En local quizá no se ejecutó `config:cache`, mientras que en Cloud sí: cambios de env no reflejados hasta limpiar cache.
- En local `storage`/permisos suelen ser más permissivos; en Cloud pueden existir restricciones intermitentes o diferencias de filesystem efímero.
- En local se prueba manualmente con pocos requests; en producción múltiples requests pueden forzar renovaciones WSAA concurrentes.

## 7. Validaciones faltantes
- Validar explícitamente coherencia ambiente-endpoint-certificado antes de emitir (ej. `company.environment=production` con endpoint de testing => bloquear).
- Validar metadatos clave por empresa/ambiente: CUIT emisor, punto de venta habilitado y tipo comprobante permitido para ese ambiente.
- Validar que ticket existente corresponda al mismo ambiente/cuit (guardando esos datos en metadata para chequeo rápido).
- Validar “presupuesto de tiempo” por request (si ya consumió demasiado tiempo, abortar antes de segunda/tercera llamada externa).
- Validar disponibilidad mínima de DB/logging para trazabilidad (pendiente de validar si hoy se monitorea).

## 8. Logs recomendados
Agregar/estandarizar logs estructurados (sin secretos) con estos campos:

- `trace_id`, `company_id`, `business_id`, `environment`, `operation`, `endpoint`, `attempt`, `duration_ms`, `status_code`.
- Antes/después de cada operación SOAP (`WSAA loginCms`, `FECompUltimoAutorizado`, `FECAESolicitar`, `FECompConsultar`).
- En `TokenCacheService`: `ticket_reused|ticket_renewed|ticket_generated`, `expires_at`, `renew_within_minutes`.
- En errores de timeout: timeout configurado, connect timeout, retries aplicados, elapsed real.
- En detección de inconsistencia de ambiente: `company_env`, `wsaa_url`, `wsfe_url`, `credential_subject_serial` (sin exponer certificado completo).
- En Laravel Cloud: confirmar canal de logs a `stderr` para observabilidad centralizada.

## 9. Cambios recomendados

### Cambios urgentes
1. Reducir latencia máxima por request: ajustar timeout/retries por operación y evitar cadenas largas en endpoints críticos.
2. Instrumentar logs de tramo por cada llamada externa con trace_id unificado.
3. Verificar y documentar env vars reales de Cloud + ejecutar limpieza de config/cache tras cambios.
4. Agregar guardas de coherencia ambiente (empresa vs endpoints/ticket).

### Cambios importantes
1. Introducir lock para renovación WSAA (por `company_id+service`) para evitar race condition.
2. Persistir metadatos de ambiente/cuit en `access_tickets` y validarlos antes de reutilizar.
3. Separar emisión/retry/reconcile pesados a jobs asíncronos cuando el SLA HTTP sea estricto.
4. Crear endpoint “quick diagnostics” (sin WSAA/WSFE) y mantener “full diagnostics” asíncrono.

### Cambios opcionales
1. Cache temporal de catálogos (`activities`, `points-of-sale`) con TTL corto.
2. Dashboard operativo (duración por operación, tasa timeout, error_code ARCA).
3. Política de circuit breaker cuando ARCA esté degradado.

## 10. Plan de corrección por etapas

**Etapa 1: diagnóstico/logs**
- Activar logs estructurados por tramo SOAP y validar visibilidad en Laravel Cloud.
- Correlacionar 504 con operación exacta (WSAA vs WSFEv1) y duración real.

**Etapa 2: timeouts**
- Ajustar `FISCAL_SOAP_TIMEOUT`, `FISCAL_SOAP_CONNECT_TIMEOUT` y estrategia de retry para no exceder timeout de gateway.
- Definir timeout diferenciado para endpoints de consulta vs emisión.

**Etapa 3: separación ambiente/certificados**
- Endurecer validaciones de coherencia ambiente-endpoint-ticket-certificado.
- Evitar mutación peligrosa de empresa entre ambientes o definir estrategia explícita por ambiente.

**Etapa 4: jobs/queues si corresponde**
- Migrar emisión/retry/reconcile a cola cuando el request sincrónico no cumpla SLA.
- Exponer estado de proceso por polling/webhook.

**Etapa 5: pruebas finales**
- Smoke tests en Cloud por ambiente.
- Pruebas de carga/latencia contra rutas críticas.
- Validación final de logs, alertas y tiempos p95/p99.

## 11. Comandos de prueba
> Ejecutar en entorno Laravel Cloud (SSH/release shell) y en staging antes de producción.

### Limpiar config/cache
```bash
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Revisar variables
```bash
php artisan tinker --execute="dump(config('fiscal.soap')); dump(config('fiscal.wsaa')); dump(config('fiscal.wsfev1.endpoints'));"
printenv | sort | grep '^FISCAL_'
```

### Probar rutas con curl
```bash
# status
curl -i -H "Authorization: Bearer <TOKEN_API>" \
  "https://<tu-dominio>/api/fiscal/companies/<company>/status"

# diagnostics
curl -i -H "Authorization: Bearer <TOKEN_API>" -H "X-Trace-Id: diag-$(date +%s)" \
  "https://<tu-dominio>/api/fiscal/companies/<company>/diagnostics"

# test credentials
curl -i -X POST -H "Authorization: Bearer <TOKEN_API>" -H "X-Trace-Id: test-$(date +%s)" \
  "https://<tu-dominio>/api/fiscal/companies/<company>/credentials/test"

# points of sale
curl -i -H "Authorization: Bearer <TOKEN_API>" -H "X-Trace-Id: pto-$(date +%s)" \
  "https://<tu-dominio>/api/fiscal/companies/<company>/points-of-sale"
```

### Revisar logs
```bash
# si canal file
php artisan pail --timeout=0

tail -n 200 storage/logs/laravel.log

# ejemplos SQL de trazas en fiscal_api_logs
php artisan tinker --execute="dump(\App\Models\FiscalApiLog::query()->latest('id')->limit(20)->get(['id','direction','operation','status_code','duration_ms','trace_id','created_at'])->toArray());"
```

### Correr queue worker (si aplica)
```bash
php artisan queue:work --queue=default --tries=3 --timeout=120
php artisan queue:failed
```

## 12. Conclusión
Para bajar rápidamente el 504, el primer cambio debe ser **acotar el tiempo total de request en rutas que hoy encadenan múltiples llamadas ARCA** (especialmente `POST /api/fiscal/documents` y `GET /diagnostics`), junto con **observabilidad por tramo** para identificar dónde se consume el tiempo. En paralelo, hay que **blindar la coherencia de ambiente (testing/production)** para eliminar fallos intermitentes por mezcla de contexto.

**Pendiente de validar:**
- Timeout efectivo del gateway/load balancer de Laravel Cloud para este servicio.
- Valores reales de `FISCAL_SOAP_TIMEOUT` / `FISCAL_SOAP_CONNECT_TIMEOUT` en producción.
- Si existen empresas que hayan cambiado de ambiente sobre el mismo `external_business_id`.
- Si hay evidencia de contención/concurrencia en renovación WSAA.
