<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientCredentialsEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly string $loginEmail,
        public readonly string $password,
        public readonly string $loginUrl,
    ) {
        $this->onQueue('filing-1702');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Analytica client portal credentials',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.client.credentials',
            with: [
                'clientName' => $this->client->name,
                'loginEmail' => $this->loginEmail,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
