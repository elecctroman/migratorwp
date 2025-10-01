# MigratorWP

MigratorWP, büyük ölçekli WordPress sitelerini profesyonel şekilde taşımanız ve kopyalamanız için tasarlanmış kapsamlı bir dışa/İçe aktarma eklentisidir. Limitsiz boyutta siteleri tek paket halinde alıp farklı ortamlara kurmanızı sağlar.

## Özellikler

- ✅ **Tek paketli dışa aktarma** – Manifest, veritabanı ve tüm WordPress dosyalarını yüksek performanslı ZIP arşivine dönüştürür.
- ✅ **Arka plan görev kuyruğu** – Dışa/içe aktarma işlemleri otomatik olarak kuyruğa alınır, zaman aşımı yaşamadan tamamlanır.
- ✅ **Sınırsız boyut desteği** – Bellek artırma, zaman aşımı engelleme, parçalı SQL dökümleri ve akış tabanlı ZIP işlemleri ile devasa sitelerle uyumlu.
- ✅ **Otomatik içe aktarma** – Yüklediğiniz paket arşivini açar, veritabanını çalıştırır ve dosyaları doğru konumlara kopyalar.
- ✅ **WP-CLI desteği** – Sunucu tarafında terminal üzerinden dışa/içe aktarma komutları.
- ✅ **Günlük kayıtları** – Admin panelinde son işlemleri görüntüleyin.
- ✅ **Güvenli yapı** – Doğrulanmış manifest, otomatik tablo ön eki eşleştirmesi ve `wp-config.php` dosyasını koruma.

## Kurulum

1. Depoyu indirip `migratorwp` klasörünü `/wp-content/plugins/` dizinine kopyalayın.
2. WordPress yönetim panelinden **Eklentiler → Yüklü Eklentiler** bölümüne gidip *MigratorWP* eklentisini etkinleştirin.

## Kullanım

### Yönetim Paneli

1. **MigratorWP → Dışa Aktar/İçe Aktar** sayfasını açın.
2. Dışa aktarmak için *Dışa Aktarmayı Başlat* düğmesine tıklayın. İşlem arka planda başlar; tamamlandığında aynı sayfadaki **İş Kuyruğu** tablosunda indirme bağlantısı görünür.
3. Yeni sitede aynı sayfadan ZIP dosyasını yükleyip *İçe Aktarmayı Başlat* ile içe aktarımı kuyruğa alın. Durumu **İş Kuyruğu** tablosundan takip edin.

### WP-CLI

```bash
wp migratorwp export
wp migratorwp export --destination=/tmp/sitem.zip
wp migratorwp import ./migratorwp-20231010-101500.zip
```

## Notlar

- İçe aktarma işlemi `wp-config.php` dosyanızı korur; yeni ortam bilgilerinizi güncellemeniz gerekmez.
- Büyük siteler için yeterli disk alanı sağladığınızdan emin olun.
- Eklenti, kendi loglarını ve geçici dosyalarını `wp-content/uploads/migratorwp` dizininde saklar.

## Lisans

GPL-2.0 veya üzeri.
