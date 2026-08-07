<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconBuildingCommunity, IconPlus } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useDateTime } from '@/composables/useDateTime';
import AppLayout from '@/layouts/AppLayout.vue';
import { create } from '@/routes/settings/branches';

defineOptions({ layout: AppLayout });

// Read-only list: a branch is edited by switching into it and using the clinic profile, so this
// page owns no row actions.
type BranchRow = {
    id: number;
    name: string;
    vertical_name: string | null;
    is_active: boolean;
    is_current: boolean;
    created_at: string;
};

defineProps<{ branches: BranchRow[] }>();

const { t } = useI18n();
const { formatDate } = useDateTime();
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('branch.title')" />

        <PageHeader
            :title="t('branch.title')"
            :description="t('branch.subtitle')"
            :breadcrumbs="[{ label: t('nav.branches') }]"
        >
            <template #actions>
                <ButtonLink
                    :href="create().url"
                    :label="t('branch.create_title')"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <EmptyState
            v-if="branches.length === 0"
            :icon="IconBuildingCommunity"
            :message="t('branch.empty')"
        />

        <SectionCard v-else padding="p-2 sm:p-3">
            <DataTable :value="branches" data-key="id" class="text-sm">
                <Column field="name" :header="t('branch.name')">
                    <template #body="{ data }">
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-medium text-surface-900">
                                {{ data.name }}
                            </span>
                            <Tag
                                v-if="data.is_current"
                                severity="info"
                                :value="t('branch.current')"
                            />
                        </span>
                    </template>
                </Column>

                <Column
                    field="vertical_name"
                    :header="t('branch.vertical')"
                    class="w-56"
                >
                    <template #body="{ data }">
                        <span class="text-surface-600">
                            {{ data.vertical_name || '—' }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="is_active"
                    :header="t('branch.status')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <Tag
                            :severity="data.is_active ? 'success' : 'secondary'"
                            :value="
                                data.is_active
                                    ? t('branch.active')
                                    : t('branch.passive')
                            "
                        />
                    </template>
                </Column>

                <Column
                    field="created_at"
                    :header="t('branch.created_at')"
                    class="w-40"
                >
                    <template #body="{ data }">
                        <span class="text-surface-500">
                            {{ formatDate(data.created_at) }}
                        </span>
                    </template>
                </Column>
            </DataTable>
        </SectionCard>
    </div>
</template>
