<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Affectation;
use App\Models\Jury;
use App\Models\JuryMembre;
use App\Models\NoteJury;
use App\Models\Resultat;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JuryController extends Controller
{
    // GET /api/jurys
    public function index(): JsonResponse
    {
        $jurys = Jury::with([
            'affectation.etudiant',
            'affectation.encadrant',
            'membres.enseignant',
            'seances',
        ])->get()->map(function ($jury) {
            return [
                'id'            => $jury->id,
                'affectation_id'=> $jury->affectation_id,
                'projet_titre'  => $jury->affectation->titre_projet ?? '—',
                'etudiant_nom'  => optional($jury->affectation->etudiant)->nom . ' ' . optional($jury->affectation->etudiant)->prenom,
                'encadrant_nom' => optional($jury->affectation->encadrant)->nom . ' ' . optional($jury->affectation->encadrant)->prenom,
                'membres'       => $jury->membres->map(fn($m) => [
                    'id'       => $m->id,
                    'nom'      => optional($m->enseignant)->nom . ' ' . optional($m->enseignant)->prenom,
                    'email'    => optional($m->enseignant)->email,
                    'fonction' => $m->fonction,
                ]),
                'seances' => $jury->seances,
            ];
        });

        return response()->json($jurys);
    }

    // GET /api/jurys/{jury}
    public function show(Jury $jury): JsonResponse
    {
        return response()->json($jury->load('affectation', 'membres.enseignant', 'seances'));
    }

    // POST /api/jurys
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'affectation_id' => 'required|exists:affectations,id|unique:jurys,affectation_id',
        ]);

        $jury = Jury::create($data);
        // Le trigger SQL ajoute automatiquement l'encadrant comme membre

        return response()->json($jury->load('membres.enseignant', 'seances'), 201);
    }

    // DELETE /api/jurys/{jury}
    public function destroy(Jury $jury): JsonResponse
    {
        $jury->delete();
        return response()->json(['message' => 'Jury supprimé.']);
    }

    // POST /api/jurys/{jury}/membres
    public function addMembre(Request $request, Jury $jury): JsonResponse
    {
        $data = $request->validate([
            'enseignant_id' => 'required|exists:utilisateurs,id',
            'fonction'      => 'required|in:president,encadrant,examinateur',
        ]);

        $existe = JuryMembre::where('jury_id', $jury->id)
            ->where('enseignant_id', $data['enseignant_id'])
            ->exists();

        if ($existe) {
            return response()->json(['message' => 'Ce membre est déjà dans le jury.'], 422);
        }

        $membre = JuryMembre::create([
            'jury_id'       => $jury->id,
            'enseignant_id' => $data['enseignant_id'],
            'fonction'      => $data['fonction'],
            'created_at'    => now(),
        ]);

        // Notifier l'enseignant
        Notification::create([
            'user_id'    => $data['enseignant_id'],
            'message'    => 'Vous avez été affecté comme ' . $data['fonction'] . ' dans un jury PFE.',
            'created_at' => now(),
        ]);

        return response()->json($membre->load('enseignant'), 201);
    }

    // PUT /api/jurys/{jury}/membres/{membre}
    public function updateMembre(Request $request, Jury $jury, JuryMembre $membre): JsonResponse
    {
        $data = $request->validate([
            'fonction' => 'required|in:president,encadrant,examinateur',
        ]);

        $membre->update($data);

        return response()->json($membre->load('enseignant'));
    }

    // DELETE /api/jurys/{jury}/membres/{membre}
    public function removeMembre(Jury $jury, JuryMembre $membre): JsonResponse
    {
        $membre->delete();
        return response()->json(['message' => 'Membre retiré du jury.']);
    }

    // ─── ÉVALUATION ───────────────────────────────────────────────

    // GET /api/jurys/{jury}/notes
    public function getNotes(Jury $jury): JsonResponse
    {
        $notes = NoteJury::with('membre')
            ->where('jury_id', $jury->id)
            ->get();

        return response()->json($notes);
    }

    // POST /api/jurys/{jury}/notes
    public function saveNote(Request $request, Jury $jury): JsonResponse
    {
        $data = $request->validate([
            'membre_id'   => 'required|exists:utilisateurs,id',
            'note'        => 'required|numeric|min:0|max:20',
            'commentaire' => 'nullable|string',
            'finalise'    => 'boolean',
        ]);

        $note = NoteJury::updateOrCreate(
            ['jury_id' => $jury->id, 'membre_id' => $data['membre_id']],
            [
                'note'        => $data['note'],
                'commentaire' => $data['commentaire'] ?? null,
                'finalise'    => $data['finalise'] ?? false,
            ]
        );

        return response()->json($note);
    }

    // GET /api/notes-jury (pour l'onglet évaluation)
    public function getAllNotes(): JsonResponse
    {
        $notes = NoteJury::with(['jury.affectation.etudiant', 'membre'])
            ->latest()
            ->get()
            ->map(function ($note) {
                return [
                    'id'           => $note->id,
                    'projet_titre' => $note->jury?->affectation?->titre_projet ?? 'Projet',
                    'etudiant_nom' => optional($note->jury?->affectation?->etudiant)->nom . ' ' . optional($note->jury?->affectation?->etudiant)->prenom ?? 'Étudiant',
                    'membre_jury'  => optional($note->membre)->nom . ' ' . optional($note->membre)->prenom ?? 'Membre',
                    'date'         => $note->created_at?->format('d/m/Y') ?? '',
                    'note_totale'  => $note->note,
                    'criteres'     => [],
                    'commentaire'  => $note->commentaire,
                ];
            });

        return response()->json($notes);
    }

    // ─── DÉLIBÉRATION & RÉSULTATS ──────────────────────────────────

    // POST /api/jurys/{jury}/deliberer
    public function deliberer(Request $request, Jury $jury): JsonResponse
    {
        $notes = NoteJury::where('jury_id', $jury->id)->where('finalise', true)->get();

        if ($notes->isEmpty()) {
            return response()->json(['message' => 'Aucune note finalisée.'], 422);
        }

        $moyenne = $notes->avg('note');
        $mention = $this->getMention($moyenne);
        $decision = $moyenne >= 10 ? 'admis' : 'ajourne';

        $resultat = Resultat::updateOrCreate(
            ['affectation_id' => $jury->affectation_id],
            [
                'note_finale' => $moyenne,
                'mention'     => $mention,
                'decision'    => $decision,
            ]
        );

        return response()->json($resultat);
    }

    // POST /api/jurys/{jury}/publier-resultats
    public function publierResultats(Jury $jury): JsonResponse
    {
        $resultat = Resultat::where('affectation_id', $jury->affectation_id)->first();

        if (!$resultat) {
            return response()->json(['message' => 'Délibération non effectuée.'], 422);
        }

        $resultat->update(['publie' => true, 'publie_le' => now()]);

        // Notifier l'étudiant
        $etudiant_id = optional($jury->affectation)->etudiant_id;
        if ($etudiant_id) {
            Notification::create([
                'user_id'    => $etudiant_id,
                'message'    => 'Vos résultats de soutenance PFE sont disponibles.',
                'created_at' => now(),
            ]);
        }

        return response()->json($resultat);
    }

    // POST /api/deliberation/declencher
    public function declencherDeliberation(): JsonResponse
    {
        // Logique pour lancer la délibération globale
        return response()->json([
            'message' => 'Délibération lancée avec succès.',
            'lancee' => true,
            'resultats' => $this->allResultats()->getData()
        ]);
    }

    // POST /api/deliberation/publier
    public function publierTousResultats(): JsonResponse
    {
        Resultat::where('publie', false)->update([
            'publie' => true,
            'publie_le' => now()
        ]);

        return response()->json(['message' => 'Résultats publiés avec succès.', 'publies' => true]);
    }

    // GET /api/resultats
    public function allResultats(): JsonResponse
    {
        $resultats = Resultat::with(['affectation.etudiant', 'affectation.encadrant'])
            ->latest()
            ->get()
            ->map(function ($resultat) {
                return [
                    'id'              => $resultat->id,
                    'etudiant_nom'    => optional($resultat->affectation?->etudiant)->nom . ' ' . optional($resultat->affectation?->etudiant)->prenom ?? 'Étudiant',
                    'matricule'       => optional($resultat->affectation?->etudiant)->matricule ?? '—',
                    'projet_titre'    => $resultat->affectation?->titre_projet ?? 'Projet',
                    'note_jury'       => $resultat->note_finale ?? 0,
                    'note_encadrant'  => 0,
                    'note_finale'     => $resultat->note_finale ?? 0,
                    'mention'         => $resultat->mention,
                    'decision'        => $resultat->decision,
                    'publie'          => $resultat->publie,
                ];
            });

        return response()->json($resultats);
    }


    // GET /api/deliberation/mon-resultat  — résultat de l'étudiant connecté
    public function monResultat(Request $request): JsonResponse
    {
        $etudiantId = $request->user()->id;

        $affectation = \App\Models\Affectation::where('etudiant_id', $etudiantId)->first();

        if (!$affectation) {
            return response()->json(null);
        }

        $resultat = Resultat::where('affectation_id', $affectation->id)
            ->where('publie', true)
            ->first();

        if (!$resultat) {
            return response()->json(null);
        }

        return response()->json([
            'id'           => $resultat->id,
            'note_finale'  => $resultat->note_finale,
            'mention'      => $resultat->mention,
            'decision'     => $resultat->decision,
            'publie_le'    => $resultat->publie_le?->format('d/m/Y'),
            'projet_titre' => $affectation->titre_projet ?? '—',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function getMention(float $note): string
    {
        if ($note >= 16)      return 'Très bien';
        elseif ($note >= 14)  return 'Bien';
        elseif ($note >= 12)  return 'Assez bien';
        elseif ($note >= 10)  return 'Passable';
        else                  return 'Insuffisant';
    }
}