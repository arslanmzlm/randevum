<?php

use App\Enums\ClinicRole;
use App\Modules\Identity\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;

it('has a tr/en label for every seeded permission, every group, and every clinic role, with nothing left in "other"', function (string $locale): void {
    app()->setLocale($locale);

    $seededPermissionNames = array_keys(
        (new ReflectionClass(PermissionSeeder::class))->getConstant('PERMISSIONS'),
    );

    $permissionLabels = trans('permission.names');
    $groupLabels = trans('permission.groups');
    $roleLabels = trans('role.names');

    foreach ($seededPermissionNames as $name) {
        expect($permissionLabels[$name] ?? '')->not->toBe('');
        expect(PermissionCatalog::groupFor($name))->not->toBe('other');
    }

    foreach (PermissionCatalog::orderedGroups() as $groupKey) {
        expect($groupLabels[$groupKey] ?? '')->not->toBe('');
    }

    foreach (ClinicRole::cases() as $case) {
        expect($roleLabels[$case->value] ?? '')->not->toBe('');
    }
})->with(['tr', 'en']);
