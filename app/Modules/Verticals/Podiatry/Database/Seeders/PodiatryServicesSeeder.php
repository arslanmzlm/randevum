<?php

namespace App\Modules\Verticals\Podiatry\Database\Seeders;

use App\Models\Clinic;
use App\Models\Service;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

class PodiatryServicesSeeder extends Seeder
{
    /**
     * Default services for podiatry clinics, each with starter clinical templates the Process
     * screen (1.10) auto-fills. Idempotent — safe to re-run.
     *
     * @var array<int, array{name: string, price: float, duration_minutes: int, default_complaint: string, default_diagnosis: string, default_treatment_process: string}>
     */
    private const SERVICES = [
        [
            'name' => 'Diyabetik Ayak Muayenesi',
            'price' => 400.00,
            'duration_minutes' => 30,
            'default_complaint' => 'Diyabete bağlı ayak kontrolü, his kaybı / yara şikayeti.',
            'default_diagnosis' => 'Diyabetik ayak risk değerlendirmesi yapıldı.',
            'default_treatment_process' => 'Nörolojik ve vasküler muayene, basınç noktaları kontrolü, hasta eğitimi.',
        ],
        [
            'name' => 'Tırnak Batması Tedavisi (Ortonixi)',
            'price' => 600.00,
            'duration_minutes' => 45,
            'default_complaint' => 'Ayak başparmağında batma, kızarıklık ve ağrı.',
            'default_diagnosis' => 'Unguis incarnatus (tırnak batması).',
            'default_treatment_process' => 'Tırnak kenarı temizlendi, ortonixi tel/klips uygulandı, pansuman yapıldı.',
        ],
        [
            'name' => 'Nasır ve Siğil Tedavisi',
            'price' => 350.00,
            'duration_minutes' => 30,
            'default_complaint' => 'Ayak tabanında sertleşme / basınca bağlı ağrı.',
            'default_diagnosis' => 'Kallus (nasır) / verruca plantaris.',
            'default_treatment_process' => 'Debridman ile sert doku alındı, basınç dağıtıcı ped önerildi.',
        ],
        [
            'name' => 'Mantar (Onikomikoz) Tedavisi',
            'price' => 450.00,
            'duration_minutes' => 30,
            'default_complaint' => 'Tırnakta renk değişikliği, kalınlaşma ve kırılma.',
            'default_diagnosis' => 'Onikomikoz (tırnak mantarı).',
            'default_treatment_process' => 'Tırnak inceltildi, topikal antifungal uygulandı, bakım planı verildi.',
        ],
        [
            'name' => 'Medikal Ayak Bakımı',
            'price' => 500.00,
            'duration_minutes' => 45,
            'default_complaint' => 'Genel ayak bakımı talebi.',
            'default_diagnosis' => 'Rutin medikal pedikür endikasyonu.',
            'default_treatment_process' => 'Tırnak kesimi, kallus temizliği, nemlendirme ve bakım önerileri.',
        ],
        [
            'name' => 'Kişiye Özel Tabanlık (Ortez)',
            'price' => 1500.00,
            'duration_minutes' => 60,
            'default_complaint' => 'Yürürken ayak/topuk ağrısı, duruş bozukluğu.',
            'default_diagnosis' => 'Biyomekanik değerlendirme sonucu ortez endikasyonu.',
            'default_treatment_process' => 'Ayak izi/basınç ölçümü alındı, kişiye özel tabanlık planlandı.',
        ],
        [
            'name' => 'Topuk Dikeni Tedavisi',
            'price' => 550.00,
            'duration_minutes' => 30,
            'default_complaint' => 'Sabah ilk adımda topukta keskin ağrı.',
            'default_diagnosis' => 'Plantar fasiit / topuk dikeni.',
            'default_treatment_process' => 'Germe egzersizleri, topuk pedi ve gece ateli önerildi.',
        ],
    ];

    public function run(): void
    {
        $podiatry = Vertical::where('slug', 'podiatry')->first();

        if ($podiatry === null) {
            return;
        }

        $clinics = Clinic::where('vertical_id', $podiatry->id)->get();

        foreach ($clinics as $clinic) {
            foreach (self::SERVICES as $serviceData) {
                Service::withoutGlobalScopes()->firstOrCreate(
                    ['clinic_id' => $clinic->id, 'name' => $serviceData['name']],
                    array_merge($serviceData, [
                        'vertical_id' => $podiatry->id,
                        'is_active' => true,
                    ]),
                );
            }
        }
    }
}
