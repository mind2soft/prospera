<?php

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Contact-form submission (issue #17), queued — encrypted in the queue
 * (ShouldBeEncrypted): the payload carries the message, which is encrypted
 * at rest in contact_submissions too. The recipient is the
 * deployment-configured contact address; the submitter's email is set as
 * Reply-To so maintainers can answer directly. Subject and message are
 * sanitized to plain text upstream (see ContactController) and rendered as
 * plain text (no HTML escaping — the text part is not HTML).
 */
class ContactMessage extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly string $contactEmail,
        public readonly string $contactSubject,
        public readonly string $contactMessage,
        public readonly ?string $accountName = null,
        public readonly ?int $accountId = null,
        public readonly string $senderLocale = 'en',
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [$this->contactEmail],
            subject: '[Prospera] '.$this->contactSubject,
        );
    }

    /**
     * Get the message content definition. Properties are passed explicitly:
     * the promoted names must not collide with Mailable's own `$subject` /
     * `$locale` properties.
     */
    public function content(): Content
    {
        return new Content(
            text: 'mail.contact',
            with: [
                'email' => $this->contactEmail,
                'subject' => $this->contactSubject,
                'message' => $this->contactMessage,
                'accountName' => $this->accountName,
                'accountId' => $this->accountId,
                'locale' => $this->senderLocale,
            ],
        );
    }
}
