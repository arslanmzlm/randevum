import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PaymentMethod } from '@/types/enums';

/** Every payment method, in the order forms offer them. */
const PAYMENT_METHODS: PaymentMethod[] = ['cash', 'card', 'transfer', 'cheque'];

/**
 * Select options for the payment method, in one place: a new PaymentMethod case is a
 * single-line change here instead of a hunt across every payment form.
 */
export function usePaymentMethodOptions(): ComputedRef<
    { value: PaymentMethod; label: string }[]
> {
    const { t } = useI18n();

    return computed(() =>
        PAYMENT_METHODS.map((method) => ({
            value: method,
            label: t(`payment.method.${method}`),
        })),
    );
}
