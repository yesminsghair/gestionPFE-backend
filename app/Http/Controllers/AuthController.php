<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Utilisateur;
use App\Models\Compte;
use App\Mail\EmailVerificationMail;
use App\Mail\ResetPasswordMail;

class AuthController extends Controller
{
    // ══════════════════════════════════════════════
    // POST /api/login
    // ══════════════════════════════════════════════
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Charger l'utilisateur avec son compte en une seule requête
        $user = Utilisateur::with('compte')->where('email', $request->email)->first();

        // Email introuvable ou mot de passe incorrect
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email ou mot de passe incorrect.'], 401);
        }

        $compte = $user->compte;

        // Pas de compte encore (ne devrait pas arriver, mais sécurité)
        if (!$compte) {
            return response()->json(['message' => 'Compte introuvable. Contactez un administrateur.'], 403);
        }

        // Email non encore vérifié (token toujours présent)
        if ($compte->email_verification_token !== null && $compte->status === 'pending') {
            return response()->json(['message' => 'Veuillez confirmer votre adresse email avant de vous connecter.'], 403);
        }

        // Email vérifié mais en attente de validation admin
        if ($compte->status === 'pending' && $compte->email_verification_token === null) {
            return response()->json(['message' => 'Votre compte est en attente de validation par un administrateur.'], 403);
        }

        // Compte désactivé
        if ($compte->status === 'inactive') {
            return response()->json(['message' => 'Votre compte a été désactivé. Contactez un administrateur.'], 403);
        }

        // Génère le token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'id'        => $user->id,
            'nom'       => $user->nom,
            'prenom'    => $user->prenom,
            'email'     => $user->email,
            'role'      => $user->role,
            'matricule' => $user->matricule,
            'token'     => $token,
            'isAdmin'   => $user->role === 'admin',
        ]);
    }

    // ══════════════════════════════════════════════
    // POST /api/logout
    // ══════════════════════════════════════════════
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    // ══════════════════════════════════════════════
    // GET /api/me
    // ══════════════════════════════════════════════
    public function me(Request $request)
    {
        // Charge l'utilisateur avec son compte et sa spécialité
        $user = Utilisateur::with(['compte', 'specialite'])
                           ->find($request->user()->id);

        return response()->json([
            'id'               => $user->id,
            'nom'              => $user->nom,
            'prenom'           => $user->prenom,
            'email'            => $user->email,
            'role'             => $user->role,
            'matricule'        => $user->matricule,
            'etablissement'    => $user->etablissement,
            'domaine_expertise'=> $user->domaine_expertise,
            'specialite_id'    => $user->specialite_id,
            'specialite'       => $user->specialite,
            'date_affectation' => $user->date_affectation,
            'created_at'       => $user->created_at,
            // Champs compte
            'status'           => $user->compte?->status,
            'actif'            => $user->compte?->actif,
            'email_verified_at'=> $user->compte?->email_verified_at,
            'activated_at'     => $user->compte?->activated_at,
        ]);
    }

    // ══════════════════════════════════════════════
    // POST /api/inscription
    // ══════════════════════════════════════════════
    public function inscription(Request $request)
    {
        $request->validate([
            'nom'           => 'required|string|max:100',
            'prenom'        => 'required|string|max:100',
            'email'         => 'required|email|unique:utilisateurs,email',
            'password'      => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
            ],
            'role'          => 'required|in:encadrant,enseignant,etudiant',
            'matricule'     => 'nullable|string|max:50|regex:/^[A-Z]{2}-[0-9]{6}$/',
            'specialite_id' => 'nullable|exists:specialites,id',
        ], [
            'password.regex'        => 'Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre.',
            'email.unique'          => 'Cette adresse email est déjà associée à un compte.',
            'specialite_id.exists'  => 'La spécialité sélectionnée est invalide.',
            'matricule.regex'       => 'Le numéro d\'inscription doit être au format XX-000000 (ex: AD-597624).',
        ]);

        $verificationToken = Str::random(64);

        // 1. Créer l'utilisateur (sans status, sans token — c'est dans comptes)
        $user = Utilisateur::create([
            'nom'           => $request->nom,
            'prenom'        => $request->prenom,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'role'          => $request->role,
            'matricule'     => $request->matricule,
            'specialite_id' => $request->specialite_id,
            'etablissement' => 'Institut Supérieur d\'Informatique et de Mathématiques de Monastir',
        ]);

        // 2. Créer l'entrée dans comptes (status=pending, token présent)
        Compte::create([
            'utilisateur_id'           => $user->id,
            'email_verification_token' => $verificationToken,
            'email_verified_at'        => null,
            'status'                   => 'pending',
            'actif'                    => false,
            'activated_at'             => null,
        ]);

        // 3. Envoyer l'email de confirmation
        $verificationUrl = config('app.frontend_url') . '/verify-email/' . $verificationToken;
        Mail::to($user->email)->send(new EmailVerificationMail($verificationUrl, $user->prenom));

        return response()->json([
            'message' => 'Inscription réussie. Vérifiez votre email pour confirmer votre compte.',
        ], 201);
    }

    // ══════════════════════════════════════════════
    // GET /api/verify-email/{token}
    // ══════════════════════════════════════════════
    public function verifyEmail(string $token)
    {
        // Chercher le compte par son token (pas l'utilisateur)
        $compte = Compte::where('email_verification_token', $token)
                        ->with('utilisateur')
                        ->first();

        if (!$compte) {
            return response()->json(['message' => 'Lien de validation invalide ou déjà utilisé.'], 422);
        }

        // Token expiré (24h depuis la création du compte)
        if (Carbon::now()->diffInHours($compte->created_at) > 24) {
            // Supprimer le compte ET l'utilisateur (cascade)
            $compte->utilisateur?->delete();
            return response()->json(['message' => 'expired'], 410);
        }

        // Marquer l'email comme vérifié — effacer le token
        $compte->update([
            'email_verified_at'        => Carbon::now(),
            'email_verification_token' => null,
            // status reste 'pending' — l'admin valide ensuite
        ]);

        return response()->json([
            'message' => 'Email confirmé. Votre compte est en attente de validation par un administrateur.',
        ]);
    }

    // ══════════════════════════════════════════════
    // POST /api/forgot-password
    // ══════════════════════════════════════════════
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = Utilisateur::where('email', $request->email)->first();

        // Toujours le même message (sécurité — ne pas révéler si l'email existe)
        if (!$user) {
            return response()->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
        }

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        $token = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email'      => $request->email,
            'token'      => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $resetUrl = config('app.frontend_url') . '/reset-password/' . $token . '?email=' . urlencode($request->email);
        Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $user->prenom));

        return response()->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
    }

    // ══════════════════════════════════════════════
    // POST /api/reset-password
    // ══════════════════════════════════════════════
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'password.regex' => 'Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre.',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }

        if (!Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'expired'], 410);
        }

        $user = Utilisateur::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    // ══════════════════════════════════════════════
    // POST /api/change-password  (auth:sanctum)
    // ══════════════════════════════════════════════
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'password.regex' => 'Le nouveau mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }
}