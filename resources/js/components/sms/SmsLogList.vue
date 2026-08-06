<script setup lang="ts">
import { IconMessage } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import SmsStatusTag from '@/components/SmsStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { SmsLogItem } from '@/types/smsLog';

// Sent-SMS card for any subject the logs were filtered by (a patient's history, one appointment).
// The caller supplies the already-translated heading and empty message; the row shape is the
// canonical SmsLogItem, so every surface renders a send identically.
defineProps<{ logs: SmsLogItem[]; title: string; emptyMessage: string }>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();
</script>

<template>
    <SectionCard :icon="IconMessage" :title="title">
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
            {{ emptyMessage }}
        </p>
    </SectionCard>
</template>
