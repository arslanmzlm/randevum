<?php

namespace App\Modules\Identity\Actions;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Modules\Identity\Services\ClinicRegistrationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify CreatesNewUsers implementation for self-service clinic onboarding.
 *
 * Validates the registration payload (user + clinic fields) then delegates
 * persistence to ClinicRegistrationService, which runs everything in a single
 * transaction: tenant → clinic → user → owner role.
 */
class RegisterClinicOwner implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private ClinicRegistrationService $registrationService) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        // Field display names for messages come from validation.attributes (lang files).
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => $this->passwordRules(),
            'vertical_id' => ['required', 'integer', Rule::exists('verticals', 'id')->where('is_active', true)],
            'clinic_name' => ['required', 'string', 'max:255'],
            'terms' => ['accepted'],
        ])->validate();

        return $this->registrationService->register($input);
    }
}
