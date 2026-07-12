<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { BulkCancelPreviewRow } from '@/types/appointment';

defineProps<{
    appointments: BulkCancelPreviewRow[];
}>();

const { t } = useI18n();
const { formatDate, formatTime } = useDateTime();
</script>

<template>
    <DataTable
        :value="appointments"
        data-key="id"
        scrollable
        scroll-height="28rem"
        class="text-sm"
    >
        <Column
            :header="t('appointment_bulk_cancel.columns.datetime')"
            class="w-44"
        >
            <template #body="{ data }">
                <div class="flex flex-col">
                    <span class="font-medium text-surface-800">
                        {{ formatDate(data.starts_at) }}
                    </span>
                    <span class="text-xs text-surface-500">
                        {{ formatTime(data.starts_at) }}
                    </span>
                </div>
            </template>
        </Column>

        <Column
            field="patient_name"
            :header="t('appointment_bulk_cancel.columns.patient')"
        >
            <template #body="{ data }">
                <span class="text-surface-800">
                    {{ data.patient_name }}
                </span>
            </template>
        </Column>

        <Column
            field="doctor_name"
            :header="t('appointment_bulk_cancel.columns.doctor')"
        >
            <template #body="{ data }">
                <span class="text-surface-700">
                    {{ data.doctor_name }}
                </span>
            </template>
        </Column>

        <Column :header="t('appointment_bulk_cancel.columns.service')">
            <template #body="{ data }">
                <span v-if="data.service_name" class="text-surface-700">
                    {{ data.service_name }}
                </span>
                <span v-else class="text-surface-400">—</span>
            </template>
        </Column>

        <Column
            field="status"
            :header="t('appointment_bulk_cancel.columns.status')"
            class="w-36"
        >
            <template #body="{ data }">
                <AppointmentStatusTag :status="data.status" />
            </template>
        </Column>
    </DataTable>
</template>
