<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCrudContext, useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import { useMoney } from '@/composables/useMoney';
import { usePaymentMethodOptions } from '@/composables/usePaymentMethodOptions';
import type { ManualIncomeFormData } from '@/types/manualIncome';

const { t } = useI18n();

const form = useCrudForm<ManualIncomeFormData>();
const context = useCrudContext<{ categories: string[] }>();
const { currency } = useMoney();

const today = new Date();

const methodOptions = usePaymentMethodOptions();

// Free-text AutoComplete (no forceSelection): suggest the clinic's existing categories, but a
// typed-in new value is kept and submitted as-is (the expense pattern).
const categorySuggestions = ref<string[]>([]);

function filterCategories(query: string): void {
    const q = query.toLowerCase();

    categorySuggestions.value = context.value.categories.filter((value) =>
        value.toLowerCase().includes(q),
    );
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <FormField
                :label="t('income.fields.paid_at')"
                :error="form.errors.paid_at"
                required
            >
                <DatePicker
                    v-model="form.paid_at"
                    :manual-input="false"
                    :max-date="today"
                    date-format="dd.mm.yy"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('income.fields.amount')"
                :error="form.errors.amount"
                required
            >
                <InputNumber
                    v-model="form.amount"
                    mode="currency"
                    :currency="currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>
        </div>

        <FormField
            :label="t('income.fields.payment_method')"
            :error="form.errors.payment_method"
            required
        >
            <Select
                v-model="form.payment_method"
                :options="methodOptions"
                option-label="label"
                option-value="value"
                fluid
            />
        </FormField>

        <FormField
            :label="t('income.fields.category')"
            :error="form.errors.category"
        >
            <AutoComplete
                v-model="form.category"
                :suggestions="categorySuggestions"
                dropdown
                fluid
                @complete="filterCategories($event.query)"
            />
        </FormField>

        <FormField :label="t('income.fields.note')" :error="form.errors.note">
            <Textarea v-model="form.note" rows="3" auto-resize fluid />
        </FormField>
    </div>
</template>
