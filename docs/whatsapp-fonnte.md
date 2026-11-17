# WhatsApp lewat Fonnte: panduan setup

Kanal WhatsApp Notification Engine (Roadmap 5D) memakai antarmuka `WhatsAppSender`. Bawaan: **mati** (`WHATSAPP_ENABLED=false`) dengan driver `log`. Driver `fonnte` (`App\Support\WhatsApp\FonnteWhatsAppSender`) mengirim lewat WhatsApp API gateway [Fonnte](https://fonnte.com) ([dokumentasi](https://docs.fonnte.com)). Selama `.env` tidak diubah, aplikasi tetap memakai driver `log`.

## 1. Akun & device Fonnte

1. Daftar akun di https://fonnte.com.
2. **Tambah device** dan isi nomor WhatsApp yang akan dipakai mengirim notifikasi.
3. Hubungkan device: buka WhatsApp di HP nomor itu → **Perangkat tertaut** (Linked devices) → **Tautkan perangkat** → scan QR yang tampil di dashboard Fonnte.

**Pakai nomor khusus notifikasi, bukan nomor CS.** Webhook Fonnte mewajibkan *Auto Read* menyala, sehingga **semua chat masuk ke nomor itu otomatis ditandai sudah dibaca**. Kalau dipakai di nomor CS, chat pelanggan bisa terlewat.

**Paket**: paket Free menambahkan branding Fonnte ke setiap pesan dan tidak mendukung lampiran, jadi pakai hanya untuk development. Untuk produksi disarankan paket **Regular** atau yang lebih tinggi. Aplikasi hanya mengirim teks.

## 2. Isi `.env` backend

```dotenv
# token device: dashboard Fonnte → Device → Token
FONNTE_TOKEN=xxxxxxxxxxxxxxxx
FONNTE_BASE_URL=https://api.fonnte.com
FONNTE_TIMEOUT=15
# secret acak untuk URL webhook
FONNTE_WEBHOOK_SECRET=
```

Buat `FONNTE_WEBHOOK_SECRET` dengan:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Token dan secret **jangan di-commit** ke repo.

## 3. Webhook (dashboard Fonnte → Device → Edit)

Fonnte tidak menandatangani webhook, jadi URL-nya diamankan dengan secret di path. Secret yang salah atau kosong dibalas 404. Endpoint di-rate-limit 120 request/menit per IP.

| Kolom di Fonnte | URL |
| --- | --- |
| Webhook status pesan (message status) | `https://<domain-backend>/api/webhooks/fonnte/<FONNTE_WEBHOOK_SECRET>/status` |
| Webhook status koneksi device (connect/disconnect) | `https://<domain-backend>/api/webhooks/fonnte/<FONNTE_WEBHOOK_SECRET>/device` |

Nyalakan **Auto Read = ON**. Ini wajib, karena tanpa Auto Read Fonnte tidak mengirim webhook.

Yang dilakukan aplikasi:

- **Status pesan**: payload mentah selalu dicatat ke log `[fonnte:webhook-status]`. Nilai `state`/`status` dipetakan ke status pengiriman lewat `FonnteWebhookController::STATE_MAP`/`STATUS_MAP` (sementara: `state` `delivered`/`read`; `status` `failed`/`invalid` → gagal), lalu diteruskan ke `NotificationDispatcher::receipt('fonnte', id, …)`. Status hanya bisa maju. Nilai lain diabaikan dan tetap dibalas 200.
  > **Arti nilai status Fonnte tidak didokumentasikan.** Setelah webhook aktif, kirim beberapa pesan uji, baca payload asli di log, lalu sesuaikan kedua konstanta tersebut.
- **Status device**: saat `disconnect`, staf dengan izin `notifications.manage` menerima notifikasi di lonceng panel admin (template `whatsapp.device_disconnected`, maksimal 1× per jam per device). Saat `connect`, kejadian cukup dicatat di log.

Server harus bisa diakses publik lewat HTTPS agar webhook bisa diterima.

## 4. Aktivasi

```dotenv
WHATSAPP_ENABLED=true
WHATSAPP_DRIVER=fonnte
```

Lalu `php artisan config:cache` jika konfigurasi di-cache. Kanal hanya aktif kalau `WHATSAPP_ENABLED=true` **dan** `FONNTE_TOKEN` terisi.

Uji sebelum atau sesudah aktivasi:

- **Dashboard admin → widget "Status WhatsApp (Fonnte)"** (hanya tampil saat `WHATSAPP_DRIVER=fonnte`, izin `notifications.manage`): status device, nomor, paket, sisa kuota, dan kedaluwarsa. Data di-cache 5 menit.
- **Notifikasi → Pengiriman notifikasi → "Kirim WA uji"**: kirim satu pesan ke nomor yang diisi admin. Aksi ini tercatat di jejak audit (`whatsapp.test_sent` / `whatsapp.test_failed`). Uji kirim tetap bisa dipakai saat `WHATSAPP_ENABLED` masih `false`, asalkan driver `fonnte` dan token sudah terisi.

## 5. Perilaku pengiriman

- Request `POST /send` bersifat form-encoded dengan header `Authorization: <token>` (tanpa "Bearer"). Isinya: `target` (nomor 62…), `message` (maksimal 60.000 karakter), `countryCode='62'` (string), dan `connectOnly='true'`, sehingga kalau device terputus request langsung gagal dan tidak tertahan di antrean Fonnte.
- **Gagal sementara** (timeout, HTTP 5xx, `status:false` dengan alasan lain, atau respons tanpa ID) di-retry oleh Notification Engine dengan jeda 1/5/30/120 menit, maksimal 4 percobaan.
- **Gagal permanen** (`token invalid`, `devices must belong to an account`, `target invalid`, `input invalid`, `insufficient quota`) langsung ditandai **Gagal** tanpa retry. Setelah masalahnya diperbaiki, kirim ulang manual dari menu Pengiriman notifikasi.
- Token tidak pernah ditulis ke log maupun ke pesan galat.

## 6. Mengganti penyedia

Semua kode Fonnte ada di `FonnteWhatsAppSender` dan `FonnteWebhookController`. Untuk penyedia lain, buat implementasi `WhatsAppSender` baru, tambahkan ke `match` di `AppServiceProvider`, dan buat controller webhook yang memanggil `NotificationDispatcher::receipt()`. Notification Engine tidak perlu diubah.
