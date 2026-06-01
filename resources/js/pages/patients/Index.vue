<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconTrash, IconUsers } from '@tabler/icons-vue';
import type {
    DataTablePageEvent,
    DataTableSortEvent,
} from 'primevue/datatable';
import { useConfirm } from 'primevue/useconfirm';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit, index, show } from '@/routes/patients';
import type { Patient, PatientIndexProps } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();

// Server-side lazy list state seeded from the controller-echoed `filters` prop.
// Each change pushes a partial GET that reloads only the `patients`/`filters` props.
const state = reactive({
    search: props.filters.search ?? '',
    sort_field: props.filters.sort_field || null,
    sort_order: props.filters.sort_order || '',
    gender: props.filters.gender || null,
    is_legacy: props.filters.is_legacy,
    per_page: props.filters.per_page || 20,
    page: props.patients.meta.current_page,
});

const loading = ref(false);

const first = computed(() => (state.page - 1) * state.per_page);

const hasActiveFilters = computed(
    () => !!state.search || !!state.gender || state.is_legacy !== null,
);

// Big empty state only when the clinic genuinely has no patients (not a filtered miss).
const showEmptyState = computed(
    () => props.patients.meta.total === 0 && !hasActiveFilters.value,
);

const genderOptions = computed(() => [
    { label: t('patient.gender.male'), value: 'male' },
    { label: t('patient.gender.female'), value: 'female' },
    { label: t('patient.gender.other'), value: 'other' },
]);

const legacyOptions = computed(() => [
    { label: t('patient.legacy_only'), value: true },
    { label: t('patient.not_legacy'), value: false },
]);

function genderLabel(gender: Patient['gender']): string {
    return gender ? t(`patient.gender.${gender}`) : t('patient.not_specified');
}

function reload(): void {
    const params: Record<string, string | number> = {
        page: state.page,
        per_page: state.per_page,
    };

    if (state.search) {
        params.search = state.search;
    }

    if (state.sort_field) {
        params.sort_field = state.sort_field;
        params.sort_order = state.sort_order;
    }

    if (state.gender) {
        params.gender = state.gender;
    }

    if (state.is_legacy !== null) {
        params.is_legacy = state.is_legacy ? 1 : 0;
    }

    router.get(index().url, params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['patients', 'filters'],
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

function onPage(event: DataTablePageEvent): void {
    state.page = event.page + 1;
    state.per_page = event.rows;
    reload();
}

function onSort(event: DataTableSortEvent): void {
    state.sort_field =
        typeof event.sortField === 'string' ? event.sortField : null;
    state.sort_order = event.sortOrder ? String(event.sortOrder) : '';
    state.page = 1;
    reload();
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => state.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.page = 1;
            reload();
        }, 350);
    },
);

watch([() => state.gender, () => state.is_legacy], () => {
    state.page = 1;
    reload();
});

function removePatient(patient: Patient): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('patient.remove_confirm', { name: patient.full_name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(patient.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('patient.title')" />

        <PageHeader
            :title="t('patient.title')"
            :description="t('patient.subtitle')"
            :breadcrumbs="[{ label: t('nav.patients') }]"
        >
            <template #actions>
                <ButtonLink
                    v-if="canManage"
                    :href="create().url"
                    :label="t('patient.add')"
                >
                    <template #icon>
                        <IconPlus class="size-4" />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <div
            v-if="showEmptyState"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-surface-200 bg-surface-0 px-6 py-16 text-center"
        >
            <IconUsers class="size-10 text-surface-300" />
            <p class="text-sm text-surface-500">{{ t('patient.empty') }}</p>
            <ButtonLink
                v-if="canManage"
                :href="create().url"
                :label="t('patient.add')"
                size="small"
            >
                <template #icon>
                    <IconPlus class="size-4" />
                </template>
            </ButtonLink>
        </div>

        <section
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <div
                class="flex flex-col gap-2 p-2 sm:flex-row sm:flex-wrap sm:items-center"
            >
                <IconField>
                    <InputIcon>
                        <IconSearch class="size-4 text-surface-400" />
                    </InputIcon>
                    <InputText
                        v-model="state.search"
                        :placeholder="t('patient.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <Select
                    v-model="state.gender"
                    :options="genderOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('patient.filter_gender')"
                    show-clear
                    class="w-full sm:w-44"
                />
                <Select
                    v-model="state.is_legacy"
                    :options="legacyOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('patient.filter_legacy')"
                    show-clear
                    class="w-full sm:w-52"
                />
            </div>

            <DataTable
                :value="patients.data"
                data-key="id"
                lazy
                paginator
                :rows="state.per_page"
                :first="first"
                :total-records="patients.meta.total"
                :rows-per-page-options="[10, 20, 50]"
                :loading="loading"
                :sort-field="state.sort_field ?? undefined"
                :sort-order="
                    state.sort_order ? Number(state.sort_order) : undefined
                "
                removable-sort
                class="text-sm"
                @page="onPage"
                @sort="onSort"
            >
                <Column
                    field="first_name"
                    :header="t('patient.columns.name')"
                    sortable
                >
                    <template #body="{ data }">
                        <div class="flex min-w-0 items-center gap-2">
                            <Link
                                :href="show(data.id).url"
                                class="truncate font-medium text-primary-600 transition-colors hover:text-primary-700"
                            >
                                {{ data.full_name }}
                            </Link>
                            <Tag
                                v-if="data.is_legacy"
                                severity="warn"
                                :value="t('patient.legacy_badge')"
                            />
                        </div>
                    </template>
                </Column>

                <Column
                    field="phone"
                    :header="t('patient.columns.phone')"
                    class="w-44"
                >
                    <template #body="{ data }">
                        <span class="text-surface-700">
                            {{ data.phone ?? '—' }}
                        </span>
                    </template>
                </Column>

                <Column :header="t('patient.columns.age')" class="w-20">
                    <template #body="{ data }">
                        <span class="text-surface-700">
                            {{ data.age ?? '—' }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="gender"
                    :header="t('patient.columns.gender')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <Tag
                            v-if="data.gender"
                            severity="secondary"
                            :value="genderLabel(data.gender)"
                        />
                        <span v-else class="text-surface-400">—</span>
                    </template>
                </Column>

                <Column
                    v-if="canManage"
                    :header="t('patient.columns.actions')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <div class="flex items-center justify-end gap-1">
                            <ButtonLink
                                :href="edit(data.id).url"
                                :label="t('patient.edit')"
                                severity="secondary"
                                outlined
                                size="small"
                            />
                            <Button
                                v-if="canDelete"
                                type="button"
                                severity="danger"
                                text
                                size="small"
                                :aria-label="t('patient.remove')"
                                @click="removePatient(data)"
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
                        {{ t('patient.empty_filtered') }}
                    </div>
                </template>
            </DataTable>
        </section>
    </div>
</template>
