/**
 * Single source for the report page's tab strip and each breakdown's column set, so the tab
 * order, icons and column meaning are declared once instead of being re-inlined per panel.
 * Labels stay out of here: the page resolves them from the `report` message tree at render time.
 */
import {
    IconBuildingStore,
    IconCalendarStats,
    IconClipboardList,
    IconPackage,
    IconReceipt,
    IconReportMoney,
    IconStethoscope,
} from '@tabler/icons-vue';
import type { Component } from 'vue';
import type { ReportTab } from '@/types/enums';
import type { BreakdownColumn, BreakdownTab } from '@/types/report';

export const REPORT_TABS: { value: ReportTab; icon: Component }[] = [
    { value: 'finance', icon: IconReportMoney },
    { value: 'doctor', icon: IconStethoscope },
    { value: 'service', icon: IconClipboardList },
    { value: 'product', icon: IconPackage },
    { value: 'appointment_type', icon: IconCalendarStats },
    { value: 'expense_owner', icon: IconReceipt },
    { value: 'branch', icon: IconBuildingStore },
];

// Typed by BreakdownTab → a new server-side tab without a column set is a compile error.
export const REPORT_BREAKDOWN_COLUMNS: Record<BreakdownTab, BreakdownColumn[]> =
    {
        doctor: [
            {
                field: 'label',
                headerKey: 'label_header.doctor',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.doctor',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.doctor',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'columns.average',
                type: 'money',
                sortable: true,
            },
            {
                field: 'appointment_count',
                headerKey: 'columns.appointment_count',
                type: 'number',
                sortable: true,
            },
            // The raw counts are not in the server's sort allow-list — only their rates are.
            {
                field: 'cancelled_count',
                headerKey: 'columns.cancelled_count',
                type: 'number',
                sortable: false,
            },
            {
                field: 'cancelled_rate',
                headerKey: 'columns.cancelled_rate',
                type: 'percent',
                sortable: true,
            },
            {
                field: 'no_show_count',
                headerKey: 'columns.no_show_count',
                type: 'number',
                sortable: false,
            },
            {
                field: 'no_show_rate',
                headerKey: 'columns.no_show_rate',
                type: 'percent',
                sortable: true,
            },
        ],
        service: [
            {
                field: 'label',
                headerKey: 'label_header.service',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.service',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.service',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'average_header.unit',
                type: 'money',
                sortable: true,
            },
        ],
        product: [
            {
                field: 'label',
                headerKey: 'label_header.product',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.product',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.product',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'average_header.unit',
                type: 'money',
                sortable: true,
            },
        ],
        appointment_type: [
            {
                field: 'label',
                headerKey: 'label_header.appointment_type',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.appointment_type',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.appointment_type',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'columns.average',
                type: 'money',
                sortable: true,
            },
        ],
        branch: [
            {
                field: 'label',
                headerKey: 'label_header.branch',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.branch',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.branch',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'columns.average',
                type: 'money',
                sortable: true,
            },
            {
                field: 'expense',
                headerKey: 'columns.expense',
                type: 'money',
                sortable: true,
            },
            {
                field: 'net',
                headerKey: 'columns.net',
                type: 'money',
                sortable: true,
            },
        ],
        expense_owner: [
            {
                field: 'label',
                headerKey: 'label_header.expense_owner',
                type: 'text',
                sortable: true,
            },
            {
                field: 'amount',
                headerKey: 'amount_header.expense_owner',
                type: 'money',
                sortable: true,
            },
            {
                field: 'count',
                headerKey: 'count_header.expense_owner',
                type: 'number',
                sortable: true,
            },
            {
                field: 'average',
                headerKey: 'columns.average',
                type: 'money',
                sortable: true,
            },
        ],
    };
