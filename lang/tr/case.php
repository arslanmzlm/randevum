<?php

return [

    'created' => 'Vaka oluşturuldu.',
    'status_updated' => 'Vaka durumu güncellendi.',
    'notes_updated' => 'Vaka notları güncellendi.',
    'title_updated' => 'Vaka başlığı güncellendi.',
    'treatments_linked' => 'Tedaviler vakaya eklendi.',
    'treatment_unlinked' => 'Tedavi vakadan çıkarıldı.',

    'status' => [
        'open' => 'Açık',
        'suspended' => 'Askıya Alındı',
        'follow_up' => 'Takipte',
        'closed' => 'Kapatıldı',
    ],

    'actions' => [
        'close' => 'Vakayı Kapat',
        'reopen' => 'Vakayı Yeniden Aç',
        'suspend' => 'Askıya Al',
        'follow_up' => 'Takibe Al',
    ],

    'errors' => [
        'transition_not_allowed' => 'Bu durum geçişi bu vaka için izin verilmiyor.',
        'edit_window_expired' => 'Düzenleme penceresi geçti; artık yalnızca durum değişikliği yapılabilir.',
        'case_closed' => 'Tedavi bağlantısı kapatılmış vakalarda değiştirilemez.',
        'treatment_not_found' => 'Seçilen tedavi(ler) bu klinikte bulunamadı.',
        'treatment_already_linked' => 'Seçilen tedavilerden biri zaten bir vakaya bağlı.',
        'treatment_not_completed' => 'Yalnızca tamamlanmış tedaviler vakaya bağlanabilir.',
        'treatment_patient_mismatch' => 'Seçilen tedavi bu hastaya ait değil.',
        'treatment_doctor_mismatch' => 'Seçilen tedavi bu vakanın doktoruna ait değil.',
        'treatments_mixed_doctors' => 'Seçilen tedaviler farklı doktorlara ait; aynı vakaya bağlanamaz.',
        'treatment_not_linked' => 'Bu tedavi bu vakaya bağlı değil.',
        'doctor_required' => 'Doktor seçimi zorunludur.',
        'doctor_not_own' => 'Yalnızca kendi profilinize ait vakalar oluşturabilirsiniz.',
    ],

];
