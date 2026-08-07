<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\FollowUpType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FollowUpType
 */
class FollowUpTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
        ];
    }
}
