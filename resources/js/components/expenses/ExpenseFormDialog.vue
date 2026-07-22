<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { store, update } from '@/routes/expenses';
import type { Expense, ExpenseFormData } from '@/types/expense';
import { parseDateString, toDateString } from '@/utils/datetime';

// Self-contained add/edit dialog owning its own Inertia form (the AdjustStockDialog /
// RecordPaymentDialog pattern), so both the finance page and Giderlerim reuse it as-is.
// `expense` null = create, otherwise edit that row.
const props = defineProps<{
    expense: Expense | null;
    categories: string[];
    currency: string;
}>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

// Free-text AutoComplete (no forceSelection): suggest the clinic's existing categories,
// but a typed-in new value is kept and submitted as-is (the products pattern).
const categorySuggestions = ref<string[]>([]);

function filterCategories(query: string): void {
    const q = query.toLowerCase();
    categorySuggestions.value = props.categories.filter((value) =>
        value.toLowerCase().includes(q),
    );
}

const form = useForm<ExpenseFormData>({
    expense_date: new Date(),
    amount: null,
    category: '',
    description: '',
});

// Seed (edit) or reset (create) each time the dialog opens.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();

    if (props.expense) {
        form.expense_date = parseDateString(props.expense.expense_date);
        form.amount = Number(props.expense.amount);
        form.category = props.expense.category ?? '';
        form.description = props.expense.description ?? '';
    } else {
        form.reset();
        form.expense_date = new Date();
    }
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        expense_date: data.expense_date ? toDateString(data.expense_date) : '',
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
        },
    };

    if (props.expense) {
        form.put(update(props.expense.id).url, options);
    } else {
        form.post(store().url, options);
    }
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :draggable="false"
        :header="expense ? t('expense.edit_title') : t('expense.create_title')"
        class="w-full max-w-md"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
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
                        :currency="currency"
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
                <Textarea
                    v-model="form.description"
                    rows="3"
                    auto-resize
                    fluid
                />
            </FormField>

            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="t('expense.submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
