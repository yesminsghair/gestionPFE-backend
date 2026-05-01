<?php
namespace App\Http\Controllers;

use App\Models\JuryPfe;
use App\Models\JuryMembrePfe;
use App\Models\NotePfe;
use App\Models\ProjetPfe;
use App\Models\ResultatPfe;
use App\Models\Notification;
use App\Models\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JuryPfeController extends Controller
{
    // GET /api/jurys-pfe
    public function index(): JsonResponse
    {
        $jurys = JuryPfe::with([
            'projet.etudiant',
            'projet.encadrant',
            'membres.enseignant',
            'resultat',
        ])->get()->map(fn($j) => $this->format($j));

        return response()->json($jurys);
    }

    // POST /api/jurys-pfe  { projet_id }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'projet_id' => 'required|exists:projets_pfe,id|unique:jurys_pfe,projet_id',
        ]);

        $jury = JuryPfe::create(['projet_id' => $data['projet_id'], 'statut' => 'en_attente']);

        $projet = ProjetPfe::find($data['projet_id']);
        if ($projet?->encadrant_id) {
            JuryMembrePfe::create([
                'jury_id'       => $jury->id,
                'enseignant_id' => $projet->encadrant_id,
                'fonction'      => 'encadrant',
            ]);
        }

        return response()->json($jury->load('membres.enseignant'), 201);
    }

    // GET /api/jurys-pfe/{jury}
    public function show(JuryPfe $juryPfe): JsonResponse
    {
        return response()->json($this->format(
            $juryPfe->load('projet.etudiant', 'projet.encadrant', 'membres.enseignant', 'resultat')
        ));
    }

    // PUT /api/jurys-pfe/{jury}
    public function update(Request $request, JuryPfe $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'date_soutenance' => 'sometimes|date',
            'heure_debut'     => 'sometimes|date_format:H:i',
            'heure_fin'       => 'sometimes|date_format:H:i',
            'salle'           => 'sometimes|string|max:100',
            'statut'          => 'sometimes|in:en_attente,planifie,termine,annule',
        ]);
        $juryPfe->update($data);
        return response()->json($this->format($juryPfe->fresh('projet', 'membres.enseignant')));
    }

    // DELETE /api/jurys-pfe/{jury}
    public function destroy(JuryPfe $juryPfe): JsonResponse
    {
        $juryPfe->delete();
        return response()->json(['message' => 'Jury supprimé.']);
    }

    // ── Membres ────────────────────────────────────────────────────

    public function addMembre(Request $request, JuryPfe $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id' => 'required|exists:utilisateurs,id',
            'fonction'      => 'required|in:president,encadrant,examinateur',
        ]);

        if ($juryPfe->membres()->where('enseignant_id', $data['enseignant_id'])->exists()) {
            return response()->json(['message' => 'Ce membre est déjà dans le jury.'], 422);
        }

        $membre = $juryPfe->membres()->create($data);

        Notification::create([
            'user_id' => $data['enseignant_id'],
            'message' => 'Vous avez été affecté comme ' . $data['fonction'] . ' dans un jury PFE.',
        ]);

        return response()->json($membre->load('enseignant'), 201);
    }

    public function updateMembre(Request $request, JuryPfe $juryPfe, JuryMembrePfe $membre): JsonResponse
    {
        $data = $request->validate(['fonction' => 'required|in:president,encadrant,examinateur']);
        $membre->update($data);
        return response()->json($membre->load('enseignant'));
    }

    public function removeMembre(JuryPfe $juryPfe, JuryMembrePfe $membre): JsonResponse
    {
        $membre->delete();
        return response()->json(['message' => 'Membre retiré.']);
    }

    // ── Notes ──────────────────────────────────────────────────────

    public function getNotes(JuryPfe $juryPfe): JsonResponse
    {
        return response()->json($juryPfe->notes()->with('enseignant')->get());
    }

    public function saveNote(Request $request, JuryPfe $juryPfe): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id' => 'required|exists:utilisateurs,id',
            'note'          => 'required|numeric|min:0|max:20',
            'commentaire'   => 'nullable|string',
            'finalise'      => 'boolean',
        ]);

        $note = NotePfe::updateOrCreate(
            ['jury_id' => $juryPfe->id, 'enseignant_id' => $data['enseignant_id']],
            ['note' => $data['note'], 'commentaire' => $data['commentaire'] ?? null, 'finalise' => $data['finalise'] ?? false]
        );

        return response()->json($note);
    }

    // ── Délibération ───────────────────────────────────────────────

    public function deliberer(JuryPfe $juryPfe): JsonResponse
    {
        $notes = $juryPfe->notes()->where('finalise', true)->get();
        if ($notes->isEmpty()) {
            return response()->json(['message' => 'Aucune note finalisée.'], 422);
        }

        $moyenne  = round($notes->avg('note'), 2);
        $resultat = ResultatPfe::updateOrCreate(
            ['jury_id' => $juryPfe->id],
            [
                'etudiant_id' => $juryPfe->projet->etudiant_id,
                'note_finale' => $moyenne,
                'mention'     => $this->getMention($moyenne),
                'decision'    => $moyenne >= 10 ? 'admis' : 'ajourne',
            ]
        );

        $juryPfe->update(['statut' => 'termine']);
        return response()->json($resultat);
    }

    public function publier(JuryPfe $juryPfe): JsonResponse
    {
        $resultat = $juryPfe->resultat;
        if (!$resultat) {
            return response()->json(['message' => 'Délibération non effectuée.'], 422);
        }

        $resultat->update(['publie' => true, 'publie_le' => now()]);
        Notification::create([
            'user_id' => $juryPfe->projet->etudiant_id,
            'message' => 'Vos résultats de soutenance PFE sont disponibles.',
        ]);

        return response()->json($resultat);
    }

    public function publierCalendrier(): JsonResponse
    {
        $jurys = JuryPfe::with(['projet.etudiant', 'membres'])
            ->where('statut', 'planifie')
            ->whereNotNull('date_soutenance')
            ->get();

        foreach ($jurys as $jury) {
            $msg = "Soutenance planifiée le {$jury->date_soutenance} à {$jury->heure_debut} en salle {$jury->salle}.";
            Notification::create(['user_id' => $jury->projet->etudiant_id, 'message' => $msg]);
            foreach ($jury->membres as $m) {
                Notification::create(['user_id' => $m->enseignant_id, 'message' => $msg]);
            }
        }

        JuryPfe::where('statut', 'planifie')->update(['calendrier_publie' => true]);
        return response()->json(['message' => 'Calendrier publié.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/jurys-pfe/projets-disponibles
    // projets_pfe without a jury yet, scoped to chef's speciality.
    // Resolves speciality via utilisateurs.specialite_id — NO affectations.
    // ─────────────────────────────────────────────────────────────────
    public function projetsDisponibles(): JsonResponse
    {
        $chef = Auth::user();

        $projets = ProjetPfe::whereDoesntHave('jury')
            ->whereHas('etudiant', fn($q) => $q->where('specialite_id', $chef->specialite_id))
            ->with('etudiant', 'encadrant')
            ->get()
            ->map(fn($p) => [
                'id'            => $p->id,
                'titre'         => $p->titre ?? 'Sans titre',
                'etudiant_nom'  => trim(($p->etudiant->prenom ?? '') . ' ' . ($p->etudiant->nom ?? '')),
                'encadrant_nom' => trim(($p->encadrant->prenom ?? '') . ' ' . ($p->encadrant->nom ?? '')),
                'specialite'    => $p->specialite,
            ]);

        return response()->json($projets);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/jurys-pfe/etudiants-du-chef
    // All students in chef's speciality with their projet_pfe + jury info.
    // Source of truth: utilisateurs.specialite_id + projets_pfe.
    // NO affectations involved.
    // ─────────────────────────────────────────────────────────────────
    public function etudiantsDuChef(): JsonResponse
    {
        $chef = Auth::user();

        $etudiants = Utilisateur::where('role', 'etudiant')
            ->where('specialite_id', $chef->specialite_id)
            ->get();

        $result = $etudiants->map(function ($etudiant) {
            $projetPfe = ProjetPfe::with(['jury.membres.enseignant', 'encadrant'])
                ->where('etudiant_id', $etudiant->id)
                ->first();

            $jury = $projetPfe?->jury;

            return [
                'etudiant_id'   => $etudiant->id,
                'etudiant_nom'  => trim(($etudiant->prenom ?? '') . ' ' . ($etudiant->nom ?? '')),
                'matricule'     => $etudiant->matricule ?? '—',
                'encadrant_nom' => $projetPfe?->encadrant
                    ? trim(($projetPfe->encadrant->prenom ?? '') . ' ' . ($projetPfe->encadrant->nom ?? ''))
                    : '—',
                'encadrant_id'  => $projetPfe?->encadrant_id,
                'projet_pfe_id' => $projetPfe?->id,
                'projet_titre'  => $projetPfe?->titre,
                'jury_id'       => $jury?->id,
                'jury_statut'   => $jury?->statut,
                'membres'       => $jury ? $jury->membres->map(fn($m) => [
                    'id'            => $m->id,
                    'enseignant_id' => $m->enseignant_id,
                    'nom'           => trim(($m->enseignant->prenom ?? '') . ' ' . ($m->enseignant->nom ?? '')),
                    'fonction'      => $m->fonction,
                ]) : [],
            ];
        });

        return response()->json($result);
    }

    // GET /api/deliberation-pfe/mon-resultat
    public function monResultat(Request $request): JsonResponse
    {
        $etudiantId = $request->user()->id;

        $resultat = ResultatPfe::whereHas('jury.projet', fn($q) =>
            $q->where('etudiant_id', $etudiantId)
        )->where('publie', true)->with('jury.projet')->first();

        if (!$resultat) return response()->json(null);

        return response()->json([
            'note_finale'  => $resultat->note_finale,
            'mention'      => $resultat->mention,
            'decision'     => $resultat->decision,
            'publie_le'    => $resultat->publie_le?->format('d/m/Y'),
            'projet_titre' => $resultat->jury->projet->titre,
        ]);
    }

    // GET /api/resultats-pfe
    public function allResultats(): JsonResponse
    {
        $resultats = ResultatPfe::with(['jury.projet.etudiant', 'jury.projet.encadrant'])
            ->latest()
            ->get()
            ->map(fn($r) => [
                'id'           => $r->id,
                'etudiant_nom' => trim(($r->jury->projet->etudiant->prenom ?? '') . ' ' . ($r->jury->projet->etudiant->nom ?? '')),
                'projet_titre' => $r->jury->projet->titre,
                'note_finale'  => $r->note_finale,
                'mention'      => $r->mention,
                'decision'     => $r->decision,
                'publie'       => $r->publie,
            ]);

        return response()->json($resultats);
    }

    // ── helpers ────────────────────────────────────────────────────

    private function format(JuryPfe $j): array
    {
        $president = $j->membres?->firstWhere('fonction', 'president');

        return [
            'id'                => $j->id,
            'projet_id'         => $j->projet_id,
            'projet_titre'      => $j->projet?->titre ?? 'Sans titre',
            'etudiant_nom'      => trim((optional($j->projet?->etudiant)->prenom ?? '') . ' ' . (optional($j->projet?->etudiant)->nom ?? '')),
            'encadrant_nom'     => trim((optional($j->projet?->encadrant)->prenom ?? '') . ' ' . (optional($j->projet?->encadrant)->nom ?? '')),
            'date_soutenance'   => $j->date_soutenance,
            'heure_debut'       => $j->heure_debut,
            'heure_fin'         => $j->heure_fin,
            'salle'             => $j->salle,
            'statut'            => $j->statut,
            'calendrier_publie' => $j->calendrier_publie,
            'president'         => $president ? trim(($president->enseignant->prenom ?? '') . ' ' . ($president->enseignant->nom ?? '')) : null,
            'president_id'      => $president?->enseignant_id,
            'membres'           => $j->membres?->map(fn($m) => [
                'id'            => $m->id,
                'enseignant_id' => $m->enseignant_id,
                'nom'           => trim((optional($m->enseignant)->prenom ?? '') . ' ' . (optional($m->enseignant)->nom ?? '')),
                'email'         => optional($m->enseignant)->email,
                'fonction'      => $m->fonction,
            ]),
            'resultat' => $j->resultat ? [
                'note_finale' => $j->resultat->note_finale,
                'mention'     => $j->resultat->mention,
                'decision'    => $j->resultat->decision,
                'publie'      => $j->resultat->publie,
            ] : null,
        ];
    }

    private function getMention(float $note): string
    {
        return match(true) {
            $note >= 16 => 'Très bien',
            $note >= 14 => 'Bien',
            $note >= 12 => 'Assez bien',
            $note >= 10 => 'Passable',
            default     => 'Insuffisant',
        };
    }
}