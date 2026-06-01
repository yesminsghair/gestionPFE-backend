<?php
//importation du façade/classe Route qui contient toutes les méthodes de définition des Routes
//le mecanisme centrale de routage 
use Illuminate\Support\Facades\Route;

//importation des classes de controleurs utilisés dans l'app ( chaq methode dans un controleur correspond à une méthode spécifique )
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SpecialiteController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\CompteController;
use App\Http\Controllers\ChefController;
use App\Http\Controllers\FormulaireVoeuxController;
use App\Http\Controllers\VoeuxEncadrementController;
use App\Http\Controllers\DemandeEncadrementController;
use App\Http\Controllers\AffectationController;
use App\Http\Controllers\PhaseController;
use App\Http\Controllers\GrilleEvaluationController;
use App\Http\Controllers\LivrableController;
use App\Http\Controllers\ReunionController;
use App\Http\Controllers\SuiviController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\JuryCompositionController;
use App\Http\Controllers\SoutenancePlanificationController;
use App\Http\Controllers\EvaluationPfeController;
use App\Http\Controllers\ResultatPfeController;
use App\Http\Controllers\ArchivageBiblioController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ChefDashboardController;
use App\Http\Controllers\DirecteurDashboardController;
use App\Http\Controllers\EncadrantDashboardController;
use App\Http\Controllers\AiChatController;

// ─────────────────────────────────────────────
// PUBLIC ROUTES : l'importation des routes accessible par tous ( meme sans etre connecté )
// ─────────────────────────────────────────────
Route::post('/login',               [AuthController::class, 'login']);
Route::post('/inscription',         [AuthController::class, 'inscription']);
Route::get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
Route::post('/forgot-password',     [AuthController::class, 'forgotPassword']);
Route::post('/reset-password',      [AuthController::class, 'resetPassword']);
Route::get('/specialites',          [SpecialiteController::class, 'index']); // public — needed for inscription form

// ─────────────────────────────────────────────
// PROTECTED ROUTES
// ─────────────────────────────────────────────
Broadcast::routes(['middleware' => ['auth:sanctum']]);
Route::middleware('auth:sanctum')->group(function () {

    // ── AUTH ────────────────────────────────────────────────────────
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',               [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // ── UTILISATEURS ────────────────────────────────────────────────
    Route::get('/utilisateurs/pending',        [UtilisateurController::class, 'pending']);
    Route::post('/utilisateurs/{id}/valider',  [UtilisateurController::class, 'valider']);
    Route::post('/utilisateurs/{id}/rejeter',  [UtilisateurController::class, 'rejeter']);
    Route::apiResource('utilisateurs',         UtilisateurController::class);

    // ── SPECIALITES ─────────────────────────────────────────────────
    Route::apiResource('specialites', SpecialiteController::class)->except(['index']);

    // ── COMPTES ─────────────────────────────────────────────────────
    Route::apiResource('comptes', CompteController::class);

    // ── CHEFS ───────────────────────────────────────────────────────
    Route::get('/chefs',                [ChefController::class, 'index']);
    Route::get('/chefs/rechercher',     [ChefController::class, 'rechercher']);
    Route::post('/chefs/promouvoir',    [ChefController::class, 'promouvoir']);
    Route::post('/chefs/{id}/affecter', [ChefController::class, 'affecter']);
    Route::post('/chefs/{id}/retirer',  [ChefController::class, 'retirer']);
    Route::put('/chefs/{id}/modifier',  [ChefController::class, 'modifier']);

    // ── FORMULAIRES DE VOEUX ────────────────────────────────────────
    // ⚠ Static GET routes must come before {id} wildcard routes.
    Route::get   ('/formulaires-voeux',                              [FormulaireVoeuxController::class, 'index']);
    Route::get   ('/formulaires-voeux/enseignants-de-ma-specialite', [FormulaireVoeuxController::class, 'enseignantsDeMaSpecialite']);
    Route::post  ('/formulaires-voeux',                              [FormulaireVoeuxController::class, 'store']);
    // Wildcard {id} routes below
    Route::get   ('/formulaires-voeux/{id}/reponses',                [FormulaireVoeuxController::class, 'reponses']);
    Route::put   ('/formulaires-voeux/{id}',                         [FormulaireVoeuxController::class, 'update']);
    Route::patch ('/formulaires-voeux/{id}/publier',                 [FormulaireVoeuxController::class, 'publier']);
    Route::patch ('/formulaires-voeux/{id}/verrouiller',             [FormulaireVoeuxController::class, 'verrouiller']);
    Route::delete('/formulaires-voeux/{id}',                         [FormulaireVoeuxController::class, 'destroy']);

    // ── VOEUX D'ENCADREMENT ─────────────────────────────────────────
    Route::get ('/voeux-encadrement',       [VoeuxEncadrementController::class, 'index']);
    Route::get ('/voeux-encadrement/liste', [VoeuxEncadrementController::class, 'liste']);
    Route::post('/voeux-encadrement',       [VoeuxEncadrementController::class, 'store']);

    // ── DEMANDES D'ENCADREMENT ──────────────────────────────────────
    Route::get   ('/demandes-encadrement',               [DemandeEncadrementController::class, 'index']);
    Route::post  ('/demandes-encadrement',               [DemandeEncadrementController::class, 'store']);
    Route::put   ('/demandes-encadrement/{id}',          [DemandeEncadrementController::class, 'update']);
    Route::delete('/demandes-encadrement/{id}',          [DemandeEncadrementController::class, 'destroy']);
    Route::post  ('/demandes-encadrement/{id}/accepter', [DemandeEncadrementController::class, 'accepter']);
    Route::post  ('/demandes-encadrement/{id}/rejeter',  [DemandeEncadrementController::class, 'rejeter']);
    Route::post  ('/demandes-encadrement/{id}/modifier', [DemandeEncadrementController::class, 'modifier']);
    Route::delete('/demandes-encadrement/{id}/reset',   [DemandeEncadrementController::class, 'reset']);

    // ── AFFECTATIONS ────────────────────────────────────────────────
    // ⚠ All static/named routes MUST come before any {id} wildcard routes.
    Route::prefix('affectations')->group(function () {
        // Named GET routes (no wildcards)
        Route::get('/mode',                       [AffectationController::class, 'getMode']);
        Route::get('/mon-affectation',            [AffectationController::class, 'monAffectation']);
        Route::get('/encadrants-disponibles',     [AffectationController::class, 'encadrantsDisponibles']);
        Route::get('/mes-affectations',           [AffectationController::class, 'mesAffectations']);
        Route::get('/mes-etudiants',              [AffectationController::class, 'mesEtudiants']);
        Route::get('/etudiants-de-ma-specialite', [AffectationController::class, 'etudiantsDeMaSpecialite']);
        // FIX: contraintes routes were missing — GET must be above any {id} wildcard
        Route::get('/contraintes',                [AffectationController::class, 'indexContraintes']);

        // Named POST / PUT / DELETE routes
        Route::post('/save-mode',                 [AffectationController::class, 'saveMode']);
        Route::post('/notifier-mode',             [AffectationController::class, 'notifierMode']);
        Route::post('/batch',                     [AffectationController::class, 'batch']);
        Route::post('/diffuser',                  [AffectationController::class, 'diffuser']);
        // FIX: contraintes POST was missing
        Route::post('/contraintes',               [AffectationController::class, 'storeContraintes']);
        Route::put('/mon-affectation/sujet',      [AffectationController::class, 'saveSujet']);
        Route::delete('/reinitialiser',           [AffectationController::class, 'reinitialiser']);

        // Collection route last
        Route::get('/',                           [AffectationController::class, 'index']);
    });

    // ═══════════════════════════════════════════════════════════════
    // SPRINT 3
    // ═══════════════════════════════════════════════════════════════

    // ── PHASES ──────────────────────────────────────────────────────
    Route::prefix('phases')->group(function () {
        Route::get('/',                [PhaseController::class, 'index']);
        Route::post('/',               [PhaseController::class, 'store']);
        Route::put('/reorder',         [PhaseController::class, 'reorder']);

        // ⚠ Static named routes MUST come before /{phase} wildcard
        Route::get('/livrable-stats',  [PhaseController::class, 'livrableStats']);
        Route::post('/reinitialiser',  [PhaseController::class, 'reinitialiser']);

        // Wildcard routes last
        Route::get('/{phase}',         [PhaseController::class, 'show']);
        Route::put('/{phase}',         [PhaseController::class, 'update']);
        Route::delete('/{phase}',      [PhaseController::class, 'destroy']);
    });

    // ── GRILLES D'EVALUATION ────────────────────────────────────────
    Route::prefix('grilles')->group(function () {
        Route::get('/',                                          [GrilleEvaluationController::class, 'index']);
        Route::post('/',                                         [GrilleEvaluationController::class, 'store']);
        Route::get('/{grille}',                                  [GrilleEvaluationController::class, 'show']);
        Route::put('/{grille}',                                  [GrilleEvaluationController::class, 'update']);
        Route::delete('/{grille}',                               [GrilleEvaluationController::class, 'destroy']);
        Route::post('/{grille}/publier',                         [GrilleEvaluationController::class, 'publier']);
        Route::post('/{grille}/verrouiller',                     [GrilleEvaluationController::class, 'verrouiller']);
        Route::post('/{grille}/rejeter',                         [GrilleEvaluationController::class, 'rejeter']);
        Route::post('/{grille}/activer',                         [GrilleEvaluationController::class, 'activer']);
        Route::post('/{grille}/fermer',                          [GrilleEvaluationController::class, 'fermer']);
        Route::post('/{grille}/categories',                      [GrilleEvaluationController::class, 'addCategorie']);
        Route::put('/{grille}/categories/{categorie}',           [GrilleEvaluationController::class, 'updateCategorie']);
        Route::delete('/{grille}/categories/{categorie}',        [GrilleEvaluationController::class, 'deleteCategorie']);
        Route::post('/{grille}/categories/{categorie}/criteres', [GrilleEvaluationController::class, 'addCritere']);
        Route::post('{grille}/reinitialiser', [GrilleEvaluationController::class, 'reinitialiser']);
    });
    Route::put('/criteres/{critere}',    [GrilleEvaluationController::class, 'updateCritere']);
    Route::delete('/criteres/{critere}', [GrilleEvaluationController::class, 'deleteCritere']);

    // ── LIVRABLES ───────────────────────────────────────────────────
    Route::prefix('livrables')->group(function () {
        // ⚠ Static named routes MUST come before /{livrable} wildcard
        Route::get('/encadrant',              [LivrableController::class, 'parEncadrant']);
        Route::get('/historique',             [LivrableController::class, 'historique']);
        Route::get('/phase/{phase}',          [LivrableController::class, 'byPhase']);
        Route::get('/',                       [LivrableController::class, 'index']);
        Route::post('/',                      [LivrableController::class, 'store']);
        Route::get('/{livrable}/download',    [LivrableController::class, 'download']);
        Route::put('/{livrable}/valider',     [LivrableController::class, 'valider']);
        Route::put('/{livrable}/rejeter',     [LivrableController::class, 'rejeter']);
        Route::put('/{livrable}/verrouiller', [LivrableController::class, 'verrouiller']);
        Route::delete('/{livrable}',          [LivrableController::class, 'destroy']);
    });

    // ── REUNIONS ────────────────────────────────────────────────────
    Route::prefix('reunions')->group(function () {
        Route::get('/',                        [ReunionController::class, 'index']);
        Route::post('/',                       [ReunionController::class, 'store']);
        Route::get('/{reunion}',               [ReunionController::class, 'show']);
        Route::put('/{reunion}',               [ReunionController::class, 'update']);
        Route::delete('/{reunion}',            [ReunionController::class, 'destroy']);
        Route::post('/{reunion}/confirmer',    [ReunionController::class, 'confirmer']);
        Route::post('/{reunion}/annuler',      [ReunionController::class, 'annuler']);
        Route::post('/{reunion}/compte-rendu', [ReunionController::class, 'compteRendu']);
        Route::post('/{reunion}/rappel',        [ReunionController::class, 'rappel']);
        Route::post('/{reunion}/rappel/annuler', [ReunionController::class, 'annulerRappel']);
    });

    // ── SUIVI ───────────────────────────────────────────────────────
    Route::prefix('suivi')->group(function () {
        Route::get('/encadrant', [SuiviController::class, 'parEncadrant']);
        Route::get('/etudiant', [SuiviController::class, 'parEtudiant']);
        Route::post('/valider', [SuiviController::class, 'validerPhase']);
        Route::post('/rejeter', [SuiviController::class, 'rejeterPhase']);
        Route::get('/historique/{affectationId}', [SuiviController::class, 'historique']);
    });

    // ── PROJETS PFE (étudiant) ──────────────────────────────────────
    Route::prefix('projets')->group(function () {
        // GET /api/projets/mon-projet — returns the authenticated student's ProjetPfe
        Route::get('/mon-projet', function () {
            $projet = \App\Models\ProjetPfe::where('etudiant_id', auth()->id())->first();
            return response()->json($projet); // null if none — frontend handles it
        });
    });

    // ── JURY PFE : COMPOSITION ───────────────────────────────────────
    // Explicit model binding: {plan} → PlanSoutenance
    Route::model('plan', \App\Models\PlanSoutenance::class);

    Route::prefix('jurys-pfe')->group(function () {
        // ⚠ Static routes MUST come before /{juryPfe} to avoid collision

        // -- JuryCompositionController (static) --
        Route::get('/etudiants-du-chef',       [JuryCompositionController::class, 'etudiantsDuChef']);
        Route::get('/enseignants-departement', [JuryCompositionController::class, 'enseignantsDuDepartement']);

        // -- SoutenancePlanificationController (static) --
        // ⚠ MUST be here, before /{juryPfe}, otherwise Laravel routes POST /jurys-pfe/publier-calendrier
        //   into the /{juryPfe} wildcard and returns 405 / model-not-found.
        Route::post('/publier-calendrier', [SoutenancePlanificationController::class, 'publierCalendrier']);

        // -- EvaluationPfeController (static) --
        Route::get('/prets-a-deliberer', [EvaluationPfeController::class, 'pretsADeliberer']);
        Route::get('/mes-notes',         [EvaluationPfeController::class, 'mesNotes']);

        // ── Wildcard routes below ──────────────────────────────────────
        Route::get('/',             [JuryCompositionController::class, 'index']);
        Route::post('/',            [JuryCompositionController::class, 'store']);
        Route::get('/{juryPfe}',    [JuryCompositionController::class, 'show']);
        Route::delete('/{juryPfe}', [JuryCompositionController::class, 'destroy']);

        // SoutenancePlanificationController — update date/salle/heure/statut
        Route::put('/{juryPfe}', [SoutenancePlanificationController::class, 'update']);

        // Role assignment (flat columns: president_id / examinateur_id)
        Route::put('/{juryPfe}/president',     [JuryCompositionController::class, 'setPresident']);
        Route::put('/{juryPfe}/examinateur',   [JuryCompositionController::class, 'setExaminateur']);
        Route::delete('/{juryPfe}/president',  [JuryCompositionController::class, 'clearPresident']);
        Route::delete('/{juryPfe}/examinateur',[JuryCompositionController::class, 'clearExaminateur']);

        Route::post('/{juryPfe}/publier-jury',  [JuryCompositionController::class, 'publierJury']);
        Route::post('/{juryPfe}/modifier-jury', [JuryCompositionController::class, 'modifierJury']);

        Route::get('/{juryPfe}/notes',      [EvaluationPfeController::class, 'getNotes']);
        Route::post('/{juryPfe}/notes',     [EvaluationPfeController::class, 'saveNote']);
        Route::get('/{juryPfe}/ma-note',    [EvaluationPfeController::class, 'maNoteDetail']);
        Route::post('/{juryPfe}/deliberer', [EvaluationPfeController::class, 'deliberer']);
        Route::post('/{juryPfe}/publier',   [EvaluationPfeController::class, 'publier']);
    });

    // ── SOUTENANCE : PLANIFICATION ───────────────────────────────────
    // ⚠ publier-calendrier is now inside the jurys-pfe prefix group above (before /{juryPfe})
    Route::get('/soutenances/salles-occupees',      [SoutenancePlanificationController::class, 'sallesOccupees']);
    Route::get('/soutenances/enseignants-occupes',  [SoutenancePlanificationController::class, 'enseignantsOccupes']);

    Route::get   ('/plans-soutenance',                    [SoutenancePlanificationController::class, 'indexPlans']);
    Route::post  ('/plans-soutenance',                    [SoutenancePlanificationController::class, 'storePlan']);
    Route::put   ('/plans-soutenance/{plan}/valider',     [SoutenancePlanificationController::class, 'validerPlan']);
    Route::put   ('/plans-soutenance/{plan}/rejeter',     [SoutenancePlanificationController::class, 'rejeterPlan']);
    Route::delete('/plans-soutenance/{plan}',             [SoutenancePlanificationController::class, 'destroyPlan']);
    // Called by chef OR proposant to delete a rejected plan
    Route::delete('/plans-soutenance/{plan}/chef',        [SoutenancePlanificationController::class, 'destroyPlanChef']);
    // Transitions soutenance statut → 'termine' when evaluation is submitted
    Route::put('/soutenances/{soutenance}/terminer',      [SoutenancePlanificationController::class, 'terminerSoutenance']);

    // ── ÉVALUATION : FICHES & RÉSULTATS ─────────────────────────────
    // ⚠ All static routes MUST come before /{resultat} wildcard routes
    Route::get('/fiches-evaluation', [EvaluationPfeController::class, 'fichesEvaluation']);

    Route::prefix('resultats-pfe')->group(function () {
        // Static routes first
        Route::get   ('/',               [ResultatPfeController::class, 'index']);         // ALL non-archived → ConsulterResultatFinal.vue
        Route::get   ('/publies',        [ResultatPfeController::class, 'publies']);       // publie=true only → student-facing
        Route::post  ('/publier-tous',   [ResultatPfeController::class, 'publierTous']);   // bulk publish
        Route::post  ('/archiver-tous',  [ResultatPfeController::class, 'archiverTous']);  // bulk archive
        Route::get   ('/archives',       [ArchivageBiblioController::class, 'archives']);      // Archives.vue
        Route::delete('/archives/{date}',[ArchivageBiblioController::class, 'supprimerArchive']); // delete archive group
        Route::get   ('/bibliotheque',   [ArchivageBiblioController::class, 'bibliotheque']);  // BiblioPfe.vue

        // Wildcard routes LAST
        Route::post('/{resultat}/publier',      [ResultatPfeController::class, 'publier']);           // single publish
        Route::post('/{resultat}/decision',     [ResultatPfeController::class, 'decision']);          // toggle admis/ajourne
        Route::post('/{resultat}/archiver',     [ResultatPfeController::class, 'archiver']);          // single archive
        Route::post('/{resultat}/bibliotheque', [ResultatPfeController::class, 'ajouterBibliotheque']); // biblio toggle
    });

    // ── ÉTUDIANT : consulter son résultat ────────────────────────────
    Route::get('/deliberation-pfe/mon-resultat', [EvaluationPfeController::class, 'monResultat']);

    // ── NOTIFICATIONS ────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::put('/lire-tout',           [NotificationController::class, 'markAllAsRead']);
        Route::get('/non-lues/count',      [NotificationController::class, 'unreadCount']);
        Route::get('/',                    [NotificationController::class, 'index']);
        Route::put('/{notification}/lire', [NotificationController::class, 'markAsRead']);
        Route::delete('/{notification}',   [NotificationController::class, 'destroy']);
    });

    // ── MESSAGERIE ───────────────────────────────────────────────────
    Route::prefix('conversations')->group(function () {
        Route::get('/',                         [MessageController::class, 'conversations']);
        Route::post('/',                        [MessageController::class, 'createConversation']);
        Route::get('/{conversation}/messages',  [MessageController::class, 'messages']);
        Route::post('/{conversation}/messages', [MessageController::class, 'sendMessage']);
        Route::put('/{conversation}/lire',      [MessageController::class, 'markConversationRead']);
    });

    // ── TABLEAUX DE BORD (GIMSI) ─────────────────────────────────────
    Route::prefix('dashboard')->group(function () {
        Route::get('/chef',      [ChefDashboardController::class, 'index']);
        Route::get('/directeur', [DirecteurDashboardController::class, 'index']);
        Route::get('/encadrant', [EncadrantDashboardController::class, 'index']);
    });

    // ── CHATBOT IA ───────────────────────────────────────────────────
    Route::post('/ai-chat', [AiChatController::class, 'chat']);
});