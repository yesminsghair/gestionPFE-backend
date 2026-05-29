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
use App\Http\Controllers\JuryPfeController;
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
        Route::get('/',           [PhaseController::class, 'index']);
        Route::post('/',          [PhaseController::class, 'store']);
        Route::put('/reorder',    [PhaseController::class, 'reorder']);
        Route::get('/{phase}',    [PhaseController::class, 'show']);
        Route::put('/{phase}',    [PhaseController::class, 'update']);
        Route::delete('/{phase}', [PhaseController::class, 'destroy']);
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
        Route::post('/{grille}/fermer',                          [GrilleEvaluationController::class, 'fermer']);
        Route::post('/{grille}/categories',                      [GrilleEvaluationController::class, 'addCategorie']);
        Route::put('/{grille}/categories/{categorie}',           [GrilleEvaluationController::class, 'updateCategorie']);
        Route::delete('/{grille}/categories/{categorie}',        [GrilleEvaluationController::class, 'deleteCategorie']);
        Route::post('/{grille}/categories/{categorie}/criteres', [GrilleEvaluationController::class, 'addCritere']);
    });
    Route::put('/criteres/{critere}',    [GrilleEvaluationController::class, 'updateCritere']);
    Route::delete('/criteres/{critere}', [GrilleEvaluationController::class, 'deleteCritere']);

    // ── LIVRABLES ───────────────────────────────────────────────────
    Route::prefix('livrables')->group(function () {
        Route::get('/encadrant',              [LivrableController::class, 'parEncadrant']);
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
    });

    // ── SUIVI ───────────────────────────────────────────────────────
    Route::prefix('suivi')->group(function () {
        Route::get('/encadrant', [SuiviController::class, 'parEncadrant']);
        Route::get('/etudiant', [SuiviController::class, 'parEtudiant']);
        Route::post('/valider', [SuiviController::class, 'validerPhase']);
        Route::post('/rejeter', [SuiviController::class, 'rejeterPhase']);
        Route::get('/historique/{affectationId}', [SuiviController::class, 'historique']);
    });

    // ── JURY PFE / SOUTENANCE / RÉSULTATS ──────────────────────────
    // Explicit model binding: {membre} → JuryMembrePfe, {plan} → PlanSoutenance
    Route::model('membre', \App\Models\JuryMembrePfe::class);
    Route::model('plan',   \App\Models\PlanSoutenance::class);

    Route::prefix('jurys-pfe')->group(function () {
        // ⚠ Static routes MUST come before /{juryPfe} to avoid collision
        Route::get('/projets-disponibles',        [JuryPfeController::class, 'projetsDisponibles']);
        Route::get('/etudiants-du-chef',          [JuryPfeController::class, 'etudiantsDuChef']);
        Route::get('/prets-a-deliberer',          [JuryPfeController::class, 'pretsADeliberer']);
        Route::get('/mes-notes',                  [JuryPfeController::class, 'mesNotes']);
        Route::post('/publier-calendrier',        [JuryPfeController::class, 'publierCalendrier']);

        Route::get('/',         [JuryPfeController::class, 'index']);
        Route::post('/',        [JuryPfeController::class, 'store']);
        Route::get('/{juryPfe}',    [JuryPfeController::class, 'show']);
        Route::put('/{juryPfe}',    [JuryPfeController::class, 'update']);
        Route::delete('/{juryPfe}', [JuryPfeController::class, 'destroy']);

        Route::post('/{juryPfe}/membres',            [JuryPfeController::class, 'addMembre']);
        Route::put('/{juryPfe}/membres/{membre}',    [JuryPfeController::class, 'updateMembre']);
        Route::delete('/{juryPfe}/membres/{membre}', [JuryPfeController::class, 'removeMembre']);

        Route::get('/{juryPfe}/notes',      [JuryPfeController::class, 'getNotes']);
        Route::post('/{juryPfe}/notes',     [JuryPfeController::class, 'saveNote']);
        Route::get('/{juryPfe}/ma-note',    [JuryPfeController::class, 'maNoteDetail']);
        Route::post('/{juryPfe}/deliberer', [JuryPfeController::class, 'deliberer']);
        Route::post('/{juryPfe}/publier',   [JuryPfeController::class, 'publier']);
    });

    // ── Plans de soutenance (jury/encadrant → chef de département) ──
    Route::get('/plans-soutenance',                        [JuryPfeController::class, 'indexPlans']);
    Route::post('/plans-soutenance',                       [JuryPfeController::class, 'storePlan']);
    Route::put('/plans-soutenance/{plan}/valider',         [JuryPfeController::class, 'validerPlan']);
    Route::put('/plans-soutenance/{plan}/rejeter',         [JuryPfeController::class, 'rejeterPlan']);

    // ── RÉSULTATS & DÉLIBÉRATION ─────────────────────────────────────
    // ⚠ All static routes MUST come before /{resultat} wildcard routes
    Route::get ('/fiches-evaluation', [JuryPfeController::class, 'fichesEvaluation']);

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
    Route::get('/deliberation-pfe/mon-resultat',           [JuryPfeController::class, 'monResultat']);

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