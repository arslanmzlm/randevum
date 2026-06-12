<?php

return [

    'created' => 'Vaka oluşturuldu.',
    'status_updated' => 'Vaka durumu güncellendi.',
    'notes_updated' => 'Vaka notları güncellendi.',
    'follow_up_updated' => 'Takip bilgileri güncellendi.',
    'title_updated' => 'Vaka başlığı güncellendi.',
    'treatments_linked' => 'Tedaviler vakaya eklendi.',

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
        'follow_up_date_required' => 'Takip tarihi, "Takipte" durumu için zorunludur.',
        'edit_window_expired' => 'Düzenleme penceresi geçti; artık yalnızca durum değişikliği yapılabilir.',
        'case_not_open' => 'Tedavi bağlantısı yalnızca açık vakalara yapılabilir.',
        'treatment_not_found' => 'Seçilen tedavi(ler) bu klinikte bulunamadı.',
        'treatment_already_linked' => 'Seçilen tedavilerden biri zaten bir vakaya bağlı.',
        'treatment_not_completed' => 'Yalnızca tamamlanmış tedaviler vakaya bağlanabilir.',
        'treatment_patient_mismatch' => 'Seçilen tedavi bu hastaya ait değil.',
        'treatment_doctor_mismatch' => 'Seçilen tedavi bu vakanın doktoruna ait değil.',
        'treatments_mixed_doctors' => 'Seçilen tedaviler farklı doktorlara ait; aynı vakaya bağlanamaz.',
        'doctor_required' => 'Doktor seçimi zorunludur.',
        'doctor_not_own' => 'Yalnızca kendi profilinize ait vakalar oluşturabilirsiniz.',
    ],

];
