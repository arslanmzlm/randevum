<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCrudContext, useCrudForm } from '@/components/crud/crudFormContext';
import FormField from '@/components/FormField.vue';
import type { ExpenseFormData } from '@/types/expense';

const { t } = useI18n();

const form = useCrudForm<ExpenseFormData>();
const context = useCrudContext<{ categories: string[]; currency: string }>();

// Free-text AutoComplete (no forceSelection): suggest the clinic's existing categories, but a
// typed-in new value is kept and submitted as-is (the products pattern).
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
                :label="t('expense.fields.expense_date')"
                :error="form.errors.expense_date"
                required
            >
                <DatePicker
                    v-model="form.expense_date"
                    :manual-input="false"
                    date-format="dd.mm.yy"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('expense.fields.amount')"
                :error="form.errors.amount"
                required
            >
                <InputNumber
                    v-model="form.amount"
                    mode="currency"
                    :currency="context.currency"
                    :min="0"
                    :max-fraction-digits="2"
                    fluid
                />
            </FormField>
        </div>

        <FormField
            :label="t('expense.fields.category')"
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

        <FormField
            :label="t('expense.fields.description')"
            :error="form.errors.description"
        >
            <Textarea v-model="form.description" rows="3" auto-resize fluid />
        </FormField>
    </div>
</template>
