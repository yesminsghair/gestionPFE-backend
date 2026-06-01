<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Reunion;
use App\Models\Utilisateur;
use Illuminate\Console\Command;

class SendRappelReunions extends Command
{
    protected $signature   = 'reunions:send-rappels';
    protected $description = 'Send due réunion rappel notifications to both student and encadrant';

    public function handle(): int
    {
        $due = Reunion::where('rappel_fired', false)
            ->whereNotNull('rappel_scheduled_at')
            ->where('rappel_scheduled_at', '<=', now())
            ->where('statut', 'confirmee')
            ->get();

        if ($due->isEmpty()) {
            $this->info('No rappels due.');
            return self::SUCCESS;
        }

        foreach ($due as $reunion) {
            $dateReunion = \Carbon\Carbon::parse($reunion->date_reunion)->format('d/m/Y à H\hi');

            $encadrant    = Utilisateur::find($reunion->encadrant_id);
            $etudiant     = Utilisateur::find($reunion->etudiant_id);
            $encadrantNom = $encadrant ? trim($encadrant->prenom . ' ' . $encadrant->nom) : 'votre encadrant';
            $etudiantNom  = $etudiant  ? trim($etudiant->prenom  . ' ' . $etudiant->nom)  : 'votre étudiant';

            // Notifier l'étudiant
            Notification::create([
                'user_id' => $reunion->etudiant_id,
                'message' => "🔔 Rappel : vous avez une réunion avec {$encadrantNom} le {$dateReunion}.",
                'lu'      => false,
            ]);

            // Notifier l'encadrant
            Notification::create([
                'user_id' => $reunion->encadrant_id,
                'message' => "🔔 Rappel : vous avez une réunion avec {$etudiantNom} le {$dateReunion}.",
                'lu'      => false,
            ]);

            $reunion->update(['rappel_fired' => true]);

            $this->info("Rappel sent for reunion #{$reunion->id} ({$dateReunion})");
        }

        return self::SUCCESS;
    }
}