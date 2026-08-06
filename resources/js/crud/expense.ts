import ExpenseFields from '@/components/expenses/ExpenseFields.vue';
import { store, update } from '@/routes/expenses';
import type { CrudResource } from '@/types/crud';
import type { Expense, ExpenseFormData } from '@/types/expense';
import { parseDateString, toDateString } from '@/utils/datetime';

export const expenseResource: CrudResource<ExpenseFormData, Expense> = {
    lang: 'expense',
    store,
    update,
    empty: () => ({
        expense_date: new Date(),
        amount: null,
        category: '',
        description: '',
    }),
    toForm: (expense) => ({
        expense_date: parseDateString(expense.expense_date),
        amount: Number(expense.amount),
        category: expense.category ?? '',
        description: expense.description ?? '',
    }),
    transform: (data) => ({
        ...data,
        expense_date: data.expense_date ? toDateString(data.expense_date) : '',
    }),
    fields: ExpenseFields,
};
