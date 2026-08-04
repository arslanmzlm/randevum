<?php

namespace Database\Seeders;

use App\Enums\TreatmentStatus;
use App\Models\Clinic;
use App\Models\Treatment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Placeholder before/after images on a few completed treatments so the treatment gallery, the
 * case media rollup and the authorized streaming route have something to render. Images are
 * generated on the fly (no binary fixtures in the repo).
 *
 * Runs AFTER DemoCasesSeeder (needs completed treatments).
 */
class DemoTreatmentMediaSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();

        if (! $clinic || ! extension_loaded('gd')) {
            return;
        }

        $treatments = Treatment::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('status', TreatmentStatus::Completed)
            ->orderBy('id')
            ->take(6)
            ->get();

        foreach ($treatments as $index => $treatment) {
            if ($treatment->getMedia('treatment_media')->isNotEmpty()) {
                continue;
            }

            foreach ([['Öncesi', [204, 132, 106]], ['Sonrası', [138, 178, 148]]] as [$label, $rgb]) {
                $path = $this->drawPlaceholder($label.' '.($index + 1), $rgb);

                $treatment
                    ->addMedia($path)
                    ->usingFileName(mb_strtolower($label).'-'.($index + 1).'.jpg')
                    ->withCustomProperties(['note' => $label.' görüntüsü'])
                    ->toMediaCollection('treatment_media');
            }
        }

        // The model registers its conversions as ->queued() (a production decision), and a seeded
        // dev database has no worker behind it — drain the media queue here or the gallery renders
        // thumbless rows until someone starts one.
        Artisan::call('queue:work', [
            '--queue' => config('media-library.queue_name') ?: 'media',
            '--stop-when-empty' => true,
        ]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function drawPlaceholder(string $label, array $rgb): string
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));

        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 40, 40, $label, $textColor);

        $path = tempnam(sys_get_temp_dir(), 'demo-media').'.jpg';
        imagejpeg($image, $path, 85);
        imagedestroy($image);

        return $path;
    }
}
