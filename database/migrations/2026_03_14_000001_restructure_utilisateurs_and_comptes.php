<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // ══════════════════════════════════════════════════════════════
    // UP — Restructuration des tables utilisateurs et comptes
    //
    // AVANT :
    //   utilisateurs : ..., email_verification_token, email_verified_at,
    //                  status, ...
    //   comptes      : id, utilisateur_id, actif, activated_at
    //
    // APRÈS :
    //   utilisateurs : id, nom, prenom, email, password, matricule,
    //                  role, etablissement, domaine_expertise,
    //                  specialite_id, date_affectation,
    //                  created_at, updated_at
    //   comptes      : id, utilisateur_id, email_verification_token,
    //                  email_verified_at, status, actif,
    //                  activated_at, created_at, updated_at
    // ══════════════════════════════════════════════════════════════
    public function up(): void
    {
        // ── ÉTAPE 1 : Ajouter les nouvelles colonnes dans comptes ──────
        Schema::table('comptes', function (Blueprint $table) {
            $table->string('email_verification_token', 255)->nullable()->after('utilisateur_id');
            $table->timestamp('email_verified_at')->nullable()->after('email_verification_token');
            $table->enum('status', ['pending', 'active', 'inactive'])->default('pending')->after('email_verified_at');
            // actif et activated_at existent déjà
        });

        // ── ÉTAPE 2 : Migrer les données existantes ────────────────────
        // Pour chaque utilisateur qui a déjà un compte dans comptes,
        // copier email_verification_token, email_verified_at et status
        DB::statement('
            UPDATE comptes c
            INNER JOIN utilisateurs u ON u.id = c.utilisateur_id
            SET
                c.email_verification_token = u.email_verification_token,
                c.email_verified_at        = u.email_verified_at,
                c.status                   = u.status
        ');

        // Pour les utilisateurs qui n'ont PAS encore d'entrée dans comptes
        // (ex : comptes admin/directeur créés avant la table comptes),
        // créer une entrée avec leurs données actuelles
        DB::statement('
            INSERT INTO comptes (utilisateur_id, email_verification_token, email_verified_at, status, actif, activated_at, created_at, updated_at)
            SELECT
                u.id,
                u.email_verification_token,
                u.email_verified_at,
                u.status,
                CASE WHEN u.status = \'active\' THEN 1 ELSE 0 END,
                CASE WHEN u.status = \'active\' THEN u.updated_at ELSE NULL END,
                NOW(),
                NOW()
            FROM utilisateurs u
            WHERE NOT EXISTS (
                SELECT 1 FROM comptes c WHERE c.utilisateur_id = u.id
            )
        ');

        // ── ÉTAPE 3 : Supprimer les colonnes de utilisateurs ──────────
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropColumn([
                'email_verification_token',
                'email_verified_at',
                'status',
            ]);
        });
    }

    // ══════════════════════════════════════════════════════════════
    // DOWN — Annulation (rollback)
    // ══════════════════════════════════════════════════════════════
    public function down(): void
    {
        // ── Remettre les colonnes dans utilisateurs ────────────────────
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->string('email_verification_token', 255)->nullable()->after('email');
            $table->timestamp('email_verified_at')->nullable()->after('email_verification_token');
            $table->enum('status', ['pending', 'active', 'inactive'])->default('pending')->after('email_verified_at');
        });

        // ── Recopier les données depuis comptes ────────────────────────
        DB::statement('
            UPDATE utilisateurs u
            INNER JOIN comptes c ON c.utilisateur_id = u.id
            SET
                u.email_verification_token = c.email_verification_token,
                u.email_verified_at        = c.email_verified_at,
                u.status                   = c.status
        ');

        // ── Supprimer les colonnes ajoutées dans comptes ───────────────
        Schema::table('comptes', function (Blueprint $table) {
            $table->dropColumn([
                'email_verification_token',
                'email_verified_at',
                'status',
            ]);
        });
    }
};