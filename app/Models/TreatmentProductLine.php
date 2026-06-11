<?php

namespace App\Models;

use Database\Factories\TreatmentProductLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TreatmentProductLine extends Model
{
    /** @use HasFactory<TreatmentProductLineFactory> */
    use HasFactory;

    protected $table = 'treatment_products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'treatment_id',
        'product_id',
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
            'treatment_id' => 'integer',
            'product_id' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * Soft-deleted products must still display their name on historical treatment records.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScope(SoftDeletingScope::class);
    }
}
