<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import EntityLinkRow from '@/components/EntityLinkRow.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { PatientAppointmentItem } from '@/types/patient';

defineProps<{ appointment: PatientAppointmentItem }>();

const { t } = useI18n();
const { formatRange } = useDateTime();
</script>

<template>
    <EntityLinkRow
        :title="formatRange(appointment.starts_at, appointment.ends_at)"
    >
        <template #status>
            <AppointmentStatusTag :status="appointment.status" />
            <Tag
                v-if="appointment.is_walk_in"
                severity="secondary"
                :value="t('appointment.walk_in')"
            />
        </template>
        <template #meta>
            <span class="flex items-center gap-1.5">
                {{ appointment.doctor_name }}
                <template v-if="appointment.service_name">
                    · {{ appointment.service_name }}
                </template>
                <template v-if="appointment.appointment_type">
                    ·
                    <span
                        class="inline-block size-2 shrink-0 rounded-full"
                        :style="{
                            backgroundColor: appointment.appointment_type.color,
                        }"
                    />
                    {{ appointment.appointment_type.name }}
                </template>
            </span>
        </template>
    </EntityLinkRow>
</template>
