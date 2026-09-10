<p align="center">
  <img src="public/favicon.svg" alt="Randevum" width="88">
</p>

<h1 align="center">Randevum</h1>

<p align="center">
  Türkiye için çok kiracılı klinik yönetim SaaS'ı. Randevu, hasta kaydı, tedavi, tahsilat ve SMS tek panelde. Laravel 13 + Inertia v3 + Vue 3 üzerine, klinik bazlı kiracılık ve izin tabanlı yetkilendirmeyle kurulmuş modüler monolit.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue.svg" alt="License: AGPL-3.0"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/laravel-13-red.svg" alt="Laravel 13"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/php-8.4-777bb4.svg" alt="PHP 8.4"></a>
  <a href="package.json"><img src="https://img.shields.io/badge/vue-3-42b883.svg" alt="Vue 3"></a>
  <a href="tests"><img src="https://img.shields.io/badge/tests-2633%20passing-green.svg" alt="2633 test"></a>
</p>

<p align="center">
  <a href="README.md">English</a>
</p>

---

> **Durum: askıya alındı, portföy projesi olarak arşivlendi.**
> B2B çekirdek ve ilk genişleme dalgası bitti, test takımı yeşil (2633 Pest testi). Geliştirme
> Ağustos 2026'da, lansmandan önce durdu; sebebi [Geliştirme neden durdu](#geliştirme-neden-durdu)
> bölümünde. Canlı müşteri yok, bu sistemde hiç hasta verisi bulunmadı.

## Randevum nedir?

Randevum, dikey bir SaaS'ın klinik tarafı: bir kliniğin gününü yürüttüğü tek bir Laravel monoliti.
Resepsiyon doktor takvimine randevu açar, doktor vakayı ve tedaviyi kaydeder, ön büro tahsilatı
taksitle alır, sistem hatırlatma SMS'ini gönderir. Hastalar platformun değil, kliniğin kaydıdır.

Mimari, neredeyse her dosyayı şekillendiren iki fikrin üzerine kurulu:

**Bir klinik, bir kiracıdır.** Her operasyonel tablo `clinic_id` taşır ve kliniğe ait modeller
fail-closed bir global scope kullanır. Aktif klinik yoksa sorgu her şeyi değil, hiçbir şeyi döner.
Kiracı izolasyonu burada bir teamül değil, test şartı: her CRUD özelliği A kliniğinin B'nin
verisini okuyamadığını ve değiştiremediğini kanıtlayan bir testle geliyor.

**Bir vertical bir klasördür.** Yayına giren tek vertical podoloji ve tamamen
`app/Modules/Verticals/Podiatry` altında duruyor: kendi config'i, dil dosyaları, migration'ları ve
seeder'ları. Hasta tarafının sözlüğü (hasta, danışan, müşteri) vertical'ın dil dosyasından geliyor,
yani ikinci bir vertical alan adı koduna dokunmadan kelimeleri değiştiriyor.

## Geliştirme neden durdu

29 Mart 2025 tarihli Sağlık Meslek Mensuplarının Serbest Meslek İcrası Hakkında Yönetmelik,
podolojiyi diyetisyenlik, psikoloji ve fizyoterapiyle birlikte ruhsatlı sağlık hizmeti haline
getirdi. MADDE 18, hasta kaydının Sağlık Bakanlığı'nın Kayıt Tescil Sistemi'nde (KTS) tescilli bir
sağlık bilgi yönetim sisteminde tutulmasını ve merkezi sağlık veri sistemine (e-Nabız) aktarılmasını
zorunlu kılıyor.

Ürün hukuken "randevu programı" değil, anamnez ve tedavi kaydı tuttuğu için MBYS (Muayenehane Bilgi
Yönetim Sistemi). Tescil, firma seviyesinde TÜRKAK akrediteli ISO 27001 ve SPICE Seviye 2 (ya da
CMMI Seviye 3), ardından Bakanlık yazılım denetimi ve e-Nabız entegrasyonu istiyor. Yönetmeliğin
16. maddesi ayrıca sağlık hizmeti verilerinin yurt dışında tutulmasını yasaklıyor, bu da alışılmış
bulut varsayılanlarını devre dışı bırakıyor.

Henüz şirketi olmayan iki kişilik bir ekip için sertifikasyon maliyeti ve 9-18 aylık takvim üründen
büyük. Demo ve pilot bu kuraldan etkilenmiyor, ama ruhsatlı bir kliniğe ücretli satış tescil
olmadan mümkün değil. Bu yüzden proje yarım lanse edilmek yerine rafa kaldırıldı. Kuyrukta bekleyen
uyum işleri (alan bazlı şifreleme, erişim audit logu, 2FA, saklama otomasyonu, veri export'u)
[Yapılmayanlar](#yapılmayanlar) bölümünde.

## Özellikler

Aşağıdakilerin tamamı yazıldı, test edildi ve main'e girdi.

### Randevu ve takvim

- Ay, hafta ve gün görünümlü takvim: gün görünümünde doktor başına kolon, dakika hassasiyetinde
  konumlanan randevu çipleri, çakışma şeritleri, kapalı saat ve mola bantları, doktor bazlı izin ve
  şimdi çizgisi. Takvim kütüphanesi kullanılmadı, sıfırdan yazıldı.
- Walk-in destekli randevu oluşturma; çalışma saatleri, doktor izni ve mevcut randevulara karşı
  çakışma kontrolü; takvimi renklendiren randevu türleri.
- Onay, iptal ve değişiklik akışları durum kaydıyla; günü kapatmak için toplu iptal.
- Resepsiyon için toplu oluşturma: tek hasta, N randevu.
- Doktor müsaitlik ve izin yönetimi; etkilenen randevuları iptal edip hastalara haber veren tek
  adımlı doktor işten çıkış akışı.

### Klinik

- Arama, not, etiket ve CRM segmentleriyle hasta kayıtları; son ziyaret ve kalan bakiye kolonları.
- Tedavileri klinik bir iplik altında toplayan vaka yönetimi.
- Şikâyet, tanı ve süreç alanlarıyla tedavi kaydı; bunları dolduran klinik hizmet şablonları;
  fiyatı anlık olarak kopyalanan hizmet ve ürün satırları; stok tüketimi.
- Tamamlanmış tedavi için void: satır bazında stok iadesi, açık tahsilat varken engel, rapordan
  geriye dönük düşme.
- Vertical'a özgü sabit şemayla anamnez, dijital onay ve yazdırılabilir PDF.
- Tedaviye öncesi-sonrası medya, HEIC dönüşümü dahil.
- Vakaya bağlı takip kayıtları; paket tarihlerini müsaitlik rozetine bakarak satır satır
  düzenlemeye izin veren önizleme.

### Finans

- Parçalı ödeme, hasta bakiyesi, iade ve takvimli takip edilen taksit planları.
- Manuel gelir ve gider kaydı, tipli sebeplerle stok hareket geçmişi.
- Hazır aralıklar, ödeme yöntemi ve zaman kırılımları, Excel export'u ve para hareketinde kendini
  düşüren etiket bazlı cache ile ciro raporları.

### Mesajlaşma

- Tip bazlı klinik anahtarlarıyla SMS geçidi: klinik hangi gönderimi istediğine kendi karar veriyor.
- Otomatik 24 saat ve 1 saat hatırlatmaları, durum değişikliği mesajları, klinik bazlı şablonlar,
  gönderim log ekranı ve sert bloklu aylık kota. OTP her zaman geçidi baypas ediyor.

### Platform

- Fortify ile e-posta ve şifre girişi, ikinci seçenek olarak telefon ve SMS OTP ile şifresiz giriş.
  Telefon doğrulaması kayıt anında değil, hastanın ilk randevusunda isteniyor; kayıt sürtünmesi
  artmıyor.
- Global ve klinik kapsamlı iki katmanda dokuz temel rol; `clinic_id` üzerinden Teams özelliğiyle
  çalışan Spatie Permission.
- Kliniğin rolleri özelleştirdiği izin matrisi ekranı. Temel roller global şablon; klinik ilk kez
  düzenlediğinde kliniğe ait bir satıra kopyalanıyor, yani bir kliniğin değişikliği diğerine hiç
  değmiyor. Özel roller sıfır izinle başlıyor ve kliniğe özel. Sunucu tarafındaki bir muhafız,
  kullanıcının kendi rol yönetme yetkisini elinden almasını engelliyor.
- Çoklu şube, çalışma saatleri, logo varyantları ve harita konumuyla klinik profili.
- Sunucu tarafında ve Vue katmanında baştan sona Türkçe ve İngilizce.
- Kayıt sırasında onay toplayan sürümlü yasal belge altyapısı.
- Şifreli ve izlenen veritabanı yedekleri, kuyruklar için Horizon, uygulama metrikleri için Pulse.

## Teknoloji

| Katman | Teknoloji |
| --- | --- |
| Backend | Laravel 13, PHP 8.4 |
| Veritabanı | PostgreSQL 16 (`timestamptz`, `jsonb`, kısmi indeksler) |
| Cache, kuyruk, oturum | Redis |
| Frontend | Inertia.js v3, Vue 3, TypeScript, Tailwind CSS v4, PrimeVue v4 (Aura) |
| Tipli route'lar | Laravel Wayfinder |
| Kimlik | Fortify (oturum) + Sanctum (API token), Teams'li Spatie Permission |
| Kuyruk, izleme | Horizon, Pulse |
| Medya | Spatie Media Library, HEIC delegesiyle Imagick |
| Belgeler | Spatie PDF + Browsershot, maatwebsite/excel |
| Yedekleme | Spatie Backup, S3 uyumlu depolamaya şifreli arşiv |
| İkonlar | Tabler |
| Tarih | UTC'den klinik saat dilimine geçiş için date-fns ve date-fns-tz, gösterim için native `Intl` |
| Test | Pest v4, tarayıcı smoke testleri dahil 2633 test |
| Yerel çalışma | DDEV (nginx, PHP 8.4, PostgreSQL 16, Node 24, corepack ile pnpm) |
| Kod stili | Pint, ESLint v9, Prettier, `vue-tsc` |

## Mimari

### Modüler monolit

Backend kodu `app/Modules/` altında iş alanına göre örgütlü, UI yüzeyine göre değil. `Clinic/`,
`Admin/` ya da `Api/` ayrımı yok; çünkü aynı veriye dokunan bir klinik ekranı ile bir admin ekranı
aynı modüle aittir.

| Modül | Sorumluluğu |
| --- | --- |
| `Core` | Paylaşılan çekirdek: klinik context'i, toast'lar, durum kayıtları, temel exception'lar |
| `Identity` | Kullanıcılar, doktorlar, roller, izin matrisi, klinik üyeliği |
| `Scheduling` | Randevular, takvim, müsaitlik, çakışma, izin |
| `Medical` | Hastalar, vakalar, tedaviler, anamnez, takipler |
| `Catalog` | Hizmetler, ürünler, stok |
| `Billing` | İşlemler, bakiye, iadeler, taksit planları, giderler |
| `Messaging` | SMS sağlayıcıları, şablonlar, kuyruk, kota, loglar |
| `Media` | Yüklemeler, dönüşümler, özel disk yönlendirmesi |
| `Compliance` | Yasal belgeler ve rızalar |
| `Reporting` | Analitik toplamlar ve export'lar |
| `Verticals` | Kendi kendine yeten vertical paketleri (Podoloji) |

Eloquent modelleri Laravel teamülüne uyup `app/Models/` içinde düz duruyor. Bir modül, modeli dosya
konumuyla değil, servisleri ve repository'leriyle sahipleniyor.

Katmanlama `Controller → FormRequest → Service → Repository → Model`. Controller'lar ince, iş
mantığı servislerde, sorgu mantığı repository'lerde.

### Modül sınırlarını testler zorluyor

Modüller arası iletişim ya bir servis sözleşmesinden (senkron, veri geri gerekiyorsa) ya da bir
domain event'inden (asenkron, gönder-unut) geçiyor. Bir modül başka bir modülün modelini,
repository'sini veya somut servisini asla import etmiyor.

Sözleşme, onu KARŞILAYAN modülün `Contracts/` klasöründe durur ve o dikişe adanmış ince bir sınıfla
karşılanır, modülün orkestrasyon servisiyle değil. Bu kural var; çünkü alternatifi bir konteyner
döngüsü üretti ve döngü makineyi OOM'a soktu. `tests/Feature/ArchTest.php` ve
`tests/Feature/ContainerCycleTest.php` iki kuralı da zorluyor, ihlalde CI kırılıyor. Gerekçeli
istisnalar sebebiyle birlikte adlandırılmış bir listede duruyor.

`Reporting` tek belgelenmiş istisna: repository'leri analitik toplamlar için başka bir modülün
tablolarına doğrudan join atabiliyor. Yalnızca tablolara, import yine yok, ve daraltıcı bir arch
kuralı bunu dürüst tutuyor.

### Yetkilendirme

Özellikler rol adına göre değil, izne göre yetkilendiriliyor. Policy'ler `$user->can('<yetki>')`
çağırıyor, frontend aynı paylaşılan izin listesini `useCan()` composable'ıyla okuyup her kontrolü
sunucunun zorladığı yetkinin AYNISIYLA gate'liyor. Controller'dan aşağı geçirilen `canManage` ya da
`canDelete` gibi ad-hoc boolean'lar yok; çünkü onlar sunucunun gerçekten kontrol ettiğinden sapıyor
ve sonunda basınca 403 veren bir buton gösteriyorlar.

İzne çevrilemeyen sahiplik kararları (bir doktorun kendi profilini düzenlemesi gibi) policy'nin
içinde, izin kontrolünün yanında sahiplik mantığı olarak kalıyor.

İzinler ve roller global satır olduğu, yalnızca rol-kullanıcı ataması klinik kapsamlı olduğu için,
istek başına izin takım kimliğini bir kez set etmek her `can()` çağrısını otomatik olarak klinik
kapsamlı yapıyor.

### Frontend

Vue sayfaları controller isim alanlarını yansıtacak şekilde `resources/js/pages` altında.
Paylaşılan tanımlar tek yerde: durum-renk haritası bir `utils/` modülü artı küçük bir bileşen,
sayfa başına yeniden inline edilmiyor; böylece renkler ve etiketler ekranlar arasında kayamıyor.
Form alanları, slot'a konan PrimeVue kontrolüne input id'sini ve geçersizlik durumunu enjekte eden
tek bir `FormField.vue` sarmalayıcısından geçiyor. Büyük form sayfaları alan parçalarına ayrılıyor
ve Inertia formunu prop yerine provide/inject ile paylaşıyor; bu da `vue/no-mutating-props`'u
memnun ediyor.

Her yıkıcı aksiyon bir onay diyaloğundan geçiyor. Geri bildirim flash toast, başarısız validasyon
alan başına değil tek bir genel toast gösteriyor; çünkü satır içi alan hataları zaten alan başına
sinyal.

Karanlık mod uçtan uca kablolanmış (PrimeVue `darkModeSelector`, `<html>` üzerinde `.dark` sınıfı,
sabit renk yerine design token) ama bilinçli olarak açılmamış.

## Proje düzeni

```
app/
├── Enums/                    # String tabanlı enum'lar, FE dallanıyorsa TS union olarak yansıtılır
├── Models/                   # Düz, Laravel teamülü
├── Modules/
│   ├── Core/                 # Paylaşılan çekirdek, her yerden import edilebilir
│   ├── <Domain>/
│   │   ├── Contracts/        # Bu modülün karşıladığı modüller arası dikişler
│   │   ├── Services/         # İş mantığı
│   │   ├── Repositories/     # Sorgu mantığı
│   │   ├── Http/             # Controller + form request
│   │   ├── Events/ Listeners/ Jobs/
│   │   └── <Domain>ServiceProvider.php
│   └── Verticals/Podiatry/   # Kendi kendine yeter: config, lang, migration, seeder
├── Policies/
└── Scopes/                   # ClinicScope (fail-closed)
database/
├── migrations/               # Merkezi, global sıralı
└── seeders/                  # Temel (idempotent) + Demo* fixture'ları
lang/{tr,en}/                 # Sunucu tarafı metinler, validasyon alan adları
resources/js/
├── pages/                    # Inertia sayfaları
├── components/               # FormField, DataTableWrapper, calendar/, özellik klasörleri
├── composables/              # useCan, useDateTime, useTableFilters, useCalendarEvents
├── locales/                  # Dil başına tek dosya, i18n.ts'te kayıtlı
├── utils/                    # datetime, calendarLayout, durum haritaları
└── types/                    # enums.ts PHP enum'larını elle yansıtır
routes/                       # Alana göre bölünmüş: auth, admin, clinic, billing, messaging, reporting
tests/
├── Feature/                  # Çoğunluk; ArchTest, ContainerCycleTest ve kiracı izolasyonu dahil
├── Browser/                  # Gerçek Chromium üzerinden Pest v4 smoke testleri
└── Unit/
.ai/
├── guidelines/               # Kesişen değişmez kurallar, ajan talimatlarına merge edilir
└── skills/domain-rules/      # Alan bazlı iş kuralları, talep üzerine yüklenir
```

## Kurulum

### Gereksinimler

Desteklenen yol DDEV ve her şeyi kendisi getiriyor. DDEV olmadan PHP 8.4, Composer 2, corepack ile
pnpm çalıştıran Node 24, PostgreSQL 16, Redis ve iPhone fotoğraflarının önizlenmesini istiyorsan
HEIC delegeli Imagick gerekiyor.

### DDEV ile hızlı başlangıç

```bash
git clone https://github.com/arslanmzlm/randevum.git
cd randevum
cp .env.example .env
ddev start                       # ilk çalıştırma sudo ile /etc/hosts'a yazar
ddev composer install
ddev pnpm install
ddev php artisan key:generate
ddev php artisan migrate --seed
ddev pnpm run build
```

Uygulama `https://randevum.ddev.site` adresinde (global DDEV config'in `project_tld: test` ise
`https://randevum.test`).

`migrate --seed` yalnızca temel veriyi yüklüyor: roller, izinler, ülkeler, şehirler, vertical'lar,
anamnez alan tanımları ve yasal belgeler. Hepsi idempotent, yani tekrar çalıştırmak satır
çoğaltmıyor, kaymayı düzeltiyor.

### Demo verisi

Personeli, katalogu, hastaları ve dolu bir operasyon geçmişi olan bir klinik için:

```bash
ddev php artisan db:seed --class=DemoDatabaseSeeder

# ya da tarih bağımlı fixture'ları bugüne göre yeniden ortalamak için:
ddev php artisan migrate:fresh --seed --seeder=DemoDatabaseSeeder
```

Veri seti, her ekranda hem dolu hem boş bir durum görebilecek şekilde boyutlandırıldı. Tüm demo
hesapların şifresi `password`:

| Hesap | Rol |
| --- | --- |
| `owner@podosen.test` | Klinik sahibi |
| `manager@podosen.test` | Yönetici |
| `doctor@podosen.test` | Doktor |
| `reception@podosen.test` | Resepsiyonist |
| `assistant@podosen.test` | Asistan |
| `superadmin@randevum.test` | Platform superadmin |

### Geliştirme

```bash
ddev pnpm run dev                # Vite dev sunucusu
ddev php artisan horizon         # kuyruk işçileri (hatırlatma, SMS, medya dönüşümü)
```

### Test, lint ve tipler

```bash
ddev php artisan test --parallel   # 2633 test, 24 process'te ~30 saniye
ddev composer lint                 # Pint
ddev pnpm run lint                 # ESLint
ddev pnpm run format               # Prettier
ddev pnpm run types:check          # vue-tsc
```

CI, `main`'e her push'ta aynı kontrolleri koşuyor (`.github/workflows/`).

## Yapılandırma

Satır içi açıklamaların tamamı [`.env.example`](.env.example) içinde. En çok işe yarayanlar:

| Değişken | Ne işe yarar | Varsayılan |
| --- | --- | --- |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | Varsayılan ve yedek dil | `tr` / `en` |
| `DB_CONNECTION` | Geliştirmede de üretimde de PostgreSQL | `pgsql` |
| `SMS_PROVIDER` | `netgsm`, `log` (`storage/logs/sms.log`'a yazar) ya da `null` | `null` |
| `PLATFORM_SMS_MONTHLY_QUOTA` | Klinik başına aylık SMS hakkı, limitte sert blok | `1000` |
| `MEDIA_PRIVATE_DISK_DRIVER` | Geliştirmede `local`, üretimde `s3`. Hiçbir zaman URL ile sunulmaz. | `local` |
| `AWS_ENDPOINT` | S3 uyumlu endpoint. Sağlık verisi Türkiye'de kalmak zorunda, bu yüzden üretim AWS'yi değil Türkiye'deki bir sağlayıcıyı gösterir. | boş |
| `BACKUP_DESTINATION_DISK` | Geliştirmede `local`, üretimde `s3` | `local` |
| `BACKUP_ARCHIVE_PASSWORD` | Üretimde zorunlu. Nesne depolamadaki şifresiz yedek bir hasta verisi sızıntısıdır. | boş |
| `IMAGE_DRIVER` | `imagick`, HEIC için gerekli | `imagick` |

Klinik seviyesindeki ayarlar (saat dilimi, dil, para birimi, çalışma saatleri, SMS tercihleri)
ortam değişkeni değil, kliniğin veritabanı kolonları. Kodun hiçbir yeri tek bir ülke varsaymıyor.

## Geliştirme süreci

Bu kod tabanı yapay zeka destekli bir hatla yazıldı ve hattın repo içinde duran parçalarını
göstermeye değer; çünkü tasarımı herhangi bir framework kararından daha fazla şekillendirdiler.

`.ai/guidelines/` kesişen değişmezleri kısa emir kipi kurallar halinde tutuyor: mimari, kiracılık,
veri modelleme, kimlik, i18n, bileşen yeniden kullanımı, test. Bunlar ajan talimat dosyasına merge
ediliyor ve her turda yükleniyor. `.ai/skills/domain-rules/` alan bazlı iş kurallarını talep üzerine
yüklenecek şekilde, alan başına bir dosyada tutuyor.

İşi yürüten disiplin şuydu: bir kuralı yazmaya ancak onu zorlayan bir şey varsa değer. Modül
sınırları, konteyner döngüsü kuralı ve kiracılık scope'u prozayla değil, CI'ı kıran testlerle
destekleniyor. Mekanik olarak zorlanamayan bir kural ise gerekçesi kenara alınmış tek satır olarak
yazıldı; böylece kurallar her seferinde okunacak kadar kısa kaldı.

## Yapılmayanlar

B2B çekirdek ve Faz 2 klinik genişlemesi bitti. Kuyruğun ortasında kalanlar:

**Mevzuat dalgası (sıraya alındı, başlanmadı).** Özel nitelikli veri için alan bazlı şifreleme,
HMAC arama hash'iyle hasta kimlik numarası (TCKN, YKN, pasaport), okumaları da kapsayan erişim
audit logu, e-posta doğrulama artı SMS tabanlı 2FA, rıza geri çekme ve hasta bazlı rıza, medya
yaşam döngüsü ve saklama otomasyonu, hem klinik ayrılığı hem de hastanın yasal erişim hakkı için
veri export'u. Sıra bilinçli: şifreleme önce geliyor ki audit logu ve export düz metnin üstüne
sonradan eklenmek yerine şifreli alanların üzerine kurulsun.

**Hiç başlanmayanlar.** B2C tarafı (hasta mobil uygulaması ve public pazaryeri, kabaca 15 özellik),
platform faturalandırma ve abonelik, superadmin araçları ve e-Nabız entegrasyonunun kendisi.

**Özellik değil.** Üretim ortamı. Uygulama DDEV ve CI dışında hiç çalışmadı.

## Lisans

Randevum **GNU Affero General Public License v3.0** ile yayınlanıyor, bkz. [`LICENSE`](LICENSE).

Serbestçe kullanabilir, değiştirebilir ve kendi sunucunda barındırabilirsin. Değiştirilmiş bir
sürümü ağ servisi olarak çalıştırıyorsan, değişikliklerini AGPL-3.0 ile yayınlaman gerekiyor.
