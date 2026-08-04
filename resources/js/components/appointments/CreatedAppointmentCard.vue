<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCalendarCheck, IconChevronRight, IconX } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
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
                <p class="text-base font-medium text-surface-900">
                    {{ appointment.patient_name }}
                </p>
                <p class="text-sm text-surface-500">
                    {{ appointment.starts_at }}
                    <template v-if="appointment.doctor_name">
                        · {{ appointment.doctor_name }}
                    </template>
                    <template v-if="appointment.service_name">
                        · {{ appointment.service_name }}
                    </template>
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <Link
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
