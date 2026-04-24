<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MergedPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MergedPdfEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly MergedPdf $mergedPdf,
        public readonly ?string $subjectLine = null,
        public readonly ?string $messageBody = null,
    ) {}

    /**
     * Define the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    /**
     * Define the message content.
     */
    public function content(): Content
    {
        return new Content(
            text: 'emails.doc-merge.send',
            with: [
                'messageBody' => $this->messageBody,
            ],
        );
    }

    /**
     * Define the message attachments.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk(\App\Support\DocumentStorage::diskName(), $this->mergedPdf->storage_path)
                ->as($this->mergedPdf->file_name)
                ->withMime('application/pdf'),
        ];
    }
}
