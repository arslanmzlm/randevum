<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Messages (English)
    |--------------------------------------------------------------------------
    |
    | Toast summaries and feature strings, namespaced by model.
    | Frontend mirrors live in resources/js/i18n.ts under matching namespaces.
    |
    */

    'clinic' => [
        'profile_updated' => 'Clinic profile updated.',
        'media_updated' => 'Image updated.',
        'media_removed' => 'Image removed.',
    ],

    'service' => [
        'created' => 'Service created successfully.',
        'updated' => 'Service updated.',
        'deleted' => 'Service removed.',
    ],

    'product' => [
        'created' => 'Product created successfully.',
        'updated' => 'Product updated.',
        'deleted' => 'Product removed.',
        'stock_updated' => 'Stock updated.',
    ],

    'patient' => [
        'created' => 'Patient record created successfully.',
        'updated' => 'Patient details updated.',
        'deleted' => 'Patient record deleted.',
        'restored' => 'Patient record restored.',
        'notes_updated' => 'Patient note updated.',
        'delete_blocked_prefix' => 'This patient cannot be deleted:',
        'delete_blocked_cases' => ':count open case(s)',
        'delete_blocked_appointments' => ':count upcoming appointment(s)',
        'delete_blocked_follow_ups' => ':count pending follow-up(s)',
        'delete_blocked_balance' => 'an unsettled balance of :amount',
        'delete_blocked_suffix' => 'still open. Resolve these first.',
    ],

    'schedule_exception' => [
        'added' => 'Availability exception added.',
        'clinic_wide_added' => 'Clinic-wide closure created for all doctors.',
        'removed' => 'Availability exception removed.',
    ],

    'appointment_type' => [
        'created' => 'Appointment type created successfully.',
        'updated' => 'Appointment type updated.',
        'deleted' => 'Appointment type removed.',
    ],

    'payment' => [
        'recorded' => 'Payment recorded.',
    ],

    'refund' => [
        'recorded' => 'Refund recorded.',
    ],

    'payment_plan' => [
        'created' => 'Payment plan created.',
        'collected' => 'Installment collected.',
        'reminder_sent' => 'Reminder SMS sent.',
        'reminder_skipped' => 'Reminder SMS was not sent (the clinic may have this notification disabled, or a quota/phone issue).',
        'cancelled' => 'Payment plan cancelled.',
        'deleted' => 'Payment plan deleted.',
    ],

    'sms_settings' => [
        'updated' => 'SMS preferences updated.',
    ],

    'doctor' => [
        'profile_created' => 'Doctor profile created.',
        'profile_updated' => 'Doctor profile updated.',
        'doctor_added' => 'Doctor added successfully.',
        'doctor_removed' => 'Doctor removed.',
        'avatar_updated' => 'Profile photo updated.',
        'avatar_removed' => 'Profile photo removed.',
        'already_has_profile' => 'A doctor profile already exists for this user.',
        'no_profile_yet' => 'You do not have a doctor profile yet.',
        'password_reminder_title' => 'Change your password',
        'password_reminder_body' => 'Your account was created by an administrator. We recommend changing your temporary password for security.',
        'password_reminder_action' => 'Change password',
        'password_reminder_dismiss' => 'Later',
        'offboarded' => ':name has been offboarded, :count appointment(s) cancelled.',
        'already_offboarded' => 'This doctor has already been offboarded.',
        'has_upcoming_appointments' => 'This doctor has :count upcoming appointment(s); cancel them or reassign to another doctor first.',
        'cannot_offboard_self' => 'You cannot offboard yourself.',
        'cannot_remove_self' => 'You cannot delete your own record.',
    ],

    'tag' => [
        'created' => 'Tag created.',
        'updated' => 'Tag updated.',
        'deleted' => 'Tag deleted.',
        'synced' => 'Patient tags updated.',
    ],

    'segment' => [
        'saved' => 'Segment saved.',
        'deleted' => 'Segment deleted.',
    ],

    'follow_up_type' => [
        'created' => 'Follow-up type created.',
        'updated' => 'Follow-up type updated.',
        'deleted' => 'Follow-up type removed.',
    ],

    'role' => [
        'permissions_updated' => 'Role permissions updated.',
        'created' => 'Role created.',
        'renamed' => 'Role renamed.',
        'deleted' => 'Role deleted.',
        'reverted' => 'Reverted to default role permissions.',
        'no_customizations' => 'There is nothing to revert.',
        'self_lockout' => 'You cannot remove this permission from your own role; you would lose access.',
        'cannot_delete_own_role' => 'You cannot delete your own role.',
        'has_assigned_users' => 'This role cannot be deleted while users are assigned to it.',
        'revert_locks_out' => 'This revert cannot proceed because it would remove a permission from your own role.',
    ],

];
