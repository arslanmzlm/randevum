<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconUsers } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SegmentPicker from '@/components/patients/SegmentPicker.vue';
import TagChip from '@/components/TagChip.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, index, show } from '@/routes/patients';
import type {
    Patient,
    PatientIndexProps,
    SegmentCriteria,
} from '@/types/patient';
import { parseDateString, toDateString } from '@/utils/datetime';

defineOptions({ layout: AppLayout });

const props = defineProps<PatientIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();
const canManage = computed(() => can('patients.create'));
const canViewBalance = computed(() => can('transactions.viewAny'));
const canManageSegments = computed(() => can('segments.manage'));

// Remaining balance for a row (positive = owes); null when square or not provided.
function balanceFor(patient: Patient): string | null {
    return props.balances?.[patient.id] ?? null;
}

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{
        gender: string | null;
        is_legacy: boolean | null;
        tags: string[];
        last_visit_after: Date | null;
        last_visit_before: Date | null;
    }>({
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
            tags: {
                type: 'array',
                value: props.query.filter.tags ?? [],
            },
            last_visit_after: {
                type: 'date',
                value: props.query.filter.last_visit_after
                    ? parseDateString(props.query.filter.last_visit_after)
                    : null,
            },
            last_visit_before: {
                type: 'date',
                value: props.query.filter.last_visit_before
                    ? parseDateString(props.query.filter.last_visit_before)
                    : null,
            },
        },
    });

// MultiSelect binds numeric tag ids; the filter state keeps string ids (URL/CSV canonical form).
const selectedTagIds = computed<number[]>({
    get: () => state.tags.map(Number),
    set: (ids) => {
        state.tags = ids.map(String);
    },
});

const hasActiveFilters = computed(
    () =>
        !!state.search ||
        !!state.gender ||
        state.is_legacy !== null ||
        state.tags.length > 0 ||
        !!state.last_visit_after ||
        !!state.last_visit_before,
);

// The queryable filter subset a segment persists / restores (search deliberately excluded).
const currentCriteria = computed<SegmentCriteria>(() => {
    const criteria: SegmentCriteria = {};

    if (state.gender) {
        criteria.gender = state.gender as SegmentCriteria['gender'];
    }

    if (state.is_legacy !== null) {
        criteria.is_legacy = state.is_legacy;
    }

    if (state.tags.length > 0) {
        criteria.tags = state.tags.map(Number);
    }

    if (state.last_visit_after) {
        criteria.last_visit_after = toDateString(state.last_visit_after);
    }

    if (state.last_visit_before) {
        criteria.last_visit_before = toDateString(state.last_visit_before);
    }

    return criteria;
});

const hasCriteria = computed(
    () => Object.keys(currentCriteria.value).length > 0,
);

// Apply a saved segment: set every filter dimension from its criteria in one batch
// (the filter watcher reloads once), leaving free-text search untouched.
function applySegment(criteria: SegmentCriteria): void {
    state.gender = criteria.gender ?? null;
    state.is_legacy = criteria.is_legacy ?? null;
    state.tags = (criteria.tags ?? []).map(String);
    state.last_visit_after = criteria.last_visit_after
        ? parseDateString(criteria.last_visit_after)
        : null;
    state.last_visit_before = criteria.last_visit_before
        ? parseDateString(criteria.last_visit_before)
        : null;
}

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

        <EmptyState
            v-if="showEmptyState"
            :icon="IconUsers"
            :message="t('patient.empty')"
        >
            <template #action>
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
            </template>
        </EmptyState>

        <DataTableWrapper
            v-else
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
                <MultiSelect
                    v-if="tags.length"
                    v-model="selectedTagIds"
                    :options="tags"
                    option-label="name"
                    option-value="id"
                    :placeholder="t('patient.filter_tags')"
                    :max-selected-labels="0"
                    :selected-items-label="`{0} ${t('tag.selected_suffix')}`"
                    show-clear
                    filter
                    class="w-full sm:w-52"
                >
                    <template #option="{ option }">
                        <TagChip :label="option.name" :color="option.color" />
                    </template>
                </MultiSelect>
                <DatePicker
                    v-model="state.last_visit_after"
                    :placeholder="t('patient.filter_last_visit_after')"
                    date-format="dd.mm.yy"
                    show-icon
                    show-button-bar
                    icon-display="input"
                    class="w-full sm:w-52"
                />
                <DatePicker
                    v-model="state.last_visit_before"
                    v-tooltip.top="t('patient.filter_last_visit_before_hint')"
                    :placeholder="t('patient.filter_last_visit_before')"
                    date-format="dd.mm.yy"
                    show-icon
                    show-button-bar
                    icon-display="input"
                    class="w-full sm:w-52"
                />
                <SegmentPicker
                    :segments="segments"
                    :current-criteria="currentCriteria"
                    :has-criteria="hasCriteria"
                    :can-manage="canManageSegments"
                    class="sm:ml-auto"
                    @apply="applySegment"
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

            <Column :header="t('patient.columns.tags')" class="w-56">
                <template #body="{ data }">
                    <div
                        v-if="data.tags && data.tags.length"
                        class="flex flex-wrap gap-1"
                    >
                        <TagChip
                            v-for="tag in data.tags"
                            :key="tag.id"
                            :label="tag.name"
                            :color="tag.color"
                        />
                    </div>
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
                    <span v-if="data.last_visit_at" class="text-surface-700">
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

            <!-- Edit/delete live on the detail page: the list row only routes there, so the
                 destructive action is never one stray click away from a scanning eye. -->
            <Column :header="t('patient.columns.actions')" class="w-32">
                <template #body="{ data }">
                    <div class="flex items-center justify-end">
                        <ButtonLink
                            :href="show(data.id).url"
                            :label="t('patient.view')"
                            severity="secondary"
                            outlined
                            size="small"
                        />
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('patient.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
