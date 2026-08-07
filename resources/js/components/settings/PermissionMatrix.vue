<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import type { PermissionGroup, RoleColumn } from '@/types/role';

// Read-only permission matrix: many rows (~70 permissions), few columns (5 roles), so a plain
// table beats DataTableWrapper — no sorting, paging or filtering applies to a full matrix.
defineProps<{
    roles: RoleColumn[];
    groups: PermissionGroup[];
}>();

const { t } = useI18n();
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
                                    :value="t('role.customized_badge')"
                                />
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="group in groups" :key="group.key">
                        <tr class="border-b border-surface-200">
                            <th
                                scope="colgroup"
                                :colspan="roles.length + 1"
                                class="sticky left-0 bg-surface-100 px-5 py-2 text-left text-xs font-semibold tracking-wide text-surface-600 uppercase"
                            >
                                {{ group.label }}
                            </th>
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
                                <IconCheck
                                    v-if="permission.role_ids.includes(role.id)"
                                    class="mx-auto size-4 text-primary"
                                    :aria-label="t('role.granted')"
                                />
                                <span v-else class="sr-only">{{
                                    t('role.not_granted')
                                }}</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </SectionCard>
</template>
