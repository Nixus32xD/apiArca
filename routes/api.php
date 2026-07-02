<?php

use App\Http\Controllers\Api\Fiscal\FiscalAdminController;
use App\Http\Controllers\Api\Fiscal\FiscalCaeaController;
use App\Http\Controllers\Api\Fiscal\FiscalCompanyController;
use App\Http\Controllers\Api\Fiscal\FiscalDocumentController;
use App\Http\Controllers\Api\Fiscal\FiscalPurchaseController;
use App\Http\Middleware\AuditFiscalApiRequest;
use App\Http\Middleware\AuthenticateFiscalClient;
use Illuminate\Support\Facades\Route;

// HTML operativo para soporte/admin fiscal. La autenticacion se valida dentro
// del controlador: local/testing queda abierto; otros ambientes requieren
// FISCAL_ADMIN_ENABLED=true y FISCAL_ADMIN_TOKEN.
Route::get('admin', FiscalAdminController::class);

// API fiscal consumida por el SaaS. Todas las rutas auditan request/response en
// fiscal_api_logs y requieren Authorization: Bearer <token interno>.
Route::prefix('fiscal')
    ->middleware([AuditFiscalApiRequest::class, AuthenticateFiscalClient::class])
    ->group(function (): void {
        // Emite un comprobante fiscal contra ARCA/WSFEv1 y persiste CAE,
        // importes, IVA por alicuota, intentos, eventos y payloads.
        Route::post('documents', [FiscalDocumentController::class, 'store']);

        // Devuelve Libro IVA Ventas por empresa y rango de fechas, sin que el
        // frontend tenga que recalcular netos, IVA, totales ni alicuotas.
        Route::get('documents/iva-sales', [FiscalDocumentController::class, 'ivaSales']);

        // Busca comprobantes por origen estable del SaaS, por ejemplo
        // origin_type=appointment y origin_id=<id del turno>.
        Route::get('documents/by-origin', [FiscalDocumentController::class, 'byOrigin']);

        // Consulta el detalle local de un comprobante fiscal ya registrado.
        Route::get('documents/{document}', [FiscalDocumentController::class, 'show'])->whereNumber('document');

        // Reintenta una emision fallida o incierta. Si corresponde, concilia
        // primero contra ARCA para evitar duplicar numeros.
        Route::post('documents/{document}/retry', [FiscalDocumentController::class, 'retry'])->whereNumber('document');

        // Consulta ARCA para reconciliar un comprobante con numero asignado y
        // actualizar CAE/estado local cuando la respuesta previa fue incierta.
        Route::post('documents/{document}/reconcile', [FiscalDocumentController::class, 'reconcile'])->whereNumber('document');

        // Informa a ARCA un comprobante emitido con CAEA que quedo pendiente de
        // reporte informativo.
        Route::post('documents/{document}/caea/report', [FiscalCaeaController::class, 'report'])->whereNumber('document');

        // Devuelve Libro IVA Compras por empresa y rango de fechas.
        Route::get('purchases/iva-book', [FiscalPurchaseController::class, 'ivaBook']);

        // Lista comprobantes de proveedores cargados manualmente.
        Route::get('purchases', [FiscalPurchaseController::class, 'index']);

        // Crea una compra/proveedor para computar IVA Compras.
        Route::post('purchases', [FiscalPurchaseController::class, 'store']);

        // Consulta el detalle de una compra cargada.
        Route::get('purchases/{purchase}', [FiscalPurchaseController::class, 'show'])->whereNumber('purchase');

        // Actualiza una compra y reemplaza su detalle de IVA por alicuota.
        Route::put('purchases/{purchase}', [FiscalPurchaseController::class, 'update'])->whereNumber('purchase');

        // Elimina una compra manual y sus items de IVA asociados.
        Route::delete('purchases/{purchase}', [FiscalPurchaseController::class, 'destroy'])->whereNumber('purchase');

        // Crea o actualiza una empresa fiscal del SaaS por business_id/CUIT.
        Route::post('companies', [FiscalCompanyController::class, 'upsert']);

        // Actualiza una empresa fiscal existente identificada por id o
        // external_business_id.
        Route::put('companies/{company}', [FiscalCompanyController::class, 'upsert']);

        // Genera clave privada cifrada y CSR para cargar en ARCA. No activa la
        // credencial hasta recibir el certificado.
        Route::post('companies/{company}/credentials/csr', [FiscalCompanyController::class, 'generateCredentialsCsr']);

        // Guarda una credencial completa ya provista por administracion interna.
        // Preferir el flujo CSR + certificate cuando el SaaS actua como proxy.
        Route::put('companies/{company}/credentials', [FiscalCompanyController::class, 'storeCredentials']);

        // Guarda el certificado .crt devuelto por ARCA y valida que corresponda
        // a la clave privada generada por el CSR.
        Route::put('companies/{company}/credentials/{credential}/certificate', [FiscalCompanyController::class, 'storeCredentialCertificate'])->whereNumber('credential');

        // Consulta las actividades fiscales habilitadas del emisor en WSFEv1.
        Route::get('companies/{company}/activities', [FiscalCompanyController::class, 'activities']);

        // Consulta puntos de venta habilitados del emisor en WSFEv1.
        Route::get('companies/{company}/points-of-sale', [FiscalCompanyController::class, 'pointsOfSale']);

        // Devuelve un estado resumido para dashboards del SaaS: empresa,
        // credencial, ticket, defaults y datos operativos.
        Route::get('companies/{company}/status', [FiscalCompanyController::class, 'status']);

        // Ejecuta diagnosticos contra credencial, WSAA, FEDummy y WSFEv1 para
        // detectar problemas de configuracion.
        Route::get('companies/{company}/diagnostics', [FiscalCompanyController::class, 'diagnostics']);

        // Prueba operativa de credenciales contra ARCA. Es ruta interna, no del
        // flujo normal de usuarios finales.
        Route::post('companies/{company}/credentials/test', [FiscalCompanyController::class, 'testCredentials']);

        // Solicita un CAEA para periodo/quincena y lo guarda localmente.
        Route::post('companies/{company}/caea/request', [FiscalCaeaController::class, 'request']);

        // Consulta CAEA existente en ARCA para periodo/quincena.
        Route::get('companies/{company}/caea/consult', [FiscalCaeaController::class, 'consult']);

        // Informa periodo CAEA sin movimiento.
        Route::post('companies/{company}/caea/without-movement', [FiscalCaeaController::class, 'informWithoutMovement']);

        // Consulta en ARCA si un periodo CAEA sin movimiento fue informado.
        Route::get('companies/{company}/caea/without-movement', [FiscalCaeaController::class, 'consultWithoutMovement']);
    });
