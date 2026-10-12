<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The "choose a new password" email. Sent straight away (not queued) because the person is waiting for it. */
class ResetPasswordMail extends Mailable
{
    public function __construct(public string $name, public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your '.config('app.name').' password');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reset-password', with: ['name' => $this->name, 'url' => $this->url, 'minutes' => $this->minutes]);
    }
}
