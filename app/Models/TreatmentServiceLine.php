<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Database\Factories\TreatmentServiceLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TreatmentServiceLine extends Model
{
    /** @use HasFactory<TreatmentServiceLineFactory> */
    use BelongsToClinic, HasFactory;

    // Avoids a class-name collision with App\Modules\Medical\Services\TreatmentService
    protected $table = 'treatment_services';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'treatment_id',
        'service_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'subtotal',
        'note',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'treatment_id' => 'integer',
            'service_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * Soft-deleted services must still display their name on historical treatment records.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withoutGlobalScope(SoftDeletingScope::class);
    }
}
