<?php

namespace App\Http\Controllers\Api\Fiscal;

use App\Exceptions\Fiscal\FiscalException;
use App\Http\Controllers\Controller;
use App\Http\Resources\FiscalPurchaseAttachmentResource;
use App\Models\FiscalPurchase;
use App\Models\FiscalPurchaseAttachment;
use App\Services\Fiscal\FiscalRecordScopeGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FiscalPurchaseAttachmentController extends Controller
{
    public function __construct(
        private readonly FiscalRecordScopeGuard $scopeGuard,
    ) {}

    public function index(Request $request, FiscalPurchase $purchase): AnonymousResourceCollection|JsonResponse
    {
        try {
            $this->authorizePurchaseScope($request, $purchase);

            return FiscalPurchaseAttachmentResource::collection(
                $purchase->attachments()->latest('uploaded_at')->get()
            );
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function store(Request $request, FiscalPurchase $purchase): JsonResponse
    {
        try {
            $this->authorizePurchaseScope($request, $purchase);

            $data = $request->validate([
                'file' => [
                    'required',
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'mimetypes:application/pdf,image/jpeg,image/png',
                    'max:'.(int) config('fiscal.attachments.max_kb', 10240),
                ],
                'business_id' => ['nullable', 'string', 'max:120'],
                'external_business_id' => ['nullable', 'string', 'max:120'],
            ]);
            $file = $data['file'];
            $hash = hash_file('sha256', $file->getRealPath());
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $key = sprintf(
                'fiscal-purchase-attachments/%d/%d/%s.%s',
                (int) $purchase->fiscal_company_id,
                (int) $purchase->id,
                (string) Str::uuid(),
                $extension,
            );

            Storage::disk($this->disk())->put($key, file_get_contents($file->getRealPath()));

            $attachment = $purchase->attachments()->create([
                'original_name' => $originalName,
                'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'storage_key' => $key,
                'sha256' => $hash,
                'uploaded_at' => now(),
            ]);

            return (new FiscalPurchaseAttachmentResource($attachment))
                ->response()
                ->setStatusCode(201);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unexpected fiscal attachment API error.',
                'error_code' => 'unexpected_error',
            ], 500);
        }
    }

    public function download(Request $request, FiscalPurchase $purchase, FiscalPurchaseAttachment $attachment): StreamedResponse|JsonResponse
    {
        try {
            $this->authorizeAttachment($request, $purchase, $attachment);

            return Storage::disk($this->disk())->download(
                $attachment->storage_key,
                $attachment->original_name,
                ['Content-Type' => $attachment->mime],
            );
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    public function destroy(Request $request, FiscalPurchase $purchase, FiscalPurchaseAttachment $attachment): JsonResponse
    {
        try {
            $this->authorizeAttachment($request, $purchase, $attachment);
            Storage::disk($this->disk())->delete($attachment->storage_key);
            $attachment->delete();

            return response()->json(null, 204);
        } catch (FiscalException $exception) {
            return $this->fiscalError($exception);
        }
    }

    private function authorizePurchaseScope(Request $request, FiscalPurchase $purchase): void
    {
        $purchase->loadMissing('company');
        $this->scopeGuard->ensureCompanyMatches($request, $purchase->company);
    }

    private function authorizeAttachment(Request $request, FiscalPurchase $purchase, FiscalPurchaseAttachment $attachment): void
    {
        $this->authorizePurchaseScope($request, $purchase);

        if ((int) $attachment->fiscal_purchase_id !== (int) $purchase->id) {
            throw new FiscalException('Fiscal purchase attachment was not found for this purchase.', 404, 'attachment_not_found');
        }
    }

    private function fiscalError(FiscalException $exception): JsonResponse
    {
        return response()->json($exception->toPayload(), $exception->status());
    }

    private function disk(): string
    {
        return (string) config('fiscal.attachments.disk', 'local');
    }
}
