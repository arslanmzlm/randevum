<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Messages (Turkish)
    |--------------------------------------------------------------------------
    |
    | Toast summaries and feature strings, namespaced by model.
    | Frontend mirrors live in resources/js/i18n.ts under matching namespaces.
    |
    */

    'clinic' => [
        'profile_updated' => 'Klinik profili güncellendi.',
        'media_updated' => 'Görsel güncellendi.',
        'media_removed' => 'Görsel kaldırıldı.',
    ],

    'service' => [
        'created' => 'Hizmet başarıyla oluşturuldu.',
        'updated' => 'Hizmet güncellendi.',
        'deleted' => 'Hizmet kaldırıldı.',
    ],

    'product' => [
        'created' => 'Ürün başarıyla oluşturuldu.',
        'updated' => 'Ürün güncellendi.',
        'deleted' => 'Ürün kaldırıldı.',
        'stock_updated' => 'Stok güncellendi.',
    ],

    'patient' => [
        'created' => 'Hasta kaydı başarıyla oluşturuldu.',
        'updated' => 'Hasta bilgileri güncellendi.',
        'deleted' => 'Hasta kaydı silindi.',
        'restored' => 'Hasta kaydı geri yüklendi.',
        'notes_updated' => 'Hasta notu güncellendi.',
    ],

    'schedule_exception' => [
        'added' => 'Müsaitlik istisnası eklendi.',
        'clinic_wide_added' => 'Klinik geneli kapatma tüm doktorlar için oluşturuldu.',
        'removed' => 'Müsaitlik istisnası kaldırıldı.',
    ],

    'appointment_type' => [
        'created' => 'Randevu türü başarıyla oluşturuldu.',
        'updated' => 'Randevu türü güncellendi.',
        'deleted' => 'Randevu türü kaldırıldı.',
    ],

    'payment' => [
        'recorded' => 'Ödeme kaydedildi.',
    ],

    'refund' => [
        'recorded' => 'İade kaydedildi.',
    ],

    'payment_plan' => [
        'created' => 'Taksit planı oluşturuldu.',
        'collected' => 'Taksit tahsil edildi.',
        'reminder_sent' => 'Hatırlatma SMS\'i gönderildi.',
        'reminder_skipped' => 'Hatırlatma SMS\'i gönderilmedi (klinik için bu bildirim kapalı olabilir veya kota/telefon sorunu var).',
        'cancelled' => 'Taksit planı iptal edildi.',
        'deleted' => 'Taksit planı silindi.',
    ],

    'sms_settings' => [
        'updated' => 'SMS tercihleri güncellendi.',
    ],

    'doctor' => [
        'profile_created' => 'Doktor profili oluşturuldu.',
        'profile_updated' => 'Doktor profili güncellendi.',
        'doctor_added' => 'Doktor başarıyla eklendi.',
        'doctor_removed' => 'Doktor kaldırıldı.',
        'avatar_updated' => 'Profil fotoğrafı güncellendi.',
        'avatar_removed' => 'Profil fotoğrafı kaldırıldı.',
        'already_has_profile' => 'Bu kullanıcıya ait bir doktor profili zaten mevcut.',
        'no_profile_yet' => 'Henüz bir doktor profiliniz bulunmuyor.',
        'password_reminder_title' => 'Şifrenizi değiştirin',
        'password_reminder_body' => 'Hesabınız yönetici tarafından oluşturuldu. Güvenliğiniz için geçici şifrenizi değiştirmenizi öneririz.',
        'password_reminder_action' => 'Şifremi değiştir',
        'password_reminder_dismiss' => 'Daha sonra',
        'offboarded' => ':name işten çıkarıldı, :count randevu iptal edildi.',
        'already_offboarded' => 'Bu doktor zaten işten çıkarılmış.',
        'has_upcoming_appointments' => 'Bu doktorun yaklaşan randevuları var; önce iptal edin ya da başka doktora taşıyın.',
        'cannot_offboard_self' => 'Kendinizi işten çıkaramazsınız.',
    ],

    'tag' => [
        'created' => 'Etiket oluşturuldu.',
        'updated' => 'Etiket güncellendi.',
        'deleted' => 'Etiket silindi.',
        'synced' => 'Hasta etiketleri güncellendi.',
    ],

    'segment' => [
        'saved' => 'Segment kaydedildi.',
        'deleted' => 'Segment silindi.',
    ],

    'follow_up_type' => [
        'created' => 'Takip türü oluşturuldu.',
        'updated' => 'Takip türü güncellendi.',
        'deleted' => 'Takip türü silindi.',
    ],

    'role' => [
        'permissions_updated' => 'Rol izinleri güncellendi.',
        'created' => 'Rol oluşturuldu.',
        'deleted' => 'Rol silindi.',
        'reverted' => 'Varsayılan rol izinlerine dönüldü.',
        'no_customizations' => 'Geri alınacak bir özelleştirme yok.',
        'self_lockout' => 'Kendi rolünüzden bu izni kaldıramazsınız; erişiminizi kaybedersiniz.',
        'cannot_delete_own_role' => 'Kendi rolünüzü silemezsiniz.',
        'has_assigned_users' => 'Bu role atanmış kullanıcı olduğu için silinemez.',
        'revert_locks_out' => 'Bu geri alma işlemi kendi rolünüzden bir izni kaldıracağı için yapılamaz.',
    ],

];
