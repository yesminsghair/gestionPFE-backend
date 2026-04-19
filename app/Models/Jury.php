<?php
// ─────────────────────────────────────────────
// Jury.php
// ─────────────────────────────────────────────
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jury extends Model
{
    protected $table = 'jurys';
    protected $fillable = ['affectation_id'];

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class, 'affectation_id');
    }

    public function membres(): HasMany
    {
        return $this->hasMany(JuryMembre::class, 'jury_id');
    }

    public function seances(): HasMany
    {
        return $this->hasMany(SeanceSoutenance::class, 'jury_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(NoteJury::class, 'jury_id');
    }
}