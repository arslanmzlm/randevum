<script setup lang="ts">
import { IconArrowNarrowRight } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { AppointmentStatusLogEntry } from '@/types/appointment';

// Vertical timeline of one appointment's status_logs, oldest first. The left rail is a dot per
// entry with a connector between them; a null actor is a system/scheduler transition.
defineProps<{ entries: AppointmentStatusLogEntry[] }>();

const { t } = useI18n();
const { formatDateTime } = useDateTime();
</script>

<template>
    <ol v-if="entries.length" class="flex flex-col">
        <li
            v-for="(entry, index) in entries"
            :key="entry.id"
            class="flex gap-3"
        >
            <div class="flex flex-col items-center">
                <span
                    class="mt-1.5 size-2.5 shrink-0 rounded-full bg-primary-400"
                    aria-hidden="true"
                />
                <span
                    v-if="index < entries.length - 1"
                    class="w-px flex-1 bg-surface-200"
                    aria-hidden="true"
                />
            </div>

            <div
                class="flex min-w-0 flex-1 flex-col gap-1"
                :class="index < entries.length - 1 ? 'pb-5' : ''"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-surface-500">
                        {{
                            entry.from_status
                                ? t(`appointment.status.${entry.from_status}`)
                                : t('appointment_detail.status_history.initial')
                        }}
                    </span>
                    <IconArrowNarrowRight
                        class="size-4 shrink-0 text-surface-300"
                        aria-hidden="true"
                    />
                    <AppointmentStatusTag :status="entry.to_status" small />
                </div>

                <div
                    class="flex flex-wrap items-center gap-x-2 text-xs text-surface-500"
                >
                    <span>{{ formatDateTime(entry.transitioned_at) }}</span>
                    <span aria-hidden="true">·</span>
                    <span>
                        {{
                            entry.by_user_name ??
                            t('appointment_detail.status_history.system_actor')
                        }}
                    </span>
                </div>

                <p
                    v-if="entry.reason"
                    class="text-sm whitespace-pre-line text-surface-600"
                >
                    {{ entry.reason }}
                </p>
            </div>
        </li>
    </ol>

    <p v-else class="text-sm text-surface-400">
        {{ t('appointment_detail.status_history.empty') }}
    </p>
</template>
