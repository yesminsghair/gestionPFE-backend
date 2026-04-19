<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── utilisateurs : ajouter telephone, domaine_expertise, date_affectation ──
        Schema::table('utilisateurs', function (Blueprint $table) {
            if (!Schema::hasColumn('utilisateurs', 'telephone')) {
                $table->string('telephone', 20)->nullable()->after('email');
            }
            if (!Schema::hasColumn('utilisateurs', 'domaine_expertise')) {
                $table->string('domaine_expertise', 255)->nullable()->after('etablissement');
            }
            if (!Schema::hasColumn('utilisateurs', 'date_affectation')) {
                $table->date('date_affectation')->nullable()->after('domaine_expertise');
            }
        });

        // ── demandes_encadrement : s'assurer que doc_pdf, motif_rejet, traite_at existent ──
        Schema::table('demandes_encadrement', function (Blueprint $table) {
            if (!Schema::hasColumn('demandes_encadrement', 'doc_pdf')) {
                $table->string('doc_pdf', 255)->nullable()->after('date_demande');
            }
            if (!Schema::hasColumn('demandes_encadrement', 'motif_rejet')) {
                $table->text('motif_rejet')->nullable()->after('doc_pdf');
            }
            if (!Schema::hasColumn('demandes_encadrement', 'traite_at')) {
                $table->timestamp('traite_at')->nullable()->after('motif_rejet');
            }
            if (!Schema::hasColumn('demandes_encadrement', 'numero')) {
                $table->string('numero', 50)->nullable()->unique()->after('id');
            }
        });

        // ── affectations : s'assurer que diffuse_at existe ──
        Schema::table('affectations', function (Blueprint $table) {
            if (!Schema::hasColumn('affectations', 'diffuse_at')) {
                $table->timestamp('diffuse_at')->nullable()->after('statut');
            }
        });
    }

    public function down(): void
    {
        Schema::table('utilisateurs', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('utilisateurs', 'telephone')        ? 'telephone'        : null,
                Schema::hasColumn('utilisateurs', 'domaine_expertise')? 'domaine_expertise': null,
                Schema::hasColumn('utilisateurs', 'date_affectation') ? 'date_affectation' : null,
            ]));
        });
    }
};