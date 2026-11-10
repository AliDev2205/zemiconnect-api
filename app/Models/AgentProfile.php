<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentProfile extends Model
{
    protected $fillable = ['user_id', 'matricule', 'organization'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
