<script setup lang="ts">
import { IconAlertTriangle, IconGauge } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDateTime } from '@/composables/useDateTime';

const props = defineProps<{
    quota: {
        used: number;
        allowance: number;
        remaining: number;
        resets_at: string;
    };
}>();

const { t } = useI18n();
const { formatDate } = useDateTime();

const percent = computed(() => {
    if (props.quota.allowance <= 0) {
        return 100;
    }

    return Math.min(
        100,
        Math.round((props.quota.used / props.quota.allowance) * 100),
    );
});

const blocked = computed(() => props.quota.remaining <= 0);
</script>

<template>
    <section
        class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
    >
        <header class="mb-6 flex items-center gap-2">
            <IconGauge class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('sms_settings.quota.title') }}
            </h2>
        </header>

        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-surface-900">
                    {{
                        t('sms_settings.quota.used', {
                            used: quota.used,
                            allowance: quota.allowance,
                        })
                    }}
                </span>
                <span class="text-sm text-surface-500">
                    {{
                        t('sms_settings.quota.remaining', {
                            remaining: quota.remaining,
                        })
                    }}
                </span>
            </div>

            <ProgressBar
                :value="percent"
                :show-value="false"
                :severity="blocked ? 'danger' : undefined"
                class="h-2"
            />

            <p class="text-xs text-surface-500">
                {{
                    t('sms_settings.quota.resets_at', {
                        date: formatDate(quota.resets_at),
                    })
                }}
            </p>

            <Message
                v-if="blocked"
                severity="warn"
                :closable="false"
                size="small"
            >
                <template #icon>
                    <IconAlertTriangle class="size-5" />
                </template>
                {{ t('sms_settings.quota.blocked') }}
            </Message>
        </div>
    </section>
</template>
