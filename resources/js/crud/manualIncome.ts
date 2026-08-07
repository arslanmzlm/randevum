import ManualIncomeFields from '@/components/incomes/ManualIncomeFields.vue';
import { store } from '@/routes/incomes';
import type { CrudResource } from '@/types/crud';
import type { ManualIncome, ManualIncomeFormData } from '@/types/manualIncome';
import { toDateString } from '@/utils/datetime';

// Create-only: a transaction is immutable, so there is no update route and no toForm seed.
export const manualIncomeResource: CrudResource<
    ManualIncomeFormData,
    ManualIncome
> = {
    lang: 'income',
    store,
    empty: () => ({
        paid_at: new Date(),
        amount: null,
        payment_method: null,
        category: '',
        note: '',
    }),
    transform: (data) => ({
        ...data,
        paid_at: data.paid_at ? toDateString(data.paid_at) : '',
    }),
    fields: ManualIncomeFields,
};
