<?php

return [

    'title' => 'Randevu Oluştur',
    'created' => ':patient için :time randevusu oluşturuldu.',

    'fields' => [
        'patient' => 'Hasta',
        'doctor' => 'Doktor',
        'service' => 'Hizmet',
        'starts_at' => 'Randevu tarihi ve saati',
        'duration_minutes' => 'Süre (dakika)',
        'is_walk_in' => 'Randevusuz hasta',
    ],

    'hints' => [
        'service' => 'Hizmet seçilirse süre otomatik belirlenir.',
        'duration_minutes' => 'Boş bırakılırsa hizmet veya klinik varsayılanı kullanılır.',
        'is_walk_in' => 'Randevusuz hastalar çalışma saatleri dışında kabul edilmez; çakışma ve izin kontrolü uygulanmaz.',
    ],

    'walk_in_label' => 'Randevusuz (walk-in)',

    'new_patient_link' => 'Yeni hasta ekle',

    'errors' => [
        'outside_hours' => 'Seçilen saat, kliniğin çalışma saatleri veya mola dışında.',
        'exception' => 'Seçilen saatte doktorun izni veya kapalı günü mevcut.',
        'conflict' => 'Seçilen saatte doktorun başka bir randevusu bulunuyor.',
        'doctor_not_allowed' => 'Yalnızca kendi adınıza randevu oluşturabilirsiniz.',
        'phone_trashed' => 'Bu telefon numarası silinmiş bir hastaya ait. Hasta ekranından geri yükleyin.',
        'not_reschedulable' => 'Bu randevu yeniden planlanamaz.',
        'not_cancellable' => 'Bu randevu iptal edilemez.',
        'not_arrivable' => 'Bu randevu geldi olarak işaretlenemiyor. Randevunun durumu Onaylandı veya Yeniden Planlandı olmalıdır.',
        'not_completable' => 'Bu randevu tamamlandı olarak işaretlenemiyor. Randevunun durumu Geldi olmalıdır.',
        'delete_not_allowed' => 'Bu randevu silinemez. Lütfen iptal seçeneğini kullanın.',
    ],

    'rescheduled' => 'Randevu yeniden planlandı.',
    'cancelled' => 'Randevu iptal edildi.',
    'deleted' => 'Randevu silindi.',

    'submit' => 'Randevu Oluştur',

    'availability' => [
        'checking' => 'Müsaitlik kontrol ediliyor…',
        'available' => 'Bu saat müsait.',
        'unavailable' => 'Bu saat müsait değil.',
    ],

    'day_schedule' => [
        'title' => 'Günün Randevuları',
        'empty' => 'Bu gün için randevu bulunmuyor.',
        'loading' => 'Randevular yükleniyor…',
        'walk_in' => 'Walk-in',
        'status' => [
            'pending' => 'Bekliyor',
            'confirmed' => 'Onaylandı',
            'rescheduled' => 'Yeniden Planlandı',
            'arrived' => 'Geldi',
            'completed' => 'Tamamlandı',
            'cancelled' => 'İptal',
            'no_show' => 'Gelmedi',
        ],
    ],

];
