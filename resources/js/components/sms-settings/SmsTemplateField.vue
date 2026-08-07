<script setup lang="ts">
import { IconRestore } from '@tabler/icons-vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CustomizableSmsType, SmsTemplateVariable } from '@/types/enums';
import { countSegments, MAX_SEGMENTS } from '@/utils/smsSegments';

const props = defineProps<{
    type: CustomizableSmsType;
    label: string;
    hint: string;
    variables: SmsTemplateVariable[];
    sample: Record<SmsTemplateVariable, string>;
    defaultBody: string;
    recommended?: SmsTemplateVariable[];
    error?: string;
    disabled?: boolean;
}>();

const enabled = defineModel<boolean>('enabled', { required: true });
const template = defineModel<string>('template', { required: true });

const { t } = useI18n();

// PrimeVue Textarea exposes its native <textarea> as `$el` — needed for
// cursor-position variable insertion.
const textareaRef = ref<{ $el: HTMLTextAreaElement } | null>(null);

const isCustom = computed(() => template.value.trim().length > 0);
const effectiveBody = computed(() =>
    isCustom.value ? template.value : props.defaultBody,
);

const segmentInfo = computed(() => countSegments(effectiveBody.value));
const overLimit = computed(() => segmentInfo.value.segments > MAX_SEGMENTS);
const multiSegment = computed(
    () => segmentInfo.value.segments > 1 && !overLimit.value,
);

// `token` is parsed out of free-typed template text, so it is an arbitrary string until
// checked against the allowed variable list — the casts below narrow it after that check.
const preview = computed(() =>
    effectiveBody.value.replace(/:([a-z_]+)/g, (match, token: string) =>
        (props.variables as string[]).includes(token)
            ? (props.sample[token as SmsTemplateVariable] ?? match)
            : match,
    ),
);

const missingRecommended = computed(() => {
    if (!isCustom.value || !props.recommended?.length) {
        return [];
    }

    return props.recommended.filter(
        (token) => !template.value.includes(`:${token}`),
    );
});

const missingRecommendedLabel = computed(() =>
    missingRecommended.value.map((token) => `:${token}`).join(', '),
);

function insertVariable(token: string): void {
    const insert = `:${token}`;
    const el = textareaRef.value?.$el;

    if (!el) {
        template.value += insert;

        return;
    }

    const start = el.selectionStart ?? template.value.length;
    const end = el.selectionEnd ?? start;
    template.value =
        template.value.slice(0, start) + insert + template.value.slice(end);

    void nextTick(() => {
        el.focus();
        const pos = start + insert.length;
        el.setSelectionRange(pos, pos);
    });
}

function reset(): void {
    template.value = '';
}
</script>

<template>
    <div class="flex flex-col gap-4 rounded-lg border border-surface-200 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-sm font-medium text-surface-900">{{
                    label
                }}</span>
                <span class="text-xs text-surface-500">{{ hint }}</span>
            </div>
            <ToggleSwitch v-model="enabled" :disabled="disabled" />
        </div>

        <div
            v-if="enabled"
            class="flex flex-col gap-3 border-t border-surface-200 pt-4"
        >
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs font-medium text-surface-500">
                    {{ t('sms_settings.template.variables_label') }}
                </span>
                <Button
                    v-for="variable in variables"
                    :key="variable"
                    type="button"
                    severity="secondary"
                    outlined
                    size="small"
                    rounded
                    :disabled="disabled"
                    :label="t(`sms_settings.template.variable.${variable}`)"
                    @click="insertVariable(variable)"
                />
            </div>

            <Textarea
                ref="textareaRef"
                v-model="template"
                rows="3"
                auto-resize
                fluid
                :disabled="disabled"
                :invalid="Boolean(error) || overLimit"
                :placeholder="defaultBody"
                :aria-label="t('sms_settings.template.label')"
            />

            <div
                class="flex flex-wrap items-center justify-between gap-2 text-xs"
            >
                <span
                    :class="
                        overLimit
                            ? 'text-red-500'
                            : multiSegment
                              ? 'text-amber-600'
                              : 'text-surface-500'
                    "
                >
                    {{
                        t('sms_settings.template.counter', {
                            chars: segmentInfo.length,
                            segments: segmentInfo.segments,
                        })
                    }}
                </span>
                <Button
                    type="button"
                    severity="secondary"
                    text
                    size="small"
                    :disabled="disabled || !isCustom"
                    :label="t('sms_settings.template.reset')"
                    @click="reset"
                >
                    <template #icon>
                        <IconRestore class="size-4" />
                    </template>
                </Button>
            </div>

            <small v-if="error" class="text-xs text-red-500">{{ error }}</small>
            <small v-else-if="overLimit" class="text-xs text-red-500">
                {{
                    t('sms_settings.template.segment_limit', {
                        max: MAX_SEGMENTS,
                    })
                }}
            </small>
            <small v-else-if="multiSegment" class="text-xs text-amber-600">
                {{
                    t('sms_settings.template.segment_warning', {
                        segments: segmentInfo.segments,
                    })
                }}
            </small>

            <small
                v-if="missingRecommended.length"
                class="text-xs text-amber-600"
            >
                {{
                    t('sms_settings.template.recommended_warning', {
                        variables: missingRecommendedLabel,
                    })
                }}
            </small>

            <div class="flex flex-col gap-1 rounded-lg bg-surface-50 p-3">
                <span class="text-xs font-medium text-surface-500">
                    {{ t('sms_settings.template.preview') }}
                </span>
                <p class="text-sm whitespace-pre-line text-surface-700">
                    {{ preview }}
                </p>
            </div>
        </div>
    </div>
</template>
