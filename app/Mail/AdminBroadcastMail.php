<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class AdminBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) $this->payload['subject'],
            metadata: [
                'source' => 'admin_email_broadcast',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-broadcast',
            text: 'emails.admin-broadcast-text',
            with: [
                'payload' => $this->payload,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Laravel-Notification' => self::class,
            ],
        );
    }
}
