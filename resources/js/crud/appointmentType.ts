import AppointmentTypeFields from '@/components/appointment-types/AppointmentTypeFields.vue';
import { store, update } from '@/routes/appointment-types';
import type {
    AppointmentType,
    AppointmentTypeFormData,
} from '@/types/appointmentType';
import type { CrudResource } from '@/types/crud';
import { COLOR_PRESETS } from '@/utils/colorPresets';

export const appointmentTypeResource: CrudResource<
    AppointmentTypeFormData,
    AppointmentType
> = {
    lang: 'appointment_type',
    // One step wider than the default so the colour presets stay on a single row.
    width: 'w-full max-w-lg',
    store,
    update,
    empty: () => ({
        name: '',
        color: COLOR_PRESETS[0],
        default_duration_minutes: 30,
        is_active: true,
    }),
    toForm: (type) => ({
        name: type.name,
        color: type.color,
        default_duration_minutes: type.default_duration_minutes,
        is_active: type.is_active,
    }),
    fields: AppointmentTypeFields,
};
