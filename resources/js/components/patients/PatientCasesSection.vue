<script setup lang="ts">
import { IconFolders } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import type { PatientCaseItem as PatientCase } from '@/types/case';
import PatientCaseItem from './PatientCaseItem.vue';

const props = defineProps<{ cases: PatientCase[] }>();

const { t } = useI18n();

const openCases = computed<PatientCase[]>(() =>
    props.cases.filter((c) => c.status !== 'closed'),
);
const closedCases = computed<PatientCase[]>(() =>
    props.cases.filter((c) => c.status === 'closed'),
);
</script>

<template>
    <SectionCard
        v-if="cases.length"
        :icon="IconFolders"
        :title="t('patient.sections.cases')"
    >
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="flex flex-col gap-2">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('patient.cases.open') }}
                </h3>
                <ul v-if="openCases.length" class="flex flex-col gap-2">
                    <li v-for="item in openCases" :key="item.id">
                        <PatientCaseItem :case-item="item" />
                    </li>
                </ul>
                <p v-else class="text-sm text-surface-400">
                    {{ t('patient.cases.no_open') }}
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <h3 class="text-sm font-semibold text-surface-700">
                    {{ t('patient.cases.closed') }}
                </h3>
                <ul v-if="closedCases.length" class="flex flex-col gap-2">
                    <li v-for="item in closedCases" :key="item.id">
                        <PatientCaseItem
                            :case-item="item"
                            :show-follow-up="false"
                        />
                    </li>
                </ul>
                <p v-else class="text-sm text-surface-400">
                    {{ t('patient.cases.no_closed') }}
                </p>
            </div>
        </div>
    </SectionCard>
</template>
