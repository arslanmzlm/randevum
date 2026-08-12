<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconBell, IconCalendarDollar, IconMessage } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import SettingRow from '@/components/SettingRow.vue';
import SmsQuotaPanel from '@/components/sms-settings/SmsQuotaPanel.vue';
import SmsTemplateField from '@/components/sms-settings/SmsTemplateField.vue';
import SmsTypeToggleRow from '@/components/sms-settings/SmsTypeToggleRow.vue';
import { useCan } from '@/composables/useCan';
import { update } from '@/routes/clinic/sms-settings';
import type {
    CustomizableSmsType,
    SmsTemplateVariable,
    SmsType,
} from '@/types/enums';
import { countSegments, MAX_SEGMENTS } from '@/utils/smsSegments';

// The SMS preferences tab of the clinic profile. Owns its own form and endpoint: the toggles and
// templates save separately from the clinic fields, which is why it is a sibling form rather than
// part of the clinic form.
const props = defineProps<{
    settings: Record<SmsType, boolean>;
    templates: Record<CustomizableSmsType, string | null>;
    defaults: Record<CustomizableSmsType, string>;
    variables: SmsTemplateVariable[];
    sample: Record<SmsTemplateVariable, string>;
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

// Customizable types in their canonical order (mirrors SmsType::customizableCases()).
const customizableTypes: CustomizableSmsType[] = [
    'appointment_created',
    'appointment_cancelled',
    'appointment_rescheduled',
    'reminder_24h',
    'reminder_1h',
];

// Soft-warning recommended variables per type (never a hard block). Cancelled
// recommends none; every other type recommends :date + :time.
const recommendedVariables: Record<CustomizableSmsType, SmsTemplateVariable[]> =
    {
        appointment_created: ['date', 'time'],
        appointment_cancelled: [],
        appointment_rescheduled: ['date', 'time'],
        reminder_24h: ['date', 'time'],
        reminder_1h: ['date', 'time'],
    };

// Textarea binds to a string; a null custom template is an empty editor (→ default).
const initialTemplates = Object.fromEntries(
    customizableTypes.map((type) => [type, props.templates[type] ?? '']),
) as Record<CustomizableSmsType, string>;

const form = useForm<{
    settings: Record<SmsType, boolean>;
    templates: Record<CustomizableSmsType, string>;
}>({
    settings: { ...props.settings },
    templates: initialTemplates,
});

// Client-side mirror of the server segment cap — blocks save when any custom
// template exceeds it (the server FormRequest re-enforces it authoritatively).
const hasOverLimitTemplate = computed(() =>
    customizableTypes.some(
        (type) => countSegments(form.templates[type]).segments > MAX_SEGMENTS,
    ),
);

function submit(): void {
    form.put(update().url, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <SmsQuotaPanel :quota="quota" />

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <SectionCard
                :icon="IconMessage"
                :title="t('sms_settings.card.appointments.title')"
            >
                <p class="-mt-2 mb-4 text-sm text-surface-500">
                    {{ t('sms_settings.card.appointments.description') }}
                </p>
                <div class="flex flex-col gap-3">
                    <SmsTemplateField
                        v-for="type in customizableTypes"
                        :key="type"
                        v-model:enabled="form.settings[type]"
                        v-model:template="form.templates[type]"
                        :type="type"
                        :label="t(`sms_settings.type.${type}.label`)"
                        :hint="t(`sms_settings.type.${type}.hint`)"
                        :variables="variables"
                        :sample="sample"
                        :default-body="defaults[type]"
                        :recommended="recommendedVariables[type]"
                        :error="form.errors[`templates.${type}`]"
                        :disabled="!canUpdate"
                    />
                </div>
            </SectionCard>

            <SectionCard
                :icon="IconBell"
                :title="t('sms_settings.card.system.title')"
            >
                <p class="-mt-2 mb-4 text-sm text-surface-500">
                    {{ t('sms_settings.card.system.description') }}
                </p>
                <!-- Not togglable: an operational notice to the clinic itself, so the row states
                     the fact instead of offering a switch that always snaps back on. -->
                <SettingRow
                    :label="t('sms_settings.type.balance_reminder.label')"
                    :description="t('sms_settings.type.balance_reminder.hint')"
                >
                    <Tag
                        severity="success"
                        :value="
                            t('sms_settings.type.balance_reminder.always_on')
                        "
                    />
                </SettingRow>
            </SectionCard>

            <SectionCard
                :icon="IconCalendarDollar"
                :title="t('sms_settings.card.installments.title')"
            >
                <p class="-mt-2 mb-4 text-sm text-surface-500">
                    {{ t('sms_settings.card.installments.description') }}
                </p>
                <div class="flex flex-col gap-3">
                    <SmsTypeToggleRow
                        v-model="form.settings.installment_due_7d"
                        :label="t('sms_settings.type.installment_due_7d.label')"
                        :hint="t('sms_settings.type.installment_due_7d.hint')"
                        :disabled="!canUpdate"
                    />
                    <SmsTypeToggleRow
                        v-model="form.settings.installment_due_1d"
                        :label="t('sms_settings.type.installment_due_1d.label')"
                        :hint="t('sms_settings.type.installment_due_1d.hint')"
                        :disabled="!canUpdate"
                    />
                </div>
            </SectionCard>

            <div v-if="canUpdate" class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('sms_settings.save')"
                    :loading="form.processing"
                    :disabled="hasOverLimitTemplate"
                />
            </div>
        </form>
    </div>
</template>
