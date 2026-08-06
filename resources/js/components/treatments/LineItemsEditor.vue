<script setup lang="ts">
import {
    IconClipboardList,
    IconPackage,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import { useMoney } from '@/composables/useMoney';
import type {
    ProductLineForm,
    ServiceLineForm,
    TreatmentProductOption,
    TreatmentServiceOption,
} from '@/types/treatment';
import { shouldFilterSelect } from '@/utils/selectFilter';
import { lineSubtotal } from '@/utils/treatmentTotals';
import { useTreatmentForm } from './formContext';

// One editor structure reused for both service and product lines — they share the same row shape
// (catalog Select + qty + editable unit-price + per-line discount + note + computed subtotal).
// `kind` switches the id field, the catalog options, the stock column and the error key prefix.
const props = defineProps<{
    kind: 'service' | 'product';
    options: TreatmentServiceOption[] | TreatmentProductOption[];
}>();

// Emitted when a catalog item is picked, so the page can auto-fill the clinical templates
// from a service's default_* (first line only; never overwrites typed text).
const emit = defineEmits<{
    select: [option: TreatmentServiceOption | TreatmentProductOption];
}>();

const { t } = useI18n();
const { currency } = useMoney();

const form = useTreatmentForm();

const isService = computed(() => props.kind === 'service');
const idField = computed<'service_id' | 'product_id'>(() =>
    isService.value ? 'service_id' : 'product_id',
);
const errorPrefix = computed(() => (isService.value ? 'services' : 'products'));

// Typed view over whichever line array this instance edits.
const lines = computed<(ServiceLineForm | ProductLineForm)[]>(() =>
    isService.value ? form.services : form.products,
);

function lineKey(line: ServiceLineForm | ProductLineForm): number | null {
    return (line as Record<string, unknown>)[idField.value] as number | null;
}

function optionFor(
    id: number | null,
): TreatmentServiceOption | TreatmentProductOption | undefined {
    if (id === null) {
        return undefined;
    }

    return (
        props.options as Array<TreatmentServiceOption | TreatmentProductOption>
    ).find((o) => o.id === id);
}

function stockFor(id: number | null): number | null {
    const option = optionFor(id) as TreatmentProductOption | undefined;

    return option && 'current_stock' in option ? option.current_stock : null;
}

function onSelect(line: ServiceLineForm | ProductLineForm): void {
    const option = optionFor(lineKey(line));

    if (!option) {
        return;
    }

    // Prefill the current catalog price; freely overridable afterward (GATE 1 decision).
    line.unit_price = Number(option.price);
    emit('select', option);
}

function addLine(): void {
    if (isService.value) {
        form.services.push({
            service_id: null,
            quantity: 1,
            unit_price: null,
            discount_amount: null,
        });
    } else {
        form.products.push({
            product_id: null,
            quantity: 1,
            unit_price: null,
            discount_amount: null,
        });
    }
}

function removeLine(index: number): void {
    if (isService.value) {
        form.services.splice(index, 1);
    } else {
        form.products.splice(index, 1);
    }
}

function error(index: number, field: string): string | undefined {
    // Per-line dotted error keys aren't part of the form's typed top-level error map.
    return (form.errors as Record<string, string | undefined>)[
        `${errorPrefix.value}.${index}.${field}`
    ];
}
</script>

<template>
    <SectionCard
        :icon="isService ? IconClipboardList : IconPackage"
        :title="
            isService
                ? t('treatment.sections.services')
                : t('treatment.sections.products')
        "
    >
        <div class="flex flex-col gap-4">
            <p
                v-if="!lines.length"
                class="rounded-lg border border-dashed border-surface-200 px-4 py-6 text-center text-sm text-surface-400"
            >
                {{
                    isService
                        ? t('treatment.lines.no_services')
                        : t('treatment.lines.no_products')
                }}
            </p>

            <div
                v-for="(line, index) in lines"
                :key="index"
                class="relative flex flex-col gap-3 rounded-lg border border-surface-200 p-4"
            >
                <Button
                    type="button"
                    severity="danger"
                    text
                    size="small"
                    class="absolute -top-2.5 -right-2.5 rounded-md border border-surface-200 bg-surface-0 shadow-sm"
                    :aria-label="t('treatment.lines.remove')"
                    @click="removeLine(index)"
                >
                    <IconTrash class="size-4" />
                </Button>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                    <div class="flex flex-col gap-1 sm:col-span-9">
                        <label class="text-xs text-surface-500">
                            {{
                                isService
                                    ? t('treatment.lines.service')
                                    : t('treatment.lines.product')
                            }}
                        </label>
                        <Select
                            :model-value="lineKey(line)"
                            :options="options"
                            option-label="name"
                            option-value="id"
                            :filter="shouldFilterSelect(options.length)"
                            :filter-placeholder="t('common.search')"
                            :invalid="
                                Boolean(
                                    error(
                                        index,
                                        isService ? 'service_id' : 'product_id',
                                    ),
                                )
                            "
                            fluid
                            @update:model-value="
                                (value: number) => {
                                    (line as Record<string, unknown>)[idField] =
                                        value;
                                    onSelect(line);
                                }
                            "
                        >
                            <template #option="{ option }">
                                <div
                                    class="flex w-full items-center justify-between gap-3"
                                >
                                    <span>{{ option.name }}</span>
                                    <span
                                        v-if="'current_stock' in option"
                                        class="text-xs"
                                        :class="
                                            option.current_stock <= 0
                                                ? 'text-red-500'
                                                : 'text-surface-400'
                                        "
                                    >
                                        {{
                                            t('treatment.lines.in_stock', {
                                                count: option.current_stock,
                                            })
                                        }}
                                    </span>
                                </div>
                            </template>
                        </Select>
                        <small
                            v-if="
                                !isService && stockFor(lineKey(line)) !== null
                            "
                            class="text-xs"
                            :class="
                                (stockFor(lineKey(line)) ?? 0) <= 0
                                    ? 'text-red-500'
                                    : 'text-surface-400'
                            "
                        >
                            {{
                                t('treatment.lines.in_stock', {
                                    count: stockFor(lineKey(line)),
                                })
                            }}
                        </small>
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-3">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.lines.quantity') }}
                        </label>
                        <InputNumber
                            v-model="line.quantity"
                            :min="1"
                            :max="999"
                            show-buttons
                            :use-grouping="false"
                            :invalid="Boolean(error(index, 'quantity'))"
                            fluid
                        />
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-4">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.lines.unit_price') }}
                        </label>
                        <InputNumber
                            v-model="line.unit_price"
                            mode="currency"
                            :currency="currency"
                            :min="0"
                            :max-fraction-digits="2"
                            :invalid="Boolean(error(index, 'unit_price'))"
                            fluid
                        />
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-4">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.lines.discount') }}
                        </label>
                        <InputNumber
                            v-model="line.discount_amount"
                            mode="currency"
                            :currency="currency"
                            :min="0"
                            :max-fraction-digits="2"
                            fluid
                        />
                        <small class="text-xs text-surface-400">
                            {{ t('treatment.lines.discount_hint') }}
                        </small>
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-4">
                        <label class="text-xs text-surface-500">
                            {{ t('treatment.lines.line_total') }}
                        </label>
                        <InputNumber
                            :model-value="lineSubtotal(line)"
                            mode="currency"
                            :currency="currency"
                            :max-fraction-digits="2"
                            readonly
                            fluid
                        />
                    </div>
                </div>
            </div>

            <div>
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    size="small"
                    :label="
                        isService
                            ? t('treatment.lines.add_service')
                            : t('treatment.lines.add_product')
                    "
                    @click="addLine"
                >
                    <template #icon>
                        <IconPlus class="size-4" />
                    </template>
                </Button>
            </div>
        </div>
    </SectionCard>
</template>
