<?php

namespace App\Modules\Core\Http\Resources;

use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical doctor shape shared by the index, edit, and mine pages.
 *
 * Requires `user` and `media` to be eager-loaded before wrapping.
 * Page-level flags (canManage, canEditSelf, hasOwnProfile, canCreateOwn)
 * are controller-level Inertia props — they do NOT live here.
 *
 * @mixin Doctor
 */
class DoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'email' => $this->user->email,
            'display_name' => $this->display_name,
            'title' => $this->title,
            'specialization' => $this->specialization,
            'bio' => $this->bio,
            'license_number' => $this->license_number,
            'certificate' => $this->certificate,
            'is_active' => $this->is_active,
            'avatar_url' => $this->imageUrl('avatar'),
            'is_self' => $this->user_id === $request->user()?->id,
        ];
    }
}
