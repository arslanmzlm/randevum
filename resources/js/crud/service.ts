import ServiceFields from '@/components/services/ServiceFields.vue';
import { store, update } from '@/routes/services';
import type { CrudResource } from '@/types/crud';
import type { Service, ServiceFormData } from '@/types/service';

export const serviceResource: CrudResource<ServiceFormData, Service> = {
    lang: 'service',
    // Wider than the default: the form carries the treatment templates too.
    width: 'w-full max-w-2xl',
    store,
    update,
    empty: () => ({
        name: '',
        description: '',
        price: null,
        duration_minutes: null,
        default_complaint: '',
        default_diagnosis: '',
        default_treatment_process: '',
        is_active: true,
    }),
    toForm: (service) => ({
        name: service.name,
        description: service.description ?? '',
        price: Number(service.price),
        duration_minutes: service.duration_minutes,
        default_complaint: service.default_complaint ?? '',
        default_diagnosis: service.default_diagnosis ?? '',
        default_treatment_process: service.default_treatment_process ?? '',
        is_active: service.is_active,
    }),
    fields: ServiceFields,
};
