<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalDocumentPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SendFiscalDocumentEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $documentId,
    ) {
        $this->onQueue((string) config('fiscal.email.queue', 'default'));
    }

    public function handle(FiscalDocumentPdfService $pdfService): void
    {
        $document = FiscalDocument::query()->with(['company', 'ivaItems'])->findOrFail($this->documentId);
        $document->increment('email_attempts');
        $document->refresh();

        try {
            $recipient = $document->email_to ?: data_get($document->normalized_payload, 'customer.email');

            if (! is_string($recipient) || $recipient === '') {
                throw new RuntimeException('Fiscal document customer email is missing.');
            }

            $document = $pdfService->store($document);
            $pdf = (string) Storage::disk($pdfService->disk())->get($document->pdf_storage_key);
            $filename = $pdfService->filename($document);

            Mail::raw($this->body($document), function (Message $message) use ($document, $recipient, $pdf, $filename): void {
                $message
                    ->to($recipient)
                    ->subject('Comprobante fiscal '.$document->company->legal_name.' '.$document->document_number)
                    ->attachData($pdf, $filename, ['mime' => 'application/pdf']);
            });

            $document->forceFill([
                'email_to' => $recipient,
                'email_status' => 'sent',
                'email_sent_at' => now(),
                'email_last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $document->forceFill([
                'email_status' => 'failed',
                'email_last_error' => $exception->getMessage(),
            ])->save();

            report($exception);
        }
    }

    private function body(FiscalDocument $document): string
    {
        return implode("\n", [
            'Adjuntamos el comprobante fiscal autorizado.',
            '',
            'Emisor: '.$document->company->legal_name.' (CUIT '.$document->company->cuit.')',
            'Comprobante: '.$document->document_type.' '.$document->point_of_sale.'-'.$document->document_number,
            'Total: '.$document->imp_total,
            'Autorizacion: '.$document->authorization_type.' '.$document->authorization_code,
        ]);
    }
}
