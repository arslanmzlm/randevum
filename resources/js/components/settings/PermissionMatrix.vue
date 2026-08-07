<script setup lang="ts">
import { IconCheck, IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import type {
    PermissionDraft,
    PermissionGroup,
    PermissionRow,
    RoleColumn,
} from '@/types/role';

// Permission matrix: many rows (~70 permissions), few columns (5+ roles), so a plain table beats
// DataTableWrapper — no sorting, paging or filtering applies to a full matrix. The same component
// serves both modes: read-only for a `roles.viewAny` viewer, editable for `roles.manage`.
const props = withDefaults(
    defineProps<{
        roles: RoleColumn[];
        groups: PermissionGroup[];
        editable?: boolean;
        /** Working copy the checkboxes render from; ignored in read-only mode. */
        draft?: PermissionDraft;
    }>(),
    { editable: false, draft: () => ({}) },
);

const emit = defineEmits<{
    toggle: [roleId: number, permission: string, granted: boolean];
    toggleGroup: [roleId: number, groupKey: string, granted: boolean];
    delete: [role: RoleColumn];
}>();

const { t } = useI18n();

// Set lookup per role: a full matrix asks `granted?` for every cell on every draft change.
const granted = computed(() => {
    const sets = new Map<number, Set<string>>();

    for (const role of props.roles) {
        sets.set(role.id, new Set(props.draft[role.id] ?? []));
    }

    return sets;
});

function isGranted(role: RoleColumn, permission: PermissionRow): boolean {
    return props.editable
        ? (granted.value.get(role.id)?.has(permission.name) ?? false)
        : permission.role_ids.includes(role.id);
}

function isLocked(role: RoleColumn, permission: PermissionRow): boolean {
    return permission.locked_role_ids.includes(role.id);
}

function groupState(
    role: RoleColumn,
    group: PermissionGroup,
): { all: boolean; some: boolean } {
    let held = 0;

    for (const permission of group.permissions) {
        if (isGranted(role, permission)) {
            held += 1;
        }
    }

    return {
        all: held > 0 && held === group.permissions.length,
        some: held > 0 && held < group.permissions.length,
    };
}

// Every permission in the group locked for this column (the acting user's own role in the
// `roles` group) means the toggle can never change anything: leave it disabled, otherwise
// PrimeVue writes its own local state and the box renders checked with nothing revoked.
function isGroupLocked(role: RoleColumn, group: PermissionGroup): boolean {
    return group.permissions.every((permission) => isLocked(role, permission));
}

function deleteTooltip(role: RoleColumn): string {
    if (role.is_own) {
        return t('role.delete_blocked_own');
    }

    if (role.assigned_users_count > 0) {
        return t('role.delete_blocked_assigned', {
            count: role.assigned_users_count,
        });
    }

    return t('role.remove');
}
</script>

<template>
    <SectionCard variant="divided">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">
                    {{
                        t('role.table_caption')
                    }}
                </caption>
                <thead>
                    <tr class="border-b border-surface-200">
                        <th
                            scope="col"
                            class="sticky left-0 z-10 bg-surface-0 px-5 py-3 text-left align-bottom font-medium text-surface-500"
                        >
                            {{ t('role.columns.permission') }}
                        </th>
                        <th
                            v-for="role in roles"
                            :key="role.id"
                            scope="col"
                            class="px-4 py-3 text-center align-bottom font-medium text-surface-700"
                        >
                            <div class="flex flex-col items-center gap-1">
                                <span class="whitespace-nowrap">{{
                                    role.label
                                }}</span>
                                <Tag
                                    v-if="role.is_customized"
                                    severity="info"
                                    class="p-tag-sm whitespace-nowrap"
                                    :value="t('role.customized_badge')"
                                />
                                <Tag
                                    v-else-if="role.is_custom"
                                    severity="warn"
                                    class="p-tag-sm whitespace-nowrap"
                                    :value="t('role.custom_badge')"
                                />
                                <span
                                    v-if="editable && role.is_custom"
                                    v-tooltip.top="deleteTooltip(role)"
                                    class="inline-flex"
                                >
                                    <Button
                                        type="button"
                                        severity="danger"
                                        text
                                        size="small"
                                        :disabled="!role.can_delete"
                                        :aria-label="deleteTooltip(role)"
                                        @click="emit('delete', role)"
                                    >
                                        <IconTrash :size="16" />
                                    </Button>
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="group in groups" :key="group.key">
                        <tr class="border-b border-surface-200">
                            <!-- Editable mode puts a per-role all/none toggle in this row, so the
                                 group label can no longer span the whole width. -->
                            <th
                                :scope="editable ? 'row' : 'colgroup'"
                                :colspan="editable ? 1 : roles.length + 1"
                                class="sticky left-0 bg-surface-100 px-5 py-2 text-left text-xs font-semibold tracking-wide text-surface-600 uppercase"
                            >
                                {{ group.label }}
                            </th>
                            <template v-if="editable">
                                <td
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="bg-surface-100 px-4 py-2 text-center"
                                >
                                    <Checkbox
                                        binary
                                        :model-value="
                                            groupState(role, group).all
                                        "
                                        :disabled="isGroupLocked(role, group)"
                                        :indeterminate="
                                            groupState(role, group).some
                                        "
                                        :aria-label="
                                            t('role.group_toggle_label', {
                                                group: group.label,
                                                role: role.label,
                                            })
                                        "
                                        @update:model-value="
                                            (value: boolean) =>
                                                emit(
                                                    'toggleGroup',
                                                    role.id,
                                                    group.key,
                                                    value,
                                                )
                                        "
                                    />
                                </td>
                            </template>
                        </tr>
                        <tr
                            v-for="permission in group.permissions"
                            :key="permission.name"
                            class="group border-b border-surface-200 last:border-b-0 hover:bg-surface-50"
                        >
                            <th
                                scope="row"
                                :title="permission.name"
                                class="sticky left-0 z-10 max-w-xs bg-surface-0 px-5 py-2 text-left font-normal text-surface-700 group-hover:bg-surface-50"
                            >
                                {{ permission.label }}
                            </th>
                            <td
                                v-for="role in roles"
                                :key="role.id"
                                class="px-4 py-2 text-center"
                            >
                                <!-- The tooltip sits on a wrapper: a disabled control emits no
                                     pointer events of its own. -->
                                <span
                                    v-if="editable"
                                    v-tooltip.top="
                                        isLocked(role, permission)
                                            ? t('role.locked_hint')
                                            : undefined
                                    "
                                    class="inline-flex"
                                >
                                    <Checkbox
                                        binary
                                        :model-value="
                                            isGranted(role, permission)
                                        "
                                        :disabled="isLocked(role, permission)"
                                        :aria-label="`${permission.label} — ${role.label}`"
                                        @update:model-value="
                                            (value: boolean) =>
                                                emit(
                                                    'toggle',
                                                    role.id,
                                                    permission.name,
                                                    value,
                                                )
                                        "
                                    />
                                </span>
                                <template v-else>
                                    <IconCheck
                                        v-if="isGranted(role, permission)"
                                        class="mx-auto size-4 text-primary"
                                        :aria-label="t('role.granted')"
                                    />
                                    <span v-else class="sr-only">{{
                                        t('role.not_granted')
                                    }}</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
