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
use App\Models\Notification;//modele notification
use App\Mail\EmailVerificationMail;
use App\Mail\ResetPasswordMail;

class AuthController extends Controller
{

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);
//retourne une selesct par email from utilisateurs et compte 
        $user = Utilisateur::with('compte')->where('email', $request->email)->first();
//utilisateur non trouvé ou mdp incorrect
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Email ou mot de passe incorrect.'], 401);
        }

        $compte = $user->compte;//recupere le compte 

        if (!$compte) {//verifier existance 
            return response()->json(['message' => 'Compte introuvable. Contactez un administrateur.'], 403);
        }
//compte nion verifier
        if ($compte->email_verification_token !== null && $compte->status === 'pending') {
            return response()->json(['message' => 'Veuillez confirmer votre adresse email avant de vous connecter.'], 403);
        }
//compte en attente validation 
        if ($compte->status === 'pending' && $compte->email_verification_token === null) {
            return response()->json(['message' => 'Votre compte est en attente de validation par un administrateur.'], 403);
        }
//compte desactivé par l'admin
        if ($compte->status === 'inactive') {
            return response()->json(['message' => 'Votre compte a été désactivé. Contactez un administrateur.'], 403);
        }
//creer un toke de connexion pour le stoké dans le local storage et pour eviter la reconnexion a chaque action
        $token = $user->createToken('auth_token')->plainTextToken;//crée par sactum

        return response()->json([ //dpnnées retourné dans la reponse
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

//req get du comp consulter profil
    public function me(Request $request)
    {//req select apartir de modele utilisateur et ses relation avec compte et specialité
        $user = Utilisateur::with(['compte', 'specialite'])
                           ->find($request->user()->id);//id de l'utilisateur connecté
//retourne un json avec toutes les données 
        return response()->json([
            'id'               => $user->id,
            'nom'              => $user->nom,
            'prenom'           => $user->prenom,
            'email'            => $user->email,
            'role'             => $user->role,
            'matricule'        => $user->matricule,
            'telephone'        => $user->telephone,
            'etablissement'    => $user->etablissement,
            'domaine_expertise'=> $user->domaine_expertise,
            'specialite_id'    => $user->specialite_id,
            'specialite'       => $user->specialite,
            'date_affectation' => $user->date_affectation,
            'created_at'       => $user->created_at,
            'status'           => $user->compte?->status,
            'actif'            => $user->compte?->actif,
            'email_verified_at'=> $user->compte?->email_verified_at,
            'activated_at'     => $user->compte?->activated_at,
        ]);
    }










  //req post from inscription
    public function inscription(Request $request)//objet request contient le corp du req post 
    {
        $request->validate([//si echoué retourne 422 : données invalide
            'nom'           => 'required|string|max:100',
            'prenom'        => 'required|string|max:100',
            'email'         => 'required|email|unique:utilisateurs,email',//'n'existe pas dans email de la table utilisateurs
            'password'      => [
                'required', 'string', 'min:8',
                //contenir au moin une maj,min,chif,caracspe
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*?&#]/',

            ],
            'role'          => 'required|in:enseignant,etudiant',
            'matricule'     => 'nullable|string|max:50|regex:/^[A-Z]{2}-[0-9]{6}$/',//2 majus - 6chiffre
            'specialite_id' => 'nullable|exists:specialites,id',
        ], [//msg error personnalisé
            'password.regex'        => 'Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre et un carctére spécial .',
            'email.unique'          => 'Cette adresse email est déjà associée à un compte.',
            'specialite_id.exists'  => 'La spécialité sélectionnée est invalide.',
            'matricule.regex'       => 'Le numéro d\'inscription doit être au format XX-000000 (ex: AD-597624).',
        ]);

        $verificationToken = Str::random(64);//clé secret(chaine 64 carac),st:facade

        //création uti
        $user = Utilisateur::create([
            'nom'           => $request->nom,
            'prenom'        => $request->prenom,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),//stocké pwd crypté par algo bcrypt hash
            'role'          => $request->role,
            'matricule'     => $request->matricule,
            'specialite_id' => $request->specialite_id,
            'etablissement' => 'Institut Supérieur d\'Informatique et de Mathématiques de Monastir',
        ]);

        //creation du compte
        Compte::create([
            'utilisateur_id'           => $user->id,
            'email_verification_token' => $verificationToken,
            'email_verified_at'        => null,
            'status'                   => 'pending',// au cours de creation
            'actif'                    => false,
            'activated_at'             => null,
        ]);

        // 3. Envoyer l'email de confirmation à l'utilisateur 
        $verificationUrl = config('app.frontend_url') . '/verify-email/' . $verificationToken;//recupere url du front depuis app.php, est la creation d'url de verif mail à envoyé 
        Mail::to($user->email)->send(new EmailVerificationMail($verificationUrl, $user->prenom));
//creer une instance de la classe emailverifmail avec les param : lien à envoyé et le distinataire 


        // 4. Créer une notification dans la table notifications pour chaque admin
        $roleLabel = match($user->role) {// un switch qui retourne le texte de chq role
            'etudiant'   => 'Étudiant',
            'enseignant' => 'Enseignant',
            'encadrant'  => 'Encadrant',
            'directeur'  => 'Directeur',
            default      => ucfirst($user->role),//sinon on met la 1ere role de user en majus
        };

        $admins = Utilisateur::where('role', 'admin')->get();//recupere admin
        foreach ($admins as $admin) {//chaq admin trouvé va recevoir la notif
            Notification::create([//creer notif
                'user_id' => $admin->id,
                'titre'   => 'Nouvelle demande de compte',
                'message' => "{$user->prenom} {$user->nom} ({$roleLabel}) a soumis une demande de création de compte.",
                'type'    => 'nouvelle_demande',
                'lu'      => false,
            ]);
        }

        return response()->json([//message de succés
            'message' => 'Inscription réussie. Vérifiez votre email pour confirmer votre compte.',
        ], 201);//code http de reussie de req
    }

 //la méthode verify mail d'inscription 
    public function verifyEmail(string $token)//recoit le token depuis le lien cliqué
    {//verifier que le compte existe deja par la comparison de token
        $compte = Compte::where('email_verification_token', $token)
                        ->with('utilisateur') // joint pour charger l'utilisateur du compte
                        ->first();//retourne premier res trouvé

        if (!$compte) {//null aucun compte trouvé
            return response()->json(['message' => 'Lien de validation invalide ou déjà utilisé.'], 422);
        }//422 données non traitable, exp token utilisé ou invalide
//cal dif entre now and created at si >24h 
        if (Carbon::now()->diffInHours($compte->created_at) > 24) {
            $compte->utilisateur?->delete();//acces par rel with et sup user 
            return response()->json(['message' => 'expired'], 410);//code http 420:gone , lien expiré, compte supprimé 
        }
//sinon mettre à jour le compte dans bd
        $compte->update([
            'email_verified_at'        => Carbon::now(),//enregitre date et heure
            'email_verification_token' => null,//mettre token pour eviter reutilisation
        ]);

        //envoi notif à l'admin
        $user = $compte->utilisateur;//stocke user dans une var
        if ($user) {//pour securité on verif qu'il existe 
            $roleLabel = match($user->role) {
                'etudiant'   => 'Étudiant',
                'enseignant' => 'Enseignant',
                'encadrant'  => 'Encadrant',
                'directeur'  => 'Directeur',
                default      => ucfirst($user->role),
            };

            $admins = Utilisateur::where('role', 'admin')->get();
            foreach ($admins as $admin) {
                Notification::create([
                    'user_id' => $admin->id,
                    'titre'   => 'Email confirmé — demande prête à examiner',
                    'message' => "{$user->prenom} {$user->nom} ({$roleLabel}) a confirmé son adresse email. Sa demande de création de compte peut maintenant être traitée.",
                    'type'    => 'email_confirme',
                    'lu'      => false,
                ]);
            }
        }

        return response()->json([
            'message' => 'Email confirmé. Votre compte est en attente de validation par un administrateur.',
        ]);
    }





//req de motdepasse oublié
//methode publique recoit objet request contenant le corp deu req post et envoi l'email
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);//obli , format valide

        $user = Utilisateur::where('email', $request->email)->first();//cherche user de l'email dans utilisateurs

        if (!$user) { //message de securité 
            return response()->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
        }
//nettoyage tokens d'email pour eviter les doublons
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
//crer u nv token
        $token = Str::random(64);
//insrt nouven enr dans la table
        DB::table('password_reset_tokens')->insert([
            'email'      => $request->email,
            'token'      => Hash::make($token),//hashé pour la securité
            'created_at' => Carbon::now(),
        ]);
//concaténation de lien avec le token
        $resetUrl = config('app.frontend_url') . '/reset-password/' . $token . '?email=' . urlencode($request->email);
        Mail::to($user->email)->send(new ResetPasswordMail($resetUrl, $user->prenom));//envoi mail 
//msg de securité 
        return response()->json(['message' => 'Si cet email existe, un lien de réinitialisation a été envoyé.']);
    }






//reinitialisermdp
//methode de reinitialisation de mdp
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
                'regex:/[@$!%*?&#]/',

            ],
        ], [//msg erreur personnalisé
            'password.regex' => 'Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre, et un carctére spécial .',
        ]);
//recherche on db l'email
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record) {//si aucun token code 422 lien invalideou expiré
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        } 
//sinon on compare le token recu avec le token hashé dans la base
        if (!Hash::check($request->token, $record->token)) {
            return response()->json(['message' => 'Lien invalide ou expiré.'], 422);
        }
//si la date de creation from now à passé 60 min le lien est expiré et on supprime le token
        if (Carbon::parse($record->created_at)->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json(['message' => 'expired'], 410);//410:expired
        }
//verifier si l'tilisateur existe dans la table utilisateur 
        $user = Utilisateur::where('email', $request->email)->first();//
        if (!$user) {//sinon msg erreu 
            return response()->json(['message' => 'Utilisateur introuvable.'], 404);
        }
//si oui remplace mdp par l nv hashé
        $user->update(['password' => Hash::make($request->password)]);
        //sup token pour eviter la reutilisation
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Mot de passe réinitialisé avec succès.']);
    }









//fonction de changement de password depuis modifier profil
    public function changePassword(Request $request)//objet request contient les données de la req
    {//on commence par validation des données 
        $request->validate([
            'current_password' => 'required|string',
            'password'         => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                 'regex:/[@$!%*?&#]/',


            ],
        ], [//msg err de pwd 
            'password.regex' => 'Le nouveau mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule et un chiffre et un carctére spécial .',
        ]);

        $user = $request->user(); //retourne lutili connecté grace à la middleware 
//compare pwd saisie par pwd hashé dans bd
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }
//sinn mettre à jour mdp dans bd par eloquent model
        $user->update(['password' => Hash::make($request->password)]);

        return response()->json(['message' => 'Mot de passe mis à jour avec succès.']);
    }
}