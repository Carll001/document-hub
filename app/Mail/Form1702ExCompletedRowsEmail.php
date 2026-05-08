<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class Form1702ExCompletedRowsEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{storagePath: string, fileName: string}>  $attachmentsData
     */
    public function __construct(
        public readonly array $attachmentsData,
        public readonly ?string $subjectLine = null,
        public readonly ?string $messageBody = null,
    ) {
        $this->onQueue('filing-1702');
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
        return array_map(
            static fn (array $attachment): Attachment => Attachment::fromStorageDisk(\App\Support\DocumentStorage::diskName(), $attachment['storagePath'])
                ->as($attachment['fileName'])
                ->withMime('application/pdf'),
            $this->attachmentsData,
        );
    }
}
