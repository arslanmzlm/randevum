<?php

namespace App\Models;

use App\Enums\SmsType;
use App\Models\Concerns\BelongsToClinic;
use Database\Factories\ClinicSmsSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicSmsSetting extends Model
{
    /** @use HasFactory<ClinicSmsSettingFactory> */
    use BelongsToClinic, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'clinic_id',
        'sms_type',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'sms_type' => SmsType::class,
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
