<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import UpcomingAppointmentsWidget from '@/components/appointments/UpcomingAppointmentsWidget.vue';
import PatientSearchSelect from '@/components/PatientSearchSelect.vue';
import type { PatientSearchResult } from '@/types/patient';

// The right-hand quick-access area. Hosts the patient search and the
// upcoming-appointments widget; each is gated by its own capability.
defineProps<{
    canViewPatients: boolean;
    canViewUpcoming: boolean;
}>();

const emit = defineEmits<{
    selectPatient: [patient: PatientSearchResult];
}>();

const { t } = useI18n();

const patientSearch = ref<{ focus: () => void } | null>(null);

// Lets AppLayout focus the docked search (header button / Ctrl·Cmd+K).
function focusSearch(): void {
    patientSearch.value?.focus();
}

defineExpose({ focusSearch });
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-surface-0">
        <header class="shrink-0 px-5 pt-6 pb-3 lg:pt-8">
            <h2
                class="text-xs font-semibold tracking-wide text-surface-500 uppercase"
            >
                {{ t('quick_access.title') }}
            </h2>
        </header>

        <div class="flex-1 space-y-4 overflow-y-auto px-4 pb-4">
            <PatientSearchSelect
                v-if="canViewPatients"
                ref="patientSearch"
                class="w-full"
                @select="emit('selectPatient', $event)"
            />
            <UpcomingAppointmentsWidget
                v-if="canViewUpcoming"
                variant="compact"
            />
        </div>
    </div>
</template>
