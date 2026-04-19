<?php

use Illuminate\Support\Facades\Route;

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
use App\Http\Controllers\JuryController;
use App\Http\Controllers\SoutenanceController;
use App\Http\Controllers\LivrableController;
use App\Http\Controllers\ReunionController;
use App\Http\Controllers\SuiviController;
use App\Http\Controllers\NotificationController;

// ─────────────────────────────────────────────
// PUBLIC ROUTES
// ─────────────────────────────────────────────
Route::post('/login',               [AuthController::class, 'login']);
Route::post('/inscription',         [AuthController::class, 'inscription']);
Route::get('/verify-email/{token}', [AuthController::class, 'verifyEmail']);
Route::post('/forgot-password',     [AuthController::class, 'forgotPassword']);
Route::post('/reset-password',      [AuthController::class, 'resetPassword']);

// ─────────────────────────────────────────────
// PROTECTED ROUTES
// ─────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── AUTH ────────────────────────────────────────────────────────
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ── UTILISATEURS ────────────────────────────────────────────────
    Route::get('/utilisateurs/pending',        [UtilisateurController::class, 'pending']);
    Route::post('/utilisateurs/{id}/valider',  [UtilisateurController::class, 'valider']);
    Route::post('/utilisateurs/{id}/rejeter',  [UtilisateurController::class, 'rejeter']);
    Route::apiResource('utilisateurs',         UtilisateurController::class);

    // ── SPECIALITES ─────────────────────────────────────────────────
    Route::apiResource('specialites', SpecialiteController::class);

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
    Route::get   ('/formulaires-voeux',                              [FormulaireVoeuxController::class, 'index']);
    Route::post  ('/formulaires-voeux',                              [FormulaireVoeuxController::class, 'store']);
    Route::put   ('/formulaires-voeux/{id}',                         [FormulaireVoeuxController::class, 'update']);
    Route::patch ('/formulaires-voeux/{id}/publier',                 [FormulaireVoeuxController::class, 'publier']);
    Route::patch ('/formulaires-voeux/{id}/verrouiller',             [FormulaireVoeuxController::class, 'verrouiller']);
    Route::delete('/formulaires-voeux/{id}',                         [FormulaireVoeuxController::class, 'destroy']);
    Route::get   ('/formulaires-voeux/enseignants-de-ma-specialite', [FormulaireVoeuxController::class, 'enseignantsDeMaSpecialite']);

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

    // ── AFFECTATIONS ────────────────────────────────────────────────
    Route::prefix('affectations')->group(function () {
        Route::get('/',                           [AffectationController::class, 'index']);
        Route::get('/mode',                       [AffectationController::class, 'getMode']);
        Route::get('/mon-affectation',            [AffectationController::class, 'monAffectation']);
        Route::get('/encadrants-disponibles',     [AffectationController::class, 'encadrantsDisponibles']);
        Route::get('/mes-affectations',           [AffectationController::class, 'mesAffectations']);
        Route::get('/etudiants-de-ma-specialite', [AffectationController::class, 'etudiantsDeMaSpecialite']);
        Route::post('/save-mode',                 [AffectationController::class, 'saveMode']);
        Route::post('/batch',                     [AffectationController::class, 'batch']);
        Route::post('/diffuser',                  [AffectationController::class, 'diffuser']);
        Route::delete('/reinitialiser',           [AffectationController::class, 'reinitialiser']);
    });

    // ═══════════════════════════════════════════════════════════════
    // SPRINT 3
    // ═══════════════════════════════════════════════════════════════

    // ── PHASES ──────────────────────────────────────────────────────
    // IMPORTANT: /reorder MUST come before /{phase} to avoid route collision
    Route::prefix('phases')->group(function () {
        Route::get('/',           [PhaseController::class, 'index']);
        Route::post('/',          [PhaseController::class, 'store']);
        Route::put('/reorder',    [PhaseController::class, 'reorder']);
        Route::get('/{phase}',    [PhaseController::class, 'show']);
        Route::put('/{phase}',    [PhaseController::class, 'update']);
        Route::delete('/{phase}', [PhaseController::class, 'destroy']);
    });
    // NOTE: /suivi/lancer has been removed — activation is now via
    // PUT /phases/{phase} { active: true } by the chef.

    // ── GRILLES D'EVALUATION ────────────────────────────────────────
    Route::prefix('grilles')->group(function () {
        Route::get('/',                                          [GrilleEvaluationController::class, 'index']);
        Route::post('/',                                         [GrilleEvaluationController::class, 'store']);
        Route::get('/{grille}',                                  [GrilleEvaluationController::class, 'show']);
        Route::put('/{grille}',                                  [GrilleEvaluationController::class, 'update']);
        Route::delete('/{grille}',                               [GrilleEvaluationController::class, 'destroy']);
        // Chef submits to directeur for validation
        Route::post('/{grille}/publier',                         [GrilleEvaluationController::class, 'publier']);
        // Both chef (direct lock) AND directeur (validation lock) use this
        Route::post('/{grille}/verrouiller',                     [GrilleEvaluationController::class, 'verrouiller']);
        // Categories
        Route::post('/{grille}/categories',                      [GrilleEvaluationController::class, 'addCategorie']);
        Route::put('/{grille}/categories/{categorie}',           [GrilleEvaluationController::class, 'updateCategorie']);
        Route::delete('/{grille}/categories/{categorie}',        [GrilleEvaluationController::class, 'deleteCategorie']);
        // Criteres within a category
        Route::post('/{grille}/categories/{categorie}/criteres', [GrilleEvaluationController::class, 'addCritere']);
    });
    // Standalone critere routes
    Route::put('/criteres/{critere}',    [GrilleEvaluationController::class, 'updateCritere']);
    Route::delete('/criteres/{critere}', [GrilleEvaluationController::class, 'deleteCritere']);

    // ── JURY ────────────────────────────────────────────────────────
    Route::get('/notes-jury',                [JuryController::class, 'getAllNotes']);
    Route::get('/resultats',                 [JuryController::class, 'allResultats']);
    Route::post('/deliberation/declencher',  [JuryController::class, 'declencherDeliberation']);
    Route::post('/deliberation/publier',     [JuryController::class, 'publierTousResultats']);
    Route::get('/deliberation/mon-resultat', [JuryController::class, 'monResultat']);

    Route::prefix('jurys')->group(function () {
        Route::get('/',                           [JuryController::class, 'index']);
        Route::post('/',                          [JuryController::class, 'store']);
        Route::get('/{jury}',                     [JuryController::class, 'show']);
        Route::delete('/{jury}',                  [JuryController::class, 'destroy']);
        Route::post('/{jury}/membres',            [JuryController::class, 'addMembre']);
        Route::put('/{jury}/membres/{membre}',    [JuryController::class, 'updateMembre']);
        Route::delete('/{jury}/membres/{membre}', [JuryController::class, 'removeMembre']);
        Route::get('/{jury}/notes',               [JuryController::class, 'getNotes']);
        Route::post('/{jury}/notes',              [JuryController::class, 'saveNote']);
        Route::post('/{jury}/deliberer',          [JuryController::class, 'deliberer']);
        Route::post('/{jury}/publier-resultats',  [JuryController::class, 'publierResultats']);
    });

    // ── SOUTENANCES ─────────────────────────────────────────────────
    Route::prefix('soutenances')->group(function () {
        Route::get('/projets-disponibles',    [SoutenanceController::class, 'projetsDisponibles']);
        Route::get('/plans-proposes',         [SoutenanceController::class, 'plansProposes']);
        Route::post('/publier-calendrier',    [SoutenanceController::class, 'publierCalendrier']);
        Route::put('/plans/{plan}/valider',   [SoutenanceController::class, 'validerPlan']);
        Route::put('/plans/{plan}/rejeter',   [SoutenanceController::class, 'rejeterPlan']);
        Route::get('/',                       [SoutenanceController::class, 'index']);
        Route::post('/',                      [SoutenanceController::class, 'store']);
        Route::get('/{seance}',               [SoutenanceController::class, 'show']);
        Route::put('/{seance}',               [SoutenanceController::class, 'update']);
        Route::delete('/{seance}',            [SoutenanceController::class, 'destroy']);
        Route::post('/{seance}/terminer',     [SoutenanceController::class, 'terminer']);
        Route::post('/{seance}/annuler',      [SoutenanceController::class, 'annuler']);
        Route::put('/{seance}/affecter',      [SoutenanceController::class, 'affecterProjet']);
    });

    // ── LIVRABLES ───────────────────────────────────────────────────
    Route::prefix('livrables')->group(function () {
        Route::get('/phase/{phase}',          [LivrableController::class, 'byPhase']);
        Route::get('/',                       [LivrableController::class, 'index']);
        Route::post('/',                      [LivrableController::class, 'store']);
        Route::get('/{livrable}/download',    [LivrableController::class, 'download']);
        Route::put('/{livrable}/valider',     [LivrableController::class, 'valider']);
        Route::put('/{livrable}/rejeter',     [LivrableController::class, 'rejeter']);
        Route::put('/{livrable}/verrouiller', [LivrableController::class, 'verrouiller']);
        Route::get('/livrables/{livrable}/download',   [LivrableController::class, 'download']);
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
    // /suivi/lancer REMOVED — activation handled by PUT /phases/{phase}
    Route::prefix('suivi')->group(function () {
        Route::get('/encadrant',                        [SuiviController::class, 'parEncadrant']);
        Route::get('/etudiant',                         [SuiviController::class, 'parEtudiant']);
        Route::post('/valider',                         [SuiviController::class, 'validerPhase']);
        Route::post('/rejeter',                         [SuiviController::class, 'rejeterPhase']);
        Route::get('/historique/{affectationId}',       [SuiviController::class, 'historique']);
    });

    // ── NOTIFICATIONS ────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::put('/lire-tout',           [NotificationController::class, 'markAllAsRead']);
        Route::get('/non-lues/count',      [NotificationController::class, 'unreadCount']);
        Route::get('/',                    [NotificationController::class, 'index']);
        Route::put('/{notification}/lire', [NotificationController::class, 'markAsRead']);
        Route::delete('/{notification}',   [NotificationController::class, 'destroy']);
    });
});