<?php

use App\Http\Requests\Fiscal\CaeaWithoutMovementRequest;
use App\Http\Requests\Fiscal\StoreFiscalDocumentRequest;
use App\Http\Requests\Fiscal\StoreFiscalPurchaseRequest;
use App\Http\Requests\Fiscal\UpsertFiscalCompanyRequest;
use App\Support\FiscalPointOfSale;
use Illuminate\Support\Facades\Validator;

test('the document request accepts the same maximum point of sale sent by ComerStock', function (): void {
    $validator = Validator::make([
        'point_of_sale' => FiscalPointOfSale::MAX,
        'associated_vouchers' => [['point_of_sale' => FiscalPointOfSale::MAX, 'PtoVta' => FiscalPointOfSale::MAX]],
    ], (new StoreFiscalDocumentRequest)->rules());

    expect($validator->errors()->has('point_of_sale'))->toBeFalse()
        ->and($validator->errors()->has('associated_vouchers.0.point_of_sale'))->toBeFalse()
        ->and($validator->errors()->has('associated_vouchers.0.PtoVta'))->toBeFalse();
});

test('all fiscal point of sale requests reject zero and values above the WSFEv1 maximum', function (string $request, string $field, array $payload): void {
    foreach ([0, FiscalPointOfSale::MAX + 1] as $pointOfSale) {
        $validator = Validator::make([...$payload, $field => $pointOfSale], (new $request)->rules());

        expect($validator->errors()->has($field))->toBeTrue();
    }
})->with([
    'fiscal company' => [UpsertFiscalCompanyRequest::class, 'default_point_of_sale', []],
    'fiscal document' => [StoreFiscalDocumentRequest::class, 'point_of_sale', []],
    'fiscal purchase' => [StoreFiscalPurchaseRequest::class, 'point_of_sale', []],
    'caea without movement' => [CaeaWithoutMovementRequest::class, 'point_of_sale', []],
]);
