<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FilterMatchMode } from '@primevue/core/api';
import {
    IconClipboardList,
    IconPlus,
    IconSearch,
    IconTrash,
} from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit } from '@/routes/services';
import type { Service, ServiceIndexProps } from '@/types/service';

defineOptions({ layout: AppLayout });

const props = defineProps<ServiceIndexProps>();

const { t, locale } = useI18n();
const confirm = useConfirm();

// Client-side filters (per-clinic catalog stays small — no server-side pagination needed):
// free-text global search + an active/passive status filter (null = all).
const filters = ref({
    global: {
        value: null as string | null,
        matchMode: FilterMatchMode.CONTAINS,
    },
    is_active: {
        value: null as boolean | null,
        matchMode: FilterMatchMode.EQUALS,
    },
});

const statusOptions = computed(() => [
    { label: t('service.active'), value: true },
    { label: t('service.passive'), value: false },
]);

const priceFormatter = new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: props.currency,
});

function formatPrice(value: string): string {
    return priceFormatter.format(Number(value));
}

function removeService(service: Service): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('service.remove_confirm', { name: service.name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(service.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('service.title')" />

        <PageHeader
            :title="t('service.title')"
            :description="t('service.subtitle')"
            :breadcrumbs="[{ label: t('nav.services') }]"
        >
            <template #actions>
                <ButtonLink
                    v-if="canManage"
                    :href="create().url"
                    :label="t('service.add')"
                >
                    <template #icon>
                        <IconPlus class="size-4" />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <section
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <div
                v-if="services.length"
                class="flex flex-col gap-2 p-2 sm:flex-row sm:items-center"
            >
                <IconField>
                    <InputIcon>
                        <IconSearch class="size-4 text-surface-400" />
                    </InputIcon>
                    <InputText
                        v-model="filters.global.value"
                        :placeholder="t('service.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <Select
                    v-model="filters.is_active.value"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('service.filter_status')"
                    show-clear
                    class="w-full sm:w-44"
                />
            </div>

            <DataTable
                v-if="services.length"
                v-model:filters="filters"
                :value="services"
                data-key="id"
                removable-sort
                paginator
                :rows="10"
                :rows-per-page-options="[10, 25, 50]"
                :global-filter-fields="['name', 'description']"
                class="text-sm"
            >
                <Column
                    field="name"
                    :header="t('service.columns.name')"
                    sortable
                >
                    <template #body="{ data }">
                        <div class="flex min-w-0 flex-col">
                            <span class="truncate font-medium text-surface-900">
                                {{ data.name }}
                            </span>
                            <span
                                v-if="data.description"
                                class="truncate text-xs text-surface-500"
                            >
                                {{ data.description }}
                            </span>
                        </div>
                    </template>
                </Column>

                <Column
                    field="price"
                    :header="t('service.columns.price')"
                    sortable
                    class="w-40"
                >
                    <template #body="{ data }">
                        <span class="font-medium text-surface-700">
                            {{ formatPrice(data.price) }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="is_active"
                    :header="t('service.columns.status')"
                    sortable
                    class="w-32"
                >
                    <template #body="{ data }">
                        <Tag
                            :severity="data.is_active ? 'success' : 'secondary'"
                            :value="
                                data.is_active
                                    ? t('service.active')
                                    : t('service.passive')
                            "
                        />
                    </template>
                </Column>

                <Column
                    v-if="canManage"
                    :header="t('service.columns.actions')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <div class="flex items-center justify-end gap-1">
                            <ButtonLink
                                :href="edit(data.id).url"
                                :label="t('service.edit')"
                                severity="secondary"
                                outlined
                                size="small"
                            />
                            <Button
                                type="button"
                                severity="danger"
                                text
                                size="small"
                                :aria-label="t('service.remove')"
                                @click="removeService(data)"
                            >
                                <IconTrash class="size-4" />
                            </Button>
                        </div>
                    </template>
                </Column>

                <template #empty>
                    <div
                        class="px-6 py-10 text-center text-sm text-surface-500"
                    >
                        {{ t('service.empty_filtered') }}
                    </div>
                </template>
            </DataTable>

            <div
                v-else
                class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
            >
                <IconClipboardList class="size-10 text-surface-300" />
                <p class="text-sm text-surface-500">{{ t('service.empty') }}</p>
            </div>
        </section>
    </div>
</template>
