<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $verificationUrl;
    public string $prenom;

    public function __construct(string $verificationUrl, string $prenom)
    {
        $this->verificationUrl = $verificationUrl;
        $this->prenom          = $prenom;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirmation de votre inscription — Vers le Diplôme');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.verify-email');
    }
}