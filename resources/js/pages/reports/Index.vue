<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconFileSpreadsheet, IconRefresh } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DateRangeFilter from '@/components/DateRangeFilter.vue';
import PageHeader from '@/components/PageHeader.vue';
import PillTabs from '@/components/PillTabs.vue';
import ReportBreakdownTable from '@/components/reports/ReportBreakdownTable.vue';
import ReportFinanceTab from '@/components/reports/ReportFinanceTab.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateWindowList } from '@/composables/useDateWindowList';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    clearCache,
    exportMethod as exportReport,
    index,
} from '@/routes/reports';
import type { ReportTab } from '@/types/enums';
import type {
    BreakdownColumn,
    BreakdownTab,
    ReportIndexProps,
} from '@/types/report';
import {
    REPORT_BREAKDOWN_COLUMNS,
    REPORT_TABS,
} from '@/utils/reportBreakdowns';

defineOptions({ layout: AppLayout });

const props = defineProps<ReportIndexProps>();

const { t } = useI18n();
const { can } = useCan();

// Finance/branch/expense-owner tabs report expense figures, so the server also requires
// expenses.viewAny (ReportController::index) on top of reports.revenue — a role customization
// can revoke the former while leaving the latter, so hide the tabs rather than let the click 403.
const EXPENSE_GATED_TABS: ReportTab[] = ['finance', 'branch', 'expense_owner'];
const canViewExpenses = computed(() => can('expenses.viewAny'));

// The tab lives on the server (`?tab=`) because the rows come from it; the local ref only keeps
// the pill highlighted while the visit is in flight, and follows the prop once it lands.
const activeTab = ref<ReportTab>(props.tab);

// Same flat date-window list state as the money lists, plus the tab (re-sent on every reload, so
// a window/sort change never falls back to the finance tab) and a list-only partial reload.
const list = useDateWindowList({
    url: index().url,
    filters: props.filters,
    query: props.query,
    currentPage: props.breakdown?.meta.current_page ?? 1,
    extraParams: () => ({ tab: activeTab.value }),
    partialOnly: ['breakdown', 'query'],
});

watch(
    () => props.tab,
    (tab) => {
        activeTab.value = tab;
    },
);

// Switching tabs keeps the window and the sort (coming back to a breakdown lands where it was
// left); changing the window keeps the tab. Rows come from the new tab, so this is a full reload.
watch(activeTab, (tab) => {
    if (tab !== props.tab) {
        list.reload({ resetPage: true });
    }
});

// The umbrella tab only exists for a tenant with a second branch the viewer belongs to; the server
// silently falls back to `finance` if it is requested anyway.
const tabs = computed(() =>
    REPORT_TABS.filter(
        (tab) => tab.value !== 'branch' || props.multiBranch,
    )
        .filter(
            (tab) =>
                canViewExpenses.value ||
                !EXPENSE_GATED_TABS.includes(tab.value),
        )
        .map((tab) => ({
        value: tab.value,
        label: t(`report.tabs.${tab.value}`),
        icon: tab.icon,
    })),
);

const columns = computed<BreakdownColumn[]>(() =>
    props.tab === 'finance'
        ? []
        : REPORT_BREAKDOWN_COLUMNS[props.tab as BreakdownTab],
);

function exportUrlFor(tab: string): string {
    return exportReport({
        query: {
            tab,
            start: props.filters.entire ? null : props.filters.start,
            end: props.filters.entire ? null : props.filters.end,
            entire: props.filters.entire ? 1 : null,
            sort: props.query.sort,
        },
    }).url;
}

const exportUrl = computed(() => exportUrlFor(props.tab));

// tab=all puts every tab in one workbook, one worksheet each.
const exportAllUrl = computed(() => exportUrlFor('all'));

function clearReportCache(): void {
    router.post(
        clearCache().url,
        {},
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('report.title')" />

        <PageHeader
            :title="t('report.title')"
            :description="t('report.subtitle')"
            :breadcrumbs="[{ label: t('nav.reports') }]"
        >
            <template #actions>
                <Button
                    v-if="tab === 'finance'"
                    type="button"
                    severity="secondary"
                    outlined
                    :label="t('revenue.refresh')"
                    @click="clearReportCache"
                >
                    <template #icon>
                        <IconRefresh />
                    </template>
                </Button>

                <!-- Binary xlsx stream: plain anchor to a new tab, never an Inertia visit. -->
                <Button
                    as="a"
                    :href="exportUrl"
                    target="_blank"
                    rel="noopener"
                    severity="secondary"
                    outlined
                    :label="t('report.export')"
                >
                    <template #icon>
                        <IconFileSpreadsheet />
                    </template>
                </Button>

                <Button
                    as="a"
                    :href="exportAllUrl"
                    target="_blank"
                    rel="noopener"
                    :label="t('report.export_all')"
                >
                    <template #icon>
                        <IconFileSpreadsheet />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <SectionCard padding="p-5">
            <DateRangeFilter
                :filters="filters"
                :loading="list.loading.value"
                @change="list.setDateWindow"
            />
        </SectionCard>

        <!-- Only the active tab's data is fetched, so a single panel is rendered. -->
        <PillTabs v-model="activeTab" :tabs="tabs">
            <TabPanel :value="activeTab">
                <template v-if="tab === activeTab">
                    <ReportFinanceTab
                        v-if="revenue && expense && net !== null"
                        :revenue="revenue"
                        :expense="expense"
                        :net="net"
                    />

                    <ReportBreakdownTable
                        v-else-if="breakdown"
                        :rows="breakdown.data"
                        :meta="breakdown.meta"
                        :totals="breakdown.totals"
                        :columns="columns"
                        :hint="t(`report.hint.${tab}`)"
                        :first="list.first.value"
                        :sort-field="list.sortField.value"
                        :sort-order="list.sortOrder.value"
                        :loading="list.loading.value"
                        @page="list.onPage"
                        @sort="list.onSort"
                    />
                </template>

                <div v-else class="flex justify-center py-16">
                    <ProgressSpinner class="size-10" />
                </div>
            </TabPanel>
        </PillTabs>
    </div>
</template>
