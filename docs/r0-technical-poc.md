# Identity V2 R0 teknik PoC

27 Kasım 2026, bulut ortamı. Kapsam [ADR-0015](https://github.com/ulaxys-platform/ulaxys-docs/blob/main/architecture/adr/0015-identity-v2-r0.md) ve [Identity PR #1](https://github.com/ulaxys-platform/identity-service/pull/1). Başlangıç commit’i `e93e3c4bc1931ebf5cbd0c7c31b84dfa093b67c6`.

## Tekrar çalıştırma

Mevcut bulut ortamındaki izole Docker kaynaklarını kullanın. PHP 8.5.11, Composer 2.10.3 ve PostgreSQL 17.11 kullanıldı. Keycloak imajı [26.8.2](https://github.com/keycloak/keycloak/releases/tag/26.8.2) olup `quay.io/keycloak/keycloak:26.8.2@sha256:7e66a96e4739239070aeaf72e4c5decf0f7f682cfc725b9f7ea2b336eaba38b3` ile sabitlendi.

```sh
cd /workspace/identity-service
bash /workspace/identity-cloud/install.sh
bash /workspace/identity-cloud/start.sh
bash /workspace/identity-cloud/keycloak.sh
bash scripts/identity-r0/run.sh
bash scripts/identity-r0/outage.sh
```

`run.sh` her çalıştırmada geçici bir realm ve kimlik bilgileri üretir. Yalnızca fixture kurulumu ve temizliği için yerel bootstrap yöneticisini kullanır. Tarayıcı oturumu Authorization Code + PKCE S256 kullanır. Provisioner, makine istemcisi ve anahtar görevlisi ayrıdır. Keycloak 26.8.2 Admin API için provisioner ve anahtar görevlisi `kc-admin-api` kapsamını ister. Provisioner realm’de kullanıcı işlemlerini yapabilir, istemci ve anahtar yönetimi reddedilir. Introspection istemcisi token audience listesinde açıkça yer alır. Scope ve audience denetimleri etkin kalır.

PoC eşlemesi yalnızca yerel Identity veritabanındaki fixture tablosuna yazılır. Keycloak veritabanı erişimi Keycloak’a aittir. Tabloda `(issuer, subject)` birincil anahtar, ULID benzersizdir. Test, sağlayıcı kaydı oluşturulduktan sonra yerel işlemi geri alır, sonraki denemede aynı sağlayıcı kaydını bulur ve yeni DB bağlantısından sonra commit edilmiş ULID’nin değişmediğini doğrular. Fixture realm’inde önceden ayrılmış tek kullanıcı adı kullanılır. Üretim için korelasyon anahtarı, yaşayacak migration ve bağımsız hata uzlaştırma akışı sonraki uygulama kapsamındadır. PoC kodu Laravel’in normal auth rotalarına bağlı değildir.

`outage.sh` yalnızca digest’i doğrulanan `identity-cloud-keycloak` konteynerini durdurup yeniden başlatır. Bir bağlantı hatası ve sonrasında doğru issuer’dan başarılı discovery bekler. Testler özel Docker ağındaki yerel HTTP üzerinden çalışır. Üretim TLS, HA veya yedekten geri yükleme bu çalıştırmada doğrulanmadı. Normal tamamlanmada geçici realm, tablo ve credential dosyaları temizlenir. Yerel servisleri durdurmak için yalnızca PoC’ye ait `identity-cloud-app`, `identity-cloud-keycloak`, `identity-cloud-db` ve `identity-cloud-keycloak-db` konteynerlerini kullanın.

## Çalıştırma kanıtı

| Komut | Çıkış | Sonuç |
|---|---:|---|
| `bash /workspace/identity-cloud/install.sh` | 0 | Aynı bulut örneğinde kurulum tekrarlandı. |
| `bash /workspace/identity-cloud/start.sh && bash /workspace/identity-cloud/keycloak.sh` | 0 | Laravel ve PostgreSQL sağlık uçları HTTP 200, migration beklemiyor, Keycloak discovery HTTP 200. |
| `bash scripts/identity-r0/run.sh` | 0 | Discovery/JWKS, gerçek Authorization Code + PKCE, state/nonce, hatalı redirect ve verifier, eksik verifier, kod tekrarının reddi, imzalı ID ve erişim tokenı, issuer/audience/expiry, Client Credentials, kapsamlı Admin API erişim reddi, provizyon, uzlaştırma, ULID, logout, introspection, refresh ve revocation, rotasyon ve devre dışı kullanıcı. |
| `bash scripts/identity-r0/outage.sh` | 0 | Gerçek Keycloak kesintisinde bağlantı hatası, konteyner yeniden başlatılınca discovery toparlandı. |
| `docker exec -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/app identity-cloud-app composer openapi` | 0 | 24 bağlı işlem, benzersiz operation ID’leri ve yerel referanslar doğrulandı. |
| `composer audit --locked` | 100 | Bulut PHP konteynerinde Packagist CONNECT 403, cURL hata 56. |
| `sh tools.sh --verbose test foundation` | 2 | Başlangıçta 167 test, 8 hata, 19 başarısız, 4 atlanan. Bağlama sonrası 167 test, 1 hata, 5 başarısız, 4 atlanan. |
| `sh tools.sh --verbose test database` | 2 → 1 | Başlangıçta 57 test, 5 hata, 21 başarısız. Bağlama sonrası 57 test, 10 başarısız. |

Packagist ve GitHub API bağlantıları doğrudan HTTPS kontrollerinde de aynı proxy sonucunu döndürdü. CONNECT reddinden dolayı upstream HTTP kodu oluşturulmadı.

```text
Packagist CONNECT 403, HTTP 000
GitHub CONNECT 403, HTTP 000
curl error 56 while downloading https://packagist.org/api/security-advisories/: CONNECT tunnel failed, response 403
```

Yeni görev mevcut dosyaları ve çalışan konteynerleri gördü. Taslaktaki ağ izinleri çalışan örneğin etkin özel izin listesinde görünmüyordu. Yayımlanmış yeni bir sürümden bağımsız bir makineye geri yükleme doğrulanmadı. Bu çalışma hosted CI sonucu içermez.

## Test başarısızlıklarının sınıflandırılması

Numaralar ilgili PHPUnit çıktısındaki hata veya başarısızlık numaralarıdır. Değişiklikten önceki ve sonraki tam günlükler, commit dışında `/workspace/identity-cloud/evidence` altında saklandı. Laravel container’ının mevcut `identity.issuer` ve `identity.token.clock_skew_seconds` ayarlarını `JwtCodec` yapıcısına iletmesi sağlandı. Codec davranışı genişletilmedi.

| Başlangıç kapsamı | Gözlenen ortak neden veya belirti |
|---|---|
| Foundation hatalar 1–8 ve başarısızlıklar 6–19 | `JwtCodec::$issuer` çözümlenemedi. Bağlama sonrası 21 kayıt ortadan kalktı. Kalan generator vakası `/api/v1/actor/context` için “Unsupported resolved middleware” ile durdu. |
| Foundation başarısızlıklar 1–3 | Mevcut codec için expiry, not-before ve issuer/audience ret beklentileri karşılanmadı. PoC imzalı tokenlarda expiry, issuer ve audience denetimini doğruladı. Mevcut regression testleri korunuyor. |
| Foundation başarısızlıklar 4–5 | Türkçe lokalizasyon anahtarları uyuşmuyor ve kısa parola için beklenen politika anahtarı üretilmiyor. |
| Database hatalar 1–5 ve başarısızlıklar 1–7, 9–21 | İstek veya rota çözümlemesi aynı `JwtCodec::$issuer` hatasını içeriyordu. Bağlama sonrası kalan sonuçlar aşağıda. |
| Database başarısızlık 8 | Recovery yanıtları eşit olmadı. Bağlama sonrasında 200/429 ayrımı tekrarlandı. |

| Database, bağlama sonrası | Gözlenen ortak neden veya belirti |
|---|---|
| 1, 6, 7, 9 | Logout, email verification, owner-scope oturum iptali ve introspection akışlarında beklenen yanıt yerine HTTP 400. Altta yatan nedenlerin aynı olduğu doğrulanmadı. |
| 2, 3, 4 | Parola değişimi, recovery yanıt eşitliği ve reset akışında rate limit HTTP 429 döndürdü. |
| 5, 8 | Controller, bulunmayan `AuthenticationService::setup()` metodunu çağırdı ve HTTP 500 döndü. |
| 10 | Eski anahtarın overlap sonrasında reddedilmesi beklenirken HTTP 200 döndü. PoC yeni ve önceki anahtarın kontrollü overlap davranışını doğruladı. Anahtar emekliliği için mevcut regression beklentisi açık. |

PoC sonuçları teknik fizibiliteyi gösterir. R1 bağımlılık kapısı için gerçek audit çıktısı gerekiyor. Template generator hatası, bağımsız ortam geri yüklemesi, sonraki R1–R9 aşamaları ve IDV2-001–016 nihai disposition’ları açıktır. Mevcut security ve template testleri korunmuştur.
