<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconMessage } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/PageHeader.vue';
import SmsQuotaPanel from '@/components/sms-settings/SmsQuotaPanel.vue';
import SmsTypeToggleRow from '@/components/sms-settings/SmsTypeToggleRow.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { update } from '@/routes/clinic/sms-settings';
import type { SmsType } from '@/types/enums';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    settings: Record<SmsType, boolean>;
    types: Array<{ value: SmsType }>;
    quota: {
        used: number;
        allowance: number;
        remaining: number;
        resets_at: string;
    };
}>();

const { t } = useI18n();
const { can } = useCan();

const canUpdate = computed(() => can('smsSettings.update'));

const form = useForm<{ settings: Record<SmsType, boolean> }>({
    settings: { ...props.settings },
});

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('sms_settings.title')" />

        <PageHeader
            :title="t('sms_settings.title')"
            :description="t('sms_settings.description')"
            :breadcrumbs="[{ label: t('nav.sms_settings') }]"
        />

        <SmsQuotaPanel :quota="props.quota" />

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <section
                class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
            >
                <header class="mb-6 flex items-center gap-2">
                    <IconMessage class="size-5 text-surface-500" />
                    <h2 class="text-lg font-semibold text-surface-900">
                        {{ t('sms_settings.section') }}
                    </h2>
                </header>

                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <SmsTypeToggleRow
                        v-for="type in types"
                        :key="type.value"
                        v-model="form.settings[type.value]"
                        :label="t(`sms_settings.type.${type.value}.label`)"
                        :hint="t(`sms_settings.type.${type.value}.hint`)"
                        :disabled="!canUpdate"
                    />
                </div>
            </section>

            <div v-if="canUpdate" class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('sms_settings.save')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
