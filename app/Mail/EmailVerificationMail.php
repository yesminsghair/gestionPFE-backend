<?php

namespace App\Mail;
//import des classes necessaires 
use Illuminate\Bus\Queueable;//met l'envoi en file d'attente
use Illuminate\Mail\Mailable;//classe de base des emails laravel
use Illuminate\Mail\Mailables\Content;//definit le contenu de mail
use Illuminate\Mail\Mailables\Envelope;//definit l'envelope sujet et l'utilisateur
use Illuminate\Queue\SerializesModels;//sérialiser les modéle pour la file d'attente

class EmailVerificationMail extends Mailable //classe qui herite de la classe mailable
{
    use Queueable, SerializesModels;//importe les fonct à utiliser
//proprietes publique a etre utilisé par blade 
    public string $verificationUrl;
    public string $prenom;
//__contrust:methode magique appele automa et instantanemt
    public function __construct(string $verificationUrl, string $prenom)//creation d'une instance de l'email
    { //on stocke les valeur à utiliser dans le template 
        $this->verificationUrl = $verificationUrl;
        $this->prenom          = $prenom;
    }

    public function envelope(): Envelope
    {//on definit les metadonnées de l'email
        return new Envelope(subject: 'Confirmation de votre inscription — Vers le Diplôme');
    }
// on definit que va utiliser dans la template blade 
    public function content(): Content
{
    return new Content(
        view: 'emails.verify-email',//l'interface blade envoyé
        with: [ //l'url d'email et le prenom de user 
            'verificationUrl' => $this->verificationUrl,
            'prenom' => $this->prenom,
        ]
    );
}
}