<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarCheck, IconChevronRight, IconX } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import PatientNameLink from '@/components/patients/PatientNameLink.vue';
import RecordName from '@/components/RecordName.vue';
import SectionCard from '@/components/SectionCard.vue';
import { index as appointmentsIndex } from '@/routes/appointments';
import { show as patientShow } from '@/routes/patients';
import type { CreatedAppointment } from '@/types/appointment';

// Shown in place of the day panel right after a booking: the form is already cleared, so this is
// the only trace of what was just created and the way to reach it.
defineProps<{ appointment: CreatedAppointment }>();

const emit = defineEmits<{ dismiss: [] }>();

const { t } = useI18n();
</script>

<template>
    <SectionCard variant="divided">
        <template #title>
            <IconCalendarCheck class="size-5 shrink-0 text-emerald-600" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('appointment.created_card.title') }}
            </h2>
        </template>

        <template #actions>
            <Button
                type="button"
                severity="secondary"
                text
                :aria-label="t('common.close')"
                @click="emit('dismiss')"
            >
                <template #icon>
                    <IconX />
                </template>
            </Button>
        </template>

        <div class="flex flex-col gap-4 p-5">
            <div class="flex flex-col gap-1">
                <!-- Plain: the "patient profile" link right below is this card's way into the
                     record, so a second link on the name would only duplicate it. -->
                <PatientNameLink
                    plain
                    :name="appointment.patient_name"
                    :deleted="appointment.patient_is_deleted"
                    class="text-base font-medium text-surface-900"
                />
                <div
                    class="flex flex-wrap items-center gap-1 text-sm text-surface-500"
                >
                    <span>{{ appointment.starts_at }}</span>
                    <template v-if="appointment.doctor_name">
                        <span>·</span>
                        <RecordName
                            :name="appointment.doctor_name"
                            :deleted="appointment.doctor_is_deleted"
                        />
                    </template>
                    <span v-if="appointment.service_name">
                        · {{ appointment.service_name }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="!appointment.patient_is_deleted"
                    :href="patientShow(appointment.patient_id).url"
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                >
                    {{ t('appointment.created_card.patient_profile') }}
                    <IconChevronRight class="size-4" />
                </Link>

                <Link
                    :href="
                        appointmentsIndex({
                            query: {
                                filter: {
                                    start_date: appointment.date,
                                    end_date: appointment.date,
                                },
                            },
                        }).url
                    "
                    class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                >
                    {{ t('appointment.created_card.day_list') }}
                    <IconChevronRight class="size-4" />
                </Link>
            </div>
        </div>
    </SectionCard>
</template>
