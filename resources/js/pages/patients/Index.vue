<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconTrash, IconUsers } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit, index, show } from '@/routes/patients';
import type { Patient, PatientIndexProps } from '@/types/patient';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();
const canManage = computed(() => can('patients.create'));
const canDelete = computed(() => can('patients.delete'));
const canViewBalance = computed(() => can('transactions.viewAny'));

// Remaining balance for a row (positive = owes); null when square or not provided.
function balanceFor(patient: Patient): string | null {
    return props.balances?.[patient.id] ?? null;
}

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{ gender: string | null; is_legacy: boolean | null }>({
        url: index().url,
        only: ['patients', 'query'],
        currentPage: props.patients.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
        filters: {
            gender: {
                type: 'string',
                value: props.query.filter.gender || null,
            },
            is_legacy: {
                type: 'boolean',
                value: props.query.filter.is_legacy,
            },
        },
    });

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
                        <IconPlus />
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
                    <IconPlus />
                </template>
            </ButtonLink>
        </div>

        <section
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <DataTableWrapper
                :value="patients.data"
                :total-records="patients.meta.total"
                :rows="state.per_page"
                :first="first"
                :loading="loading"
                :sort-field="sortField"
                :sort-order="sortOrder"
                @page="onPage"
                @sort="onSort"
            >
                <template #toolbar>
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
                </template>

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
                    field="last_visit_at"
                    :header="t('patient.columns.last_visit')"
                    sortable
                    class="w-36"
                >
                    <template #body="{ data }">
                        <span
                            v-if="data.last_visit_at"
                            class="text-surface-700"
                        >
                            {{ formatDate(data.last_visit_at) }}
                        </span>
                        <span v-else class="text-surface-400">—</span>
                    </template>
                </Column>

                <Column
                    v-if="canViewBalance"
                    :header="t('patient.columns.balance')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <span
                            v-if="balanceFor(data) !== null"
                            class="font-medium"
                            :class="
                                Number(balanceFor(data)) > 0
                                    ? 'text-red-600'
                                    : 'text-emerald-600'
                            "
                        >
                            {{ formatMoney(balanceFor(data)) }}
                        </span>
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
                                <IconTrash />
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
            </DataTableWrapper>
        </section>
    </div>
</template>
