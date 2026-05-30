<?php

namespace App\Modules\Core\Repositories;

use App\Models\Clinic;

class ClinicRepository
{
    /**
     * Persist the validated field array onto the clinic and save.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Clinic $clinic, array $data): void
    {
        $clinic->fill($data)->save();
    }
}
