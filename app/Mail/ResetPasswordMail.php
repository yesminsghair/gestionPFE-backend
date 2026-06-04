<?php
//preparation d'email à envoyer 
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable     
{
    use Queueable, SerializesModels;

    public string $resetUrl;
    public string $prenom;

    public function __construct(string $resetUrl, string $prenom)
    {
        $this->resetUrl = $resetUrl;
        $this->prenom   = $prenom;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réinitialisation de mot de passe — Vers le Diplôme');
    }

    public function content(): Content
{
    return new Content(
        view: 'emails.reset-password', // la vue blade crée
        with: [
            'resetUrl' => $this->resetUrl,//l'url crée dans authcontroller
            'prenom' => $this->prenom,
        ]
    );
}
}