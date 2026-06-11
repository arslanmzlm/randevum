<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vertical-specific treatment detail (morph target for treatments.details).
 * No clinic scope — always reached through its owning Treatment.
 */
class PodiatryTreatmentDetail extends Model
{
    protected $table = 'podiatry_treatment_details';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'complaint',
        'diagnosis',
        'treatment_process',
    ];
}
