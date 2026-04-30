<?php

use App\Http\Controllers\Api\Fiscal\FiscalCaeaController;
use App\Http\Controllers\Api\Fiscal\FiscalCompanyController;
use App\Http\Controllers\Api\Fiscal\FiscalDocumentController;
use App\Http\Middleware\AuditFiscalApiRequest;
use App\Http\Middleware\AuthenticateFiscalClient;
use Illuminate\Support\Facades\Route;

Route::prefix('fiscal')
    ->middleware([AuditFiscalApiRequest::class, AuthenticateFiscalClient::class])
    ->group(function (): void {
        Route::post('documents', [FiscalDocumentController::class, 'store']);
        Route::get('documents/by-origin', [FiscalDocumentController::class, 'byOrigin']);
        Route::get('documents/{document}', [FiscalDocumentController::class, 'show'])->whereNumber('document');
        Route::post('documents/{document}/retry', [FiscalDocumentController::class, 'retry'])->whereNumber('document');
        Route::post('documents/{document}/reconcile', [FiscalDocumentController::class, 'reconcile'])->whereNumber('document');
        Route::post('documents/{document}/caea/report', [FiscalCaeaController::class, 'report'])->whereNumber('document');

        Route::post('companies', [FiscalCompanyController::class, 'upsert']);
        Route::put('companies/{company}', [FiscalCompanyController::class, 'upsert']);
        Route::post('companies/{company}/credentials/csr', [FiscalCompanyController::class, 'generateCredentialsCsr']);
        Route::put('companies/{company}/credentials', [FiscalCompanyController::class, 'storeCredentials']);
        Route::put('companies/{company}/credentials/{credential}/certificate', [FiscalCompanyController::class, 'storeCredentialCertificate'])->whereNumber('credential');
        Route::get('companies/{company}/activities', [FiscalCompanyController::class, 'activities']);
        Route::get('companies/{company}/points-of-sale', [FiscalCompanyController::class, 'pointsOfSale']);
        Route::get('companies/{company}/status', [FiscalCompanyController::class, 'status']);
        Route::get('companies/{company}/diagnostics', [FiscalCompanyController::class, 'diagnostics']);
        Route::post('companies/{company}/credentials/test', [FiscalCompanyController::class, 'testCredentials']);
        Route::post('companies/{company}/caea/request', [FiscalCaeaController::class, 'request']);
        Route::get('companies/{company}/caea/consult', [FiscalCaeaController::class, 'consult']);
        Route::post('companies/{company}/caea/without-movement', [FiscalCaeaController::class, 'informWithoutMovement']);
        Route::get('companies/{company}/caea/without-movement', [FiscalCaeaController::class, 'consultWithoutMovement']);
    });
