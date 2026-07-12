<script setup lang="ts">
import { IconMessage } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import SmsStatusTag from '@/components/SmsStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { SmsLogItem } from '@/types/smsLog';

defineProps<{ logs: SmsLogItem[] }>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();
</script>

<template>
    <SectionCard :icon="IconMessage" :title="t('sms.log.patient.title')">
        <ul v-if="logs.length" class="flex flex-col gap-2">
            <li
                v-for="log in logs"
                :key="log.id"
                class="flex flex-col gap-1 rounded-xl border border-surface-200 p-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm font-medium text-surface-900">
                        {{ t(`sms.type.${log.type}`) }}
                    </span>
                    <SmsStatusTag :status="log.status" small />
                    <span class="ml-auto text-xs text-surface-500">
                        {{ formatDateTime(log.created_at) }}
                    </span>
                </div>
                <p class="text-sm whitespace-pre-line text-surface-600">
                    {{ log.body }}
                </p>
                <p v-if="log.error" class="text-xs text-red-500">
                    {{ t('sms.log.error_label') }}: {{ log.error }}
                </p>
            </li>
        </ul>
        <p v-else class="text-sm text-surface-400">
            {{ t('sms.log.patient.empty') }}
        </p>
    </SectionCard>
</template>
