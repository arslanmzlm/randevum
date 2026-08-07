<?php

namespace App\Modules\Identity\Support;

/**
 * Structural (non-translatable) half of the permission matrix: which resource prefixes fall
 * into which group, and the row order. Human-readable labels live in lang/<locale>/permission.php.
 */
class PermissionCatalog
{
    /**
     * Ordered groupKey => list<resourcePrefix>. Order here is the matrix's group order.
     *
     * @var array<string, list<string>>
     */
    private const GROUPS = [
        'patients' => ['patients', 'anamnesis', 'tags', 'segments'],
        'appointments' => ['appointments', 'appointmentTypes', 'scheduleExceptions'],
        'treatments' => ['treatments', 'cases', 'followUps', 'followUpTypes'],
        'finance' => ['transactions', 'paymentPlans', 'expenses'],
        'catalog' => ['services', 'products'],
        'messaging' => ['smsSettings', 'smsLogs'],
        'clinic' => ['clinic', 'clinics', 'doctors'],
        'reports' => ['reports'],
        'roles' => ['roles'],
    ];

    /**
     * The group a permission belongs to, by its resource prefix (segment before the first dot).
     * Unmapped resource → 'other'.
     */
    public static function groupFor(string $permission): string
    {
        $resource = strstr($permission, '.', true) ?: $permission;

        foreach (self::GROUPS as $groupKey => $resources) {
            if (in_array($resource, $resources, true)) {
                return $groupKey;
            }
        }

        return 'other';
    }

    /**
     * Group keys in matrix order, 'other' last.
     *
     * @return list<string>
     */
    public static function orderedGroups(): array
    {
        return [...array_keys(self::GROUPS), 'other'];
    }

    /**
     * [groupIndex, resourceIndexWithinGroup, permissionName] — sorts the matrix rows
     * deterministically: group order, then resource order within the group, then alphabetical.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public static function sortKey(string $permission): array
    {
        $resource = strstr($permission, '.', true) ?: $permission;
        $groupKey = self::groupFor($permission);
        $groupIndex = array_search($groupKey, self::orderedGroups(), true);
        $resourceIndex = $groupKey === 'other'
            ? 0
            : array_search($resource, self::GROUPS[$groupKey], true);

        return [(int) $groupIndex, (int) $resourceIndex, $permission];
    }
}
