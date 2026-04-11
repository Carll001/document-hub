<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Form1702ExBatchRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class Form1702ExCompletedRowEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Form1702ExBatchRow $row,
        public readonly ?string $subjectLine = null,
        public readonly ?string $messageBody = null,
        public readonly ?string $extraAttachmentStoragePath = null,
        public readonly ?string $extraAttachmentFileName = null,
        public readonly ?string $extraAttachmentMimeType = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.form-1702-ex.completed-send',
            with: [
                'messageBody' => $this->messageBody,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromStorageDisk('local', (string) $this->row->generated_pdf_storage_path)
                ->as((string) $this->row->generated_pdf_file_name)
                ->withMime('application/pdf'),
        ];

        if ($this->extraAttachmentStoragePath !== null && $this->extraAttachmentFileName !== null) {
            $attachments[] = Attachment::fromStorageDisk('local', $this->extraAttachmentStoragePath)
                ->as($this->extraAttachmentFileName)
                ->withMime($this->extraAttachmentMimeType ?? 'application/octet-stream');
        }

        return $attachments;
    }
}
