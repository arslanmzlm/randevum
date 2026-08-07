<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * Province centre coordinates: Wikidata (CC0), matched to this table's city names and
     * verified against `code` (plaka). See database/seeders/data/city-coordinates.json.
     *
     * Türkiye'nin 81 ili. `code` = resmî plaka kodu (01-81) = ISO 3166-2:TR alt bölüm numarası.
     *
     * @var array<string, string>
     */
    private const TR_PROVINCES = [
        '01' => 'Adana', '02' => 'Adıyaman', '03' => 'Afyonkarahisar', '04' => 'Ağrı',
        '05' => 'Amasya', '06' => 'Ankara', '07' => 'Antalya', '08' => 'Artvin',
        '09' => 'Aydın', '10' => 'Balıkesir', '11' => 'Bilecik', '12' => 'Bingöl',
        '13' => 'Bitlis', '14' => 'Bolu', '15' => 'Burdur', '16' => 'Bursa',
        '17' => 'Çanakkale', '18' => 'Çankırı', '19' => 'Çorum', '20' => 'Denizli',
        '21' => 'Diyarbakır', '22' => 'Edirne', '23' => 'Elazığ', '24' => 'Erzincan',
        '25' => 'Erzurum', '26' => 'Eskişehir', '27' => 'Gaziantep', '28' => 'Giresun',
        '29' => 'Gümüşhane', '30' => 'Hakkâri', '31' => 'Hatay', '32' => 'Isparta',
        '33' => 'Mersin', '34' => 'İstanbul', '35' => 'İzmir', '36' => 'Kars',
        '37' => 'Kastamonu', '38' => 'Kayseri', '39' => 'Kırklareli', '40' => 'Kırşehir',
        '41' => 'Kocaeli', '42' => 'Konya', '43' => 'Kütahya', '44' => 'Malatya',
        '45' => 'Manisa', '46' => 'Kahramanmaraş', '47' => 'Mardin', '48' => 'Muğla',
        '49' => 'Muş', '50' => 'Nevşehir', '51' => 'Niğde', '52' => 'Ordu',
        '53' => 'Rize', '54' => 'Sakarya', '55' => 'Samsun', '56' => 'Siirt',
        '57' => 'Sinop', '58' => 'Sivas', '59' => 'Tekirdağ', '60' => 'Tokat',
        '61' => 'Trabzon', '62' => 'Tunceli', '63' => 'Şanlıurfa', '64' => 'Uşak',
        '65' => 'Van', '66' => 'Yozgat', '67' => 'Zonguldak', '68' => 'Aksaray',
        '69' => 'Bayburt', '70' => 'Karaman', '71' => 'Kırıkkale', '72' => 'Batman',
        '73' => 'Şırnak', '74' => 'Bartın', '75' => 'Ardahan', '76' => 'Iğdır',
        '77' => 'Yalova', '78' => 'Karabük', '79' => 'Kilis', '80' => 'Osmaniye',
        '81' => 'Düzce',
    ];

    public function run(): void
    {
        $turkey = Country::where('code', 'TR')->firstOrFail();

        $coordinatesByCode = collect(
            json_decode(file_get_contents(__DIR__.'/data/city-coordinates.json'), true),
        )->keyBy('code');

        foreach (self::TR_PROVINCES as $code => $name) {
            $coordinates = $coordinatesByCode->get($code);

            // updateOrCreate (not firstOrCreate): re-running must heal a row seeded before
            // coordinates existed, same as PermissionSeeder's authoritative re-sync.
            City::updateOrCreate(
                ['country_id' => $turkey->id, 'code' => $code],
                [
                    'name' => $name,
                    'latitude' => $coordinates['lat'] ?? null,
                    'longitude' => $coordinates['lng'] ?? null,
                    'is_active' => true,
                ],
            );
        }
    }
}
