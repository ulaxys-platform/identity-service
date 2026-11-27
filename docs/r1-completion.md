# Identity V2 R1 doğrulaması

27 Kasım 2026. Başlangıç `ccdba328f1026747bf7254d592aa82d9ae584048`, PR #1. [R0 kanıtı](r0-technical-poc.md) ve ADR-0015 geçerli. R1A lokal Composer audit kapısı **PASS**; R1, strict template, referans parity ve açık güvenlik regresyonları nedeniyle **BLOCKED**. R2–R9 geçişi bekliyor.

## Kurulum ve bağımlılık

Kurulum için Docker, Bash, Python 3, OpenSSL ve sistem CA paketi gerekiyor. PHP ve Composer geliştirme imajının digest’leri [Dockerfile](../Dockerfile) içinde, PostgreSQL 17 ve Keycloak 26.8.2 digest’leri [ortam betiklerinde](../scripts/identity-r0/environment) kayıtlı. Kurulum kilitli paket arşivlerini GitHub’ın izinli HTTPS arşiv servisinden alır. Composer audit kendi Packagist advisory servisini kullanır.

```sh
bash scripts/identity-r0/environment/install.sh
bash scripts/identity-r0/environment/start-app.sh
bash scripts/identity-r0/environment/start-keycloak.sh
bash scripts/identity-r0/run.sh
```

Aynı Docker sunucusunda ayrı bir yerel kurulum için dört komutta da `IDENTITY_R0_STACK=identity-r1-fresh` kullanın. Varsayılan durum dizini `/workspace/$IDENTITY_R0_STACK`, alternatif dizin ayarı `IDENTITY_R0_STATE`. Ayrı stack farklı Docker ağı, konteynerler ve vendor volume kullanır. Çalışma zamanı değerleri ve yerel anahtarlar durum dizininde üretilir. Yeni Docker ağı ve iki yeni PostgreSQL veritabanı üzerinde migration, Keycloak discovery ve R0 PoC doğrulandı. Yayımlanmış bulut snapshot’ının ayrı makinede geri yüklenmesi bekliyor.

`composer validate --strict` ve `composer check-platform-reqs` çıkış 0. Bu bulut koşusundaki Composer lock içeriği korundu; lokal adayın kesin blob kimliği aşağıda kayıtlıdır. `composer audit --locked` çıkış 100, cURL 56. Packagist bağlantısı proxy CONNECT aşamasında 403 döndü, upstream HTTP kodu yok. Taslakta `packagist.org` ve `api.github.com` var. Çalışan örneğin etkin özel alan adı listesi boş. Cloud Environment ayarlarında taslağı inceleyip uygulayın ve HTTPS bağlantısının etkinleştiğini doğrulayın. Ürün sahibi, ağ erişimi olan ve PHP 8.5 ile Composer 2 içeren bir makinede şu komutlarla aynı kilit dosyasını denetleyebilir.

```sh
git fetch origin refs/heads/feature/identity-v2-delivery
git switch --detach FETCH_HEAD
git rev-parse HEAD
git hash-object composer.lock
composer validate --strict
composer install --no-interaction --prefer-dist
composer check-platform-reqs
composer audit --locked
```

## Strict template ve referanslar

`php artisan ulaxys:generate-integration --all --strict --dry-run` ve `php artisan ulaxys:generate-deploy --strict --dry-run` çıkış 1, `Unsupported resolved middleware on /api/v1/actor/context`.

İncelenen kaynaklar, resmî PHP template `5abad78706c94bf2802224ebbe39b1ccbd87f993`, Sourcing `421d6b327125f69748946a233f3c42fbf1025672`, Order `50a056650e45c57257bbe58f9d89aa9f12108618`, HS Code `d39802ad18b3d3e17b8f726796aa569609cc4f3e` ve Inventory `ca68f3959564f82eb397d86573d87f71a0b72eaf`. Sourcing ve Order, bearer ve servis imzasıyla Identity’nin authenticated actor-context sözleşmesini çağırıyor. Resmî `RouteDiscovery`, tam middleware zincirini metadata çözülmeden önce kapalı bir listeyle denetliyor. Metadata v1 yalnızca operation, expose ve permission/exemption sunuyor. Auth ve throttle middleware’ini tanımlayan bir alanı yok. `expose: false` bu denetimi değiştirmiyor. Ayrıca native MainGate, açık ve permission’dan muaf korumalı işlemler için temsil sunmuyor. Desteklenen çözüm için şablonun aktör ve rate-limit semantiğini tanıyan bir sözleşme ve bu sözleşmeyle uyumlu Gateway hedefi gerekiyor. Actor doğrulaması, throttling ve strict kapılar korunuyor.

Eksik [HS Code kataloğu](../tests/Fixtures/Integration/hs-code/catalog.json) belirtilen gerçek commit’ten `php tests/Support/Integration/export-catalog.php /references/hs-code-service` ile üretildi. Foundation koşusunda bu katalog canlı referansla karşılaştırıldı ve Inventory parity testleri çalıştı. Namespace ve import sırası normalizasyonu, karşılaştırmayı gerçek kaynak değişikliklerine odaklar. HS Code engine parity açık. İncelenen referans, resmî şablondaki deploy renderer ve komut değişikliklerinden önceki engine’i taşıyor.

## Önceki başarısızlıkların disposition’ı

| Vaka | Sınıf ve işlem |
|---|---|
| Foundation integration generator | Template. Aktör middleware sözleşmesi yukarıdaki nedenle açık. |
| Foundation JWT expiry, not-before, issuer/audience | Fixture. Dizi override’ları artık geçersiz claim’leri gerçekten üretiyor. Sağlayıcı geçişinde zaman, issuer, audience ve imza denetiminin regression kapsamı korunacak. |
| Foundation localization | Fixture. Test çevrilmiş metinler yerine anahtarları karşılaştırıyor. Ayrı test tüm çevirilerin boş olmamasını kontrol ediyor. |
| Foundation kısa parola | Fixture. Örnek 12 karakterlik asgari sınırın altında. Sağlayıcı geçişinde realm parola politikası karşılaştırılacak. |
| Database logout | JSON fixture artık nesne gövdesi gönderiyor. Mevcut logout çağrısında hatalı AuthSession tür referansı düzeltildi. Sağlayıcı geçişinde introspection logout sonrasında pasif olmalı. |
| Database parola değiştirme, recovery enumeration, reset ve email verification | Fixture. Test saati normal rate-limit penceresini ilerletir, sınırlar etkin kalır. Email başlangıcı nesne gövdesi gönderir. Sağlayıcı geçişinde genel recovery mesajı, challenge tek kullanımı ve oturumların iptali ayrıca doğrulanacak. |
| Database owner-scoped iptal | Fixture. DELETE isteği nesne gövdesi gönderiyor. Başkasının oturumuna 404 ve sahibin listesinde yalnızca kendi oturum kimliği bekleniyor. |
| Database bootstrap ve admin erişimi | Yerini başka akışa bırakacak motor. `AuthenticationService::setup()` yok. Sağlayıcı geçişinde tek kullanımlık onboarding, idempotent bootstrap ve admin grant yokluğunda erişim reddi ayrı regresyonlar olarak korunacak. |
| Database introspection | Fixture. 16 karakter altı girdi için doğrulama reddi ve yeterli uzunluktaki biçimsiz token için pasif sonucu ayrı ayrı sınanıyor. |
| Database eski anahtar | Fixture. Test, önce overlap davranışını sınar, sonra PostgreSQL zaman damgası çözünürlüğünün ötesine ilerleyerek sıfır overlap politikasını sınar. Sağlayıcı geçişinde emekliye ayrılan anahtarın aktif JWKS dışında kalması ayrıca sınanacak. |
| Advisory servisi ve snapshot | Ortam. Bulut audit CONNECT 403 olarak kalır; R1A lokal audit PASS. Bağımsız snapshot geri yükleme kanıtı bekleniyor. |

Tam yürütme günlükleri bulut görevinde `/workspace/identity-cloud/evidence/r1-completion` altında.

| Son koşu | Çıkış | Kanıt |
|---|---:|---|
| Foundation, HS Code ve Inventory referansları bağlı | 2 | 167 test, 4.291 assertion, 1 generator hatası, 1 HS Code engine parity başarısızlığı, 0 atlanan. |
| `sh tools.sh --verbose test database` | 1 | İzole PostgreSQL’de 57 test, 224 assertion, 2 başarısız, 0 atlanan. İkisi de eksik `AuthenticationService::setup()`. |
| `php vendor/bin/pint --test` | 0 | 236 PHP dosyası. |
| Ayrı stack kurulumu ve R0 PoC | 0 | Yeni vendor volume’a 109 paket kuruldu ve PoC tekrar geçti. |

## Product Owner lokal kabul kanıtı — 27 Kasım 2026

Ortam: Windows, Docker Desktop; Identity repository. Aşağıdaki sonuçlar Product Owner tarafından bildirilen terminal çıktılarıdır; bu dokümantasyon güncellemesinde yeniden yürütülmemiştir.

- Test edilen aday: `fd9aebac2c019403f0fbdacb16fcf2f91075c865`.
- `git hash-object composer.lock`: `a290239f134b03c81d25b1f124198b22fc1aea17`.
- Kurulum ve testler sonrası `git rev-parse HEAD` ve lock blob hash eşleşti; `git status --short` boş.
- `.\tools install`: SUCCESS (Product Owner bildirimi).
- `composer audit --locked`: `No security vulnerability advisories found.` **R1A PASS** (lokal, bu lock dosyası ve denetim tarihi kapsamında).
- Bulut audit sonucu ayrı tutulur: CONNECT 403, cURL 56, çıkış 100; bulut audit PASS değildir.

### Lokal PostgreSQL regresyonu

`.\tools test database --verbose`: PHP 8.5.11, PHPUnit 12.5.40, izole PostgreSQL; preflight PASS, Docker çıkış 1. 57 test, 55 başarılı, 224 assertion, 2 başarısız, 0 atlanan; 61.387 saniye. Bulut R1 sonuçlarıyla aynı iki başarısızlık:

| Test | Sonuç |
|---|---|
| `IdentityCredentialLifecycleTest::test_bootstrap_is_idempotent_seeds_no_password_and_setup_activates_the_account` | HTTP 201 yerine 500 |
| `IdentitySecurityRegressionTest::test_user_administration_requires_the_bootstrap_grant` | HTTP 201 yerine 500 |

Ortak neden: `AuthenticationController.php:88`, tanımsız `AuthenticationService::setup()`. Bu bir gerçek uygulama regresyonudur; PostgreSQL/CLI hatası olarak sınıflandırılmaz. Database acceptance **FAIL**. Eski authentication motoruna yeni credential authority eklenmeyecek; R2 ve ilgili lifecycle/authorization aşamalarında idempotent bootstrap, tek kullanımlık aktivasyon, parola otoritesinin Keycloak'ta kalması, provisioning/reconciliation ve yetkisiz yönetim reddi için replacement regression kanıtı zorunludur. Mevcut başarısızlıklar gizlenmez, skip edilmez ve giderilmiş sayılmaz.

R1B: [resmî PHP Template P4.5 PR #22](https://github.com/ulaxys-platform/ulaxys-be-php-template-service/pull/22) ayrı çalışma kapsamında; kabulü bu kayıtla verilmez. R1C kontrollü upgrade, P4.5 kabulü ve kaynak pin doğrulaması sonrasında yapılacaktır. R2–R9 beklemede; Identity PR #1 merge BLOCKED. Bu güncelleme yalnızca dokümantasyon kanıtıdır; uygulama, test, lockfile veya template değişikliği değildir.
