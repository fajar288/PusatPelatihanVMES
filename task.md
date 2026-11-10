# PPTI VMES ITMS — Master Task List

> Sumber: (1) reverse engineering `https://pusatpelatihan.id/` (WordPress, tema custom `ppti-vmes`), (2) PRD v1.0 "PPTI VMES Integrated Training Management System" (3 Nov 2026).
> Target stack: **Next.js (App Router, TypeScript) + Laravel (REST API) + PostgreSQL + Tailwind**.
> Dokumen pendukung: [docs/PRD.md](docs/PRD.md) (teks PRD), [docs/reference/](docs/reference/) (CSS/JS/HTML asli), [docs/screenshots/](docs/screenshots/) (tangkapan layar tiap section).

---

## 0. ATURAN WAJIB (baca sebelum mengerjakan task apa pun)

1. **UI/UX publik WAJIB "plek ketiplek" dengan pusatpelatihan.id.** Jangan mendesain ulang. Sumber kebenaran visual adalah:
   - `docs/reference/theme.style.css` → seluruh nilai warna, radius, shadow, spacing, font-size, breakpoint. **Salin nilainya apa adanya.**
   - `docs/reference/home.body.html` → struktur DOM beranda (urutan section, class, teks, ikon).
   - `docs/reference/theme.main.js` → perilaku interaktif (slider, counter, paginasi, lightbox, header shrink, filter katalog).
   - `docs/reference/icons-sprite.svg` → 39 ikon SVG (stroke 2, lucide-style). Pakai ikon ini, bukan library lain.
   - `docs/screenshots/*.jpg` → acuan visual akhir, bandingkan side-by-side.
2. Di frontend, CSS tema asli di-port 1:1 ke `frontend/src/styles/theme.css` dengan **nama class yang sama** (`.hd`, `.hero2`, `.cc`, `.jr`, dst). Komponen React hanya membungkus markup asli. Tailwind dipakai untuk halaman **baru** (portal member, trainer, admin) yang tidak ada di website lama, tetap memakai token warna yang sama.
3. Font: **Plus Jakarta Sans** 400/500/700/800 (Google Fonts, via `next/font/google`). Tidak ada font lain.
4. Semua teks copy (judul, deskripsi, CTA) dipakai apa adanya dari website lama kecuali yang jelas placeholder WordPress ("Hello world!", "Contoh konten...").
5. Gambar hero/galeri di website lama di-hotlink dari situs pihak ketiga (bukan milik PPTI). Untuk sementara dipakai URL yang sama agar tampilan identik, **wajib diganti** dengan foto PPTI sendiri sebelum go-live (lihat §1.9).
6. Data dummy (kursus, jadwal, testimoni, partner) disimpan di `frontend/src/data/*.ts` dan nanti diganti dengan fetch ke API Laravel tanpa mengubah komponen.

---

## 1. HASIL REVERSE ENGINEERING pusatpelatihan.id

### 1.1 Fakta teknis website lama

| Item | Nilai |
|---|---|
| Platform | WordPress 7.1.4, tema custom `ppti-vmes` v1.0.0 (konversi dari template Blogger) |
| CSS | Satu file `style.css` (43 KB), tanpa framework. Variabel di `:root`. |
| JS | Satu file `assets/js/main.js` (10 KB), vanilla, tanpa dependency |
| Font | Plus Jakarta Sans 400;500;700;800 dari Google Fonts |
| Ikon | SVG sprite inline `<symbol id="i-*">` (39 ikon), dipakai lewat `<svg class="ic"><use href="#i-xxx"/></svg>` |
| Container | `.wrap` max-width **1320px**, padding 0 20px |
| Konten | Hampir seluruh konten masih **dummy bawaan tema** (kursus, jadwal, testimoni, galeri). Hanya 1 post asli ("Hello world!"). Link "Lihat Detail" kursus semuanya `href="#"` (halaman detail kursus belum ada). |
| Nomor WA | `wa.me/6285121251285`, teks prefill `Halo, saya ingin info training di PPTI - VMES` |
| Alamat | Batam Centre, Kawasan Industri Tunas Bizpark Blok D No 15 Lantai 2, Belian, Kec. Batam Kota, Kota Batam, Kepulauan Riau 29464 |
| Video profil | YouTube embed `IClaATbg--A` |

### 1.2 Design tokens (WAJIB sama persis)

```css
:root{
  --p:#004B23;     /* primary green */
  --pd:#0B3C26;    /* primary dark */
  --l:#6BB218;     /* lime accent */
  --o:#FA5000;     /* orange CTA */
  --ink:#1d2523;   /* text */
  --mut:#4A4D4E;   /* muted text */
  --bg:#f2f7ec;    /* light green bg */
  --ln:#dde6d2;    /* border line */
  --w:#fff;
}
```

Turunan yang sering dipakai (hard-coded di CSS asli):

| Kegunaan | Nilai |
|---|---|
| Gradien hero bg | `linear-gradient(180deg,#f2f7ec,#fff 78%)` + radial glow lime 20% di 18%/12% dan green 12% di 82%/10% |
| Gradien teks `.gt` | `linear-gradient(90deg,var(--p),var(--l))` + background-clip:text |
| Gradien tombol oranye | `linear-gradient(90deg,#FA5000,#ff7a2e)`, shadow `0 8px 20px rgba(250,80,0,.28)` |
| Gradien ikon box | `linear-gradient(135deg,var(--p),var(--l))` |
| Gradien dark panel (CTA, noimg, kh) | `linear-gradient(135deg,#0B3C26,#004B23 55-70%,#1d6b2f)` |
| Footer | `linear-gradient(180deg,#0B3C26,#06251a)`, border-top 6px lime |
| Shadow card default | `0 2px 10px rgba(0,75,35,.06-.07)` |
| Shadow card hover | `0 14px 30px rgba(0,75,35,.16)` / `0 14px 32px rgba(0,75,35,.18)` |
| Shadow panel besar | `0 12px 40px rgba(0,75,35,.14)` |
| Status OK | bg `#eef7e1` text `#2f6a0a` border `#cfe6a8` |
| Status Warning | bg `#fff7e0` text `#8a6100` border `#f1dc9b` |
| Status No | bg `#fff3e8` text `#b34200` border `#ffd2ab` |
| Bintang | `#f5a300` |
| WhatsApp icon | `#25a244` |
| Hover oranye | `#d94400` |
| Radius | pill `99px`; kartu kursus `28px`; kartu kategori `18px`; panel jadwal `24px`; testimoni featured `36px`; CTA `32px`; hero image `48px`; stats bar `24px`; card artikel `14px`; tombol hero `16px` |

### 1.3 Tipografi

| Elemen | Spesifikasi |
|---|---|
| Body | 16px / 1.65, color `--ink` |
| H1 hero | `clamp(32px,5vw,52px)`, line-height 1.08, letter-spacing -.045em, weight 800 |
| H2 section (`.tt`) | `clamp(26px,3.8vw,40px)`, lh 1.12, ls -.035em, 800 |
| H2 `.sh h2` (Kursus/Artikel) | 28px, ls -.02em |
| H2 tentang (`.tkt h2`) | `clamp(24px,3.2vw,32px)`, `em` berwarna `--p` |
| Lead hero | 18px, weight 600, `--mut`, max-width 540px |
| Eyebrow `.eb` | 14px 800, pill bg `rgba(0,75,35,.08)`, dot lime 9px di depan |
| Judul kartu kursus | 22px 800, lh 1.2, ls -.03em, clamp 2 baris (height 53px) |
| Brand kartu `.br` | 12px 800 uppercase, ls .04em |
| Stat angka `.sg b` | 28px 800 `--p` |
| Footer | 14px, teks `rgba(255,255,255,.78)`, heading 14px putih |

### 1.4 Peta halaman (sitemap website lama)

| URL | Body class | Template / komponen |
|---|---|---|
| `/` | `home is-home` | Beranda: 11 section (lihat §1.5) |
| `/category/kursus/` | `g-kursus` | Hero katalog `.cath` + filter bar `.catb` + grid `.list` kartu `.cc` (3 kolom) |
| `/category/jadwal/` | `g-jadwal` | `.cath` + filter (tanpa mode) + list baris `.card` (border-left lime, tanggal pill, tombol oranye "Lihat detail") |
| `/category/tutorial/`, `/berita/`, `/kegiatan/`, `/dokumentasi/` | `g-artikel` | `.cath` + filter + list kartu horizontal (thumb 260px kiri) |
| `/2026/11/hello-world/` (single post) | `g-artikel` | `.kd` detail: back link, chips, h1, meta, grid `1fr 380px` (konten `.kbx` + sidebar sticky CTA WA + "Kenapa Pilih PPTI?") |
| `/kebijakan-privasi/`, `/syarat-ketentuan/` | page | `.ph0` judul + `.single .in .pb` konten |
| Single kursus (belum ada datanya) | `g-kursus` | `.kd` + `.kic` info chips + `.kbx` jadwal/overview + sidebar harga `.pcd` + related `.rcg` (CSS sudah ada) |
| Search `?s=` | — | Form `.sf` di header (hidden di layar sempit) |

Navigasi header: **Beranda · Kursus · Jadwal · Tutorial · Berita · Kegiatan · [Hubungi Kami]** (oranye, link WA).

### 1.5 Beranda section-by-section (urutan WAJIB sama)

Tiap baris: class root → isi → perilaku. Markup lengkap ada di `docs/reference/home.body.html`.

| # | Section (class) | Isi persis | Perilaku JS |
|---|---|---|---|
| 0 | `header.hd` sticky, border-bottom 3px lime, tinggi 76px | Logo img (h 48px, radius 6px) + teks "VMES Training Center" 16px 800 green · nav 6 link + CTA `.cta-n` oranye · form search `.sf` | Scroll >24px → class `shr` (tinggi 56px, logo 36px, shadow). `fitNav()`: kalau nav overflow → sembunyikan search (`ns`), masih overflow → mode compact `cp` (hamburger ☰ `.mb`, nav dropdown `.nav.open`). |
| 1 | `section.hero2 > .wrap.hg` grid 1fr 1fr, min-height 620px | Kiri: `.pill` (ikon spark + "Pusat Pelatihan Teknologi Industri (VMES Training Center)"), h1 "Upgrade Your Skills.<br><span.gt>Shape the Future.</span>", `.lead`, `.f4` 4 fitur (users/shield/cloud/laptop), `.hb` 2 tombol: `.b-o` "Lihat Jadwal Training →" & `.b-w` WA "Konsultasi via WhatsApp". Kanan `.hv` (h 540px): `.im` slider 4 gambar (radius 48px, fade putih 42% di kiri), `.gl.g1` glass card "12.000+ Lulusan / Tersebar seluruh Indonesia" (ikon grad), `.gl.g2` "Partner Resmi" grid 2x2 logo Cisco/MikroTik/Pearson VUE/Certiport, `.hdt` dots. | Slider fade 4500ms (`.on`), dots klik. Dots aktif lebar 26px lime. |
| 1b | `.sb > .sg` stats bar (margin-top -32px, grid 5 kolom, radius 24px) | 12.000+ Lulusan (grad) · 20+ Instruktur Ahli (users) · 10+ Tahun Pengalaman (award) · 100+ Jenis Training (trophy) · 300+ Corporate Client (shield) | Counter animasi 1800ms ease-out-cubic saat terlihat (IntersectionObserver .4), format `id-ID`. `data-n`, `data-s`. |
| 2 | `section.tk#tentang-kami > .tkg` grid .9fr 1.1fr | Kiri `.tkv` iframe YouTube (radius 28px, border 6px putih, garis gradien 5px di atas). Kanan `.tkt`: eyebrow "Profil Singkat", h2 "Tentang <em>PPTI VMES Training Center</em>", 3 paragraf (teks di reference), tagline `.tg` border-left oranye 5px: "Mengembangkan Kompetensi, Mendorong Inovasi, Mendukung Kemajuan Industri." | — |
| 3 | `section.sec > .sh` + `.sw` | Header "Kursus" + link "Semua kursus". Slider horizontal `.slider#h-kursus` kartu `.cc` lebar 330px gap 24px, panah `.ar.l/.ar.r` bulat 40px di luar (-20px). 6 kursus dummy (lihat §1.6). | Panah geser 1 kartu; autoplay 4000ms, pause saat hover/focus/touch; wrap ke awal/akhir. Scrollbar tipis lime. |
| 4 | `section.sec.alt > .jk` grid 5fr 7fr gap 44px, bg `--bg` | Kiri: eyebrow "Kategori", h2 "Pilih Jalur Kompetensi Sesuai Kebutuhan Anda", `.cg` grid 2 kolom 8 kartu `.cat` (ikon + judul + deskripsi). Kanan: eyebrow "Jadwal", h2 "Jadwal Terdekat", link "Lihat semua jadwal →", panel `.jt` (border-top 4px lime) baris `.jr` grid `1fr 140px 150px 92px`: judul+lokasi•mode / tanggal+durasi / status pill+harga / tombol `.jb` "Daftar" (WA prefill). 8 jadwal dummy. Footer `.jp` paginasi. | Paginasi 7 baris/halaman: tombol "← Sebelumnya" `.pn`, nomor `.pm` (aktif green + ring lime), "Berikutnya →". |
| 5 | `section.ps > .cn` + `.pw` | Eyebrow "Partner & Client", h2 "Dipercaya oleh Perusahaan & Instansi Terkemuka". `.pt` scroll horizontal, `.pgr` grid auto-flow column 2 baris x 100px, kolom 220px. 16 logo `.lc` grayscale → berwarna saat hover. Panah 44px. | Panah geser 80% lebar. |
| 6 | `section.ms > .cn` + `.wg` grid 3 kolom | Eyebrow "Mengapa PPTI", h2 "Kami Mengembangkan Kompetensi yang Relevan dengan Kebutuhan Industri", subteks `.sbt`. 6 kartu `.wc` (radius 24px, blob lime blur di kanan atas, garis gradien bawah saat hover): ikon `.wi` 56px gradien + judul + nomor pill 01–06 + paragraf. Ikon: factory, wrench, network, users, brief, award. | hover translateY(-4px) |
| 7 | `section.ts > .cn` + `.tf#h-tfeat` + `.tw` | Eyebrow "Testimoni", h2 "Apa Kata Mereka Tentang Kami", subteks. Featured `.tf` grid `420px 1fr` radius 36px: foto kiri (gradien dark bg), kanan `.tb`: pill program `.tp`, 5 bintang, ikon quote 48px lime 35%, kutipan `.tq` clamp(17px,2vw,23px), author `.au` (avatar 64px border 3px lime, nama 18px 800, role). List `.tl` kartu `.tc` 340px (bintang 16px, kutipan clamp 5 baris min-height 120px, author avatar 48px). 5 testimoni (data `docs/reference/testimonials.json`). | Klik/Enter kartu `.tc` → jadi featured, list menampilkan sisanya. Avatar default `az-icons.com/images/users/icon.svg` dengan class `dflt`. |
| 8 | `section.gs > .gk` grid 5fr 7fr, bg `--bg` | Kiri: eyebrow "Dokumentasi", h2 "Momen Training & Aktivitas Peserta PPTI", subteks, tombol `.btn` pill "Lihat galeri lengkap →". Kanan `.gm` mosaic grid 3 kolom gap 12px, item pertama span 2x2, radius 18px, aspect 1:1. 6 foto. | Klik → lightbox `.lb` fullscreen bg `rgba(6,30,18,.92)`, Esc/klik tutup. |
| 9 | `section.sec > .sh` + `.grid#h-artikel` | "Artikel terbaru" + "Semua tutorial". Kartu `.card` (radius 14px, thumb 16:9 atau `.noimg` gradien dark + border-bottom 5px lime, `.cb` tag/h3/p clamp 3/time). | — |
| 10 | `section.cs > .ctw` | Panel dark radius 32px, glow lime & oranye, pola grid 34px. Grid `1.25fr .75fr`: kiri eyebrow `.eb2` "Konsultasi Training", h2 "Siap Mengembangkan Kompetensi untuk Industri Masa Depan?", `.cd` paragraf, `.cbt` tombol `.c1` "Daftar Sekarang →" & `.c2` WA. Kanan `.cpn` glass list 4 poin (ikon check lime): Konsultasi gratis & tanpa komitmen · Materi & jadwal sesuai kebutuhan tim · Instruktur praktisi bersertifikasi · Respon cepat via WhatsApp. | — |
| 11 | `section.ct > .kcs` (di luar `<main>`) | 2 kartu `.kc` (auto-fit 270px): "Kantor Pusat" (ikon pin, link Google Maps) dan "Telepon / WhatsApp 0851-2125-1285" (ikon phone). Glow lime radial di bawah. | — |
| 12 | `footer.ft` | Grid `2fr 1fr 1fr 1fr`: logo di panel putih `.fl` + deskripsi `.fd` + sosial `.so` (kosong di live). Kolom "Layanan" (Public Training, Corporate Training, Online Class, Sertifikasi Internasional), "Perusahaan" (Tentang Kami, Tutorial, Jadwal Training, Kontak), "Bantuan" (FAQ, Cara Pendaftaran, Kebijakan Privasi, Syarat & Ketentuan). Bar bawah `.fb`: © 2026 PT. PPTI - VMES Training Center. All rights reserved. · "Dibangun untuk meningkatkan kompetensi teknologi, industri, dan SDM digital Indonesia." | — |

### 1.6 Data dummy yang tampil di website lama (dipakai sebagai seed awal)

**Kursus (6):** Cisco CCNA (Networking, Cisco, 5 Hari, online/offline, Tersedia, Rp 7.500.000, Populer) · AWS Cloud Practitioner (Cloud Computing, AWS, 3 Hari, hybrid, Tersedia, Rp 7.000.000, Populer) · DevOps Engineering (DevOps, 5 Hari, online, Tersedia, Rp 8.500.000) · MikroTik MTCNA (Networking, MikroTik, 3 Hari, offline, Tersedia, Rp 5.500.000) · Cyber Security Foundation (Cyber Security, 4 Hari, online, Segera dibuka, Rp 6.500.000) · Python Programming (Programming, 4 Hari, hybrid, Tersedia, Rp 4.500.000).

**Jadwal (8):** Cisco CCNA 17 Nov 2026 Batam•online 5 Hari Tersedia 7.5jt · MikroTik MTCNA 24 Nov Jakarta•offline 3 Hari Terbatas 5.5jt · AWS 1 Des Bandung•hybrid 3 Hari "Tersedia (Sisa 14)" 7jt · DevOps 8 Des Online 5 Hari Tersedia 8.5jt · Cyber Security 15 Des Online 4 Hari Tersedia 6.5jt · Python 22 Des Batam•offline 4 Hari Tersedia 4.5jt · Cisco CCNP Enterprise 29 Des Jakarta•offline 5 Hari Terbatas 9.5jt · Linux Administration 5 Jan 2027 Surabaya•hybrid 4 Hari Penuh 5jt.

**Kategori kompetensi (8):** Industrial Automation & Control (cpu) · Robotics & Mechatronics (bot) · Smart Manufacturing & Industrial IoT (factory) · IT, AI & Data Analytics (chart) · Maintenance & Operational Excellence (wrench) · Human Capital & Management (users) · Electrical, Instrumentation & Process Control (zap) · Digital Transformation & Project Management (rocket). Deskripsi masing-masing ada di reference.

**Partner (16, urutan):** telkomsel, Telkom Indonesia, Axis, ericsson, huawei, indosat, lapan, bmkg, lp3i, mandiri, nec, zte, moratelindo, Smartfren, xl axiata, PLN. **Partner resmi (hero):** Cisco, MikroTik, Pearson VUE, Certiport.

**Testimoni (5):** Rizky Pratama, Andika Saputra, Nadia Putri Amelia, Dewi Anggraini, Muhammad Farhan — teks lengkap di `docs/reference/testimonials.json`.

### 1.7 Komponen reusable → file React target

| Komponen | Class asli | File target (frontend/src/components) |
|---|---|---|
| Icon sprite + `<Icon name>` | `<svg class="ic"><use href="#i-x"/>` | `ui/IconSprite.tsx`, `ui/Icon.tsx` |
| Header | `.hd .logo .nav .cta-n .sf .mb` | `layout/Header.tsx` (client) |
| Footer + Kontak | `.ct .kc .ki` / `.ft .fg .fl .fd .so .fb` | `layout/ContactCards.tsx`, `layout/Footer.tsx` |
| Eyebrow pill | `.eb` / `.eb2` / `.catc` / `.pill` | `ui/Eyebrow.tsx` |
| Tombol | `.btn` `.b-o` `.b-w` `.jb` `.c1` `.c2` `.kb1` `.kb2` | pakai class langsung |
| Status pill | `.st.ok/.no` `.jst.ok/.wn/.no` | `ui/StatusPill.tsx` |
| Kartu kursus | `.cc .ci .b1 .b2 .cp .br .ds .mt .pr` | `cards/CourseCard.tsx` |
| Kartu artikel | `.card .noimg .cb .tag .go` | `cards/ArticleCard.tsx` |
| Kartu kategori | `.cat .ib` | `cards/CategoryCard.tsx` |
| Baris jadwal | `.jr .j1 .j2 .j3 .jb` | `cards/ScheduleRow.tsx` |
| Kartu mengapa | `.wc .wi .wh` | `cards/WhyCard.tsx` |
| Testimoni featured & card | `.tf .tb .tq .au .tp .stars` / `.tc` | `home/Testimonials.tsx` (client) |
| Slider arrows | `.ar.l .ar.r` | `ui/SliderArrows.tsx` |
| Lightbox | `.lb` | `home/Gallery.tsx` (client) |
| Stats counter | `.sb .sg` | `home/StatsBar.tsx` (client) |
| Hero slider | `.hs .hdt` | `home/Hero.tsx` (client) |
| Katalog filter bar | `.catb .cats .catf .catn` | `catalog/CatalogBar.tsx` (client) |
| Hero halaman kategori | `.cath .catc` | `catalog/CatalogHero.tsx` |
| Detail post / kursus | `.kd .bk .chips .chp .kdg .kbx .kov .ksd .pcd .kpr .ksp .kbt .why .rt .rc` | `detail/*` |

### 1.8 Breakpoint responsif (WAJIB sama)

| Max-width | Perubahan utama |
|---|---|
| 1000px | hero 1 kolom, `.hv` 420px, stats 2 kolom, `.jk/.gk` 1 kolom, `.wg` 2 kolom, `.tf` 1 kolom, CTA 1 kolom, footer 2 kolom (kolom 1 full), panah slider ±8px |
| 900px | `.grid/.list` 2 kolom, `.tkg` 1 kolom (video aspect 16/9), detail 1 kolom |
| 700px | `.cath` padding 48/80, select 45% |
| 640px | `.f4` 2 kolom, `.hv` 340px radius 32px, `.g2` 230px, stats b 22px, slider card `min(82vw,330px)`, tombol hero full width, `.grid/.list` 1 kolom, `.jr` 2 kolom (j1 & jb full), `.wg` 1 kolom, `.tc` 280px, footer 1 kolom, section padding 44-56px |
| 480px | `.cg` 1 kolom, logo text 12px |
| 420px | `.logo small` hidden |
| `prefers-reduced-motion` | semua transition off, slider/counter tidak autoplay |

Header responsif tidak pakai media query tetapi **deteksi overflow** via JS (`fitNav`).

### 1.9 Aset

| Aset | Sumber di website lama | Status di repo |
|---|---|---|
| Logo PPTI (1600x769 jpeg) | blogger.googleusercontent.com | ✅ `frontend/public/images/logo.jpeg` |
| Favicon 192px | wp-content/uploads/2026/11/cropped-favicon-192x192.jpg | ✅ `frontend/public/images/favicon.jpg` |
| 20 logo partner (png) | supabase storage `uploads/partners/*` | ✅ `frontend/public/images/partners/*.png` |
| Avatar default testimoni | az-icons.com/images/users/icon.svg | ✅ `frontend/public/images/avatar-default.svg` |
| 4 foto hero slider | the-european.eu, tudonghoa.caothang.edu.vn, tmic-ai.com, asfanco.com (hotlink pihak ketiga) | ⚠️ hotlink sementara, **ganti dengan foto PPTI** |
| 6 foto galeri | merca.team, livewireindia.com, agile4training.com x3, arghajata.com (hotlink) | ⚠️ hotlink sementara, **ganti dengan foto PPTI** |
| Video profil | youtube.com/embed/IClaATbg--A | ✅ embed |

### 1.10 Daftar ikon sprite (id)

`i-users i-shield i-cloud i-laptop i-award i-grad i-trophy i-spark i-msg i-arr i-network i-server i-db i-code i-brief i-boxes i-cpu i-bot i-factory i-chart i-wrench i-zap i-rocket i-cal i-globe i-arrl i-clock i-monitor i-star i-quote i-check i-pin i-phone i-mail i-yt i-fb i-tw i-ig i-in` — path lengkap di `docs/reference/icons-sprite.svg`.

### 1.11 Perilaku UX yang harus dipertahankan

- Header mengecil saat scroll; hamburger hanya muncul kalau nav tidak muat.
- Semua CTA "Hubungi Kami / Konsultasi / Daftar" membuka WhatsApp di tab baru dengan teks prefill (`?text=`), daftar per jadwal menyertakan nama + tanggal.
- Slider kursus autoplay & pause saat interaksi; partner & testimoni geser manual.
- Counter statistik animasi sekali saat masuk viewport.
- Testimoni: klik kartu kecil menukar featured.
- Galeri: lightbox.
- Katalog: cari (substring pada `data-q`), filter kategori & mode, urutkan (terbaru, populer, harga rendah/tinggi, A-Z), teks "Menampilkan N training", pesan kosong "Tidak ada hasil yang cocok. Coba kata kunci atau filter lain."
- Jadwal terdekat: paginasi 7 baris.
- Fokus keyboard: outline 3px green.

---

## 2. ARSITEKTUR TARGET & STRUKTUR REPO

```
D:\PPTI
├─ task.md                  ← file ini
├─ docs/
│  ├─ PRD.md                ← teks PRD
│  ├─ reference/            ← CSS/JS/HTML asli pusatpelatihan.id (sumber kebenaran UI)
│  └─ screenshots/          ← tangkapan layar tiap section
├─ frontend/                ← Next.js 15 App Router + TS + Tailwind (public web + portal)
│  └─ src/
│     ├─ app/               ← routes: / , /kursus, /kursus/[slug], /jadwal, /tutorial, /berita, /kegiatan, /dokumentasi, /[slug] (page statis), /daftar, /login, /portal/*, /trainer/*, /admin/*
│     ├─ components/        ← lihat §1.7
│     ├─ data/              ← dummy seed (diganti API)
│     ├─ lib/               ← api client, helpers (formatRupiah, waLink)
│     └─ styles/theme.css   ← port 1:1 theme.style.css
└─ backend/                 ← Laravel 11 API (modular monolith) — BELUM dibuat (butuh PHP 8.2+ & Composer di mesin ini)
```

Keputusan:
- **Frontend** memakai CSS tema asli (class sama) untuk semua halaman publik → jaminan identik. Tailwind untuk portal/admin.
- **Backend** Laravel sebagai REST API (`/api/v1/*`) + Sanctum; frontend fetch via server components / route handlers.
- **DB** PostgreSQL. Storage S3-compatible. Video: YouTube unlisted / layanan streaming (keputusan bisnis §13 PRD).

---

## 3. TASK LIST (gabungan reverse engineering + PRD)

Legenda: `[ ]` belum · `[~]` sedang · `[x]` selesai. Prioritas mengikuti roadmap PRD (Phase 1 → 5). MVP = Phase 1 + inti Phase 2.

### PHASE 1 — Website & Fondasi (PRD §5.1, §5.2, §11)

#### 1A. Setup proyek
- [x] Scaffold Next.js (TS, App Router, Tailwind, ESLint) di `frontend/`
- [x] Port `theme.style.css` → `frontend/src/styles/theme.css` (1:1, tanpa bagian `.admin-bar`, `.wp-*`)
- [x] Font Plus Jakarta Sans via `next/font/google` (400/500/700/800), variable `--font-jakarta`
- [x] Icon sprite komponen + `<Icon name="...">`
- [x] Salin aset (logo, favicon, partner, avatar) ke `public/images`
- [x] Data dummy `src/data/{site,courses,schedules,categories,partners,testimonials,gallery,articles}.ts`
- [ ] Scaffold Laravel 11 di `backend/` (install PHP 8.2+, Composer; `composer create-project laravel/laravel backend`), Sanctum, PostgreSQL, CORS untuk `localhost:3000`
- [ ] Docker compose (postgres, backend, frontend), `.env.example` kedua app
- [ ] Git init, `.gitignore`, CI lint+build

#### 1B. Landing page (clone plek ketiplek) — **TARGET HARI INI**
- [x] `layout/Header` (sticky, shrink, fitNav overflow, hamburger, search form)
- [x] `home/Hero` (pill, h1 gradien, lead, f4, 2 tombol, slider 4 foto fade + dots, glass card lulusan, glass card partner resmi)
- [x] `home/StatsBar` (5 stat, counter IO)
- [x] `home/About` (YouTube embed, 3 paragraf, tagline)
- [x] `home/CourseSlider` (6 `CourseCard`, panah, autoplay 4s, pause)
- [x] `home/CategoriesSchedules` (8 `CategoryCard` + panel jadwal 8 baris + paginasi 7/halaman)
- [x] `home/Partners` (16 logo grid 2 baris scroll + panah)
- [x] `home/WhyUs` (6 `WhyCard`)
- [x] `home/Testimonials` (featured + list, klik tukar)
- [x] `home/Gallery` (mosaic 6 + lightbox)
- [x] `home/LatestArticles` (grid 3 `ArticleCard`)
- [x] `home/CtaBanner`
- [x] `layout/ContactCards` + `layout/Footer`
- [x] Metadata: title "PPTI – VMES Training Center", favicon, lang `id`
- [x] Verifikasi side-by-side dengan screenshot (desktop 800px & mobile 375px: hero, stats, tentang, kursus, kategori+jadwal, partner, mengapa, testimoni, galeri, artikel, CTA, kontak, footer, menu hamburger) — identik
- [ ] Verifikasi tambahan di 1320px & 1000px + `npm run build` produksi
- [ ] Lighthouse: LCP gambar hero pakai `priority`, `next/image` untuk logo & partner

#### 1C. Halaman publik lain (clone)
- [ ] `/kursus` katalog: `CatalogHero` + `CatalogBar` (cari/filter kategori/mode/urut) + grid `CourseCard`
- [ ] `/kursus/[slug]` detail kursus: `.kd` + chips + `.kic` (durasi, mode, level, sertifikat) + jadwal `.kjl` + overview `.kov` + sidebar harga/CTA WA/`why` + related
- [ ] `/jadwal` list jadwal (baris `.card` border-left lime, tanggal pill, tombol "Lihat detail") + kalender bulanan (PRD §5.4)
- [ ] `/jadwal/[id]` detail batch + tombol Daftar (ke form registrasi, bukan hanya WA)
- [ ] `/tutorial`, `/berita`, `/kegiatan`, `/dokumentasi` list artikel/galeri (`g-artikel` layout)
- [ ] `/artikel/[slug]` detail post (`.kd` + sidebar)
- [ ] `/tentang` (profil, visi-misi, fasilitas, trainer, layanan, kerja sama — PRD §5.1) memakai gaya `.tk`, `.wg`, `.kbx`
- [ ] `/kontak` (form konsultasi + kartu kontak + peta)
- [ ] `/kebijakan-privasi`, `/syarat-ketentuan`, `/faq`, `/cara-pendaftaran` (`.ph0` + `.pb`)
- [ ] `/search?q=` hasil pencarian
- [ ] 404 page gaya `.nores`
- [ ] Tombol WhatsApp floating (opsional, konsisten warna `#25a244`)
- [ ] SEO: metadata per halaman, OpenGraph, sitemap.xml, robots.txt, JSON-LD Course/Event/Organization

#### 1D. Backend — Public API & CMS (PRD §5.2)
- [ ] Migrasi & model: `training_categories`, `training_packages`, `training_curricula`, `training_schedules`, `training_classes`, `partners`, `principals`, `testimonials`, `galleries`, `posts` (news/article/activity), `pages`, `menus`, `banners`, `site_settings`
- [ ] Endpoint publik: `GET /api/v1/home` (agregat beranda), `/packages`, `/packages/{slug}`, `/schedules`, `/posts`, `/posts/{slug}`, `/partners`, `/testimonials`, `/galleries`, `/pages/{slug}`, `/search`
- [ ] Admin CRUD (Filament atau Inertia admin) untuk semua entitas CMS: galeri, berita/artikel (slug, thumbnail, kategori, tag, penulis, status, SEO meta), testimoni (approval), mitra/principal, halaman statis, menu, banner, footer, kontak
- [ ] Upload gambar ke S3-compatible + resize/kompresi
- [ ] Frontend: ganti `src/data/*` dengan fetch API (ISR / revalidate on publish)

#### 1E. Registrasi online & akun member (PRD §5.5, §5.6)
- [ ] Form pendaftaran individu `/daftar?jadwal=` (identitas, instansi, jabatan, kontak, dokumen) + pendaftaran kolektif perusahaan
- [ ] Nomor registrasi otomatis, status: pending → under review → waiting for payment → payment verification → confirmed / rejected / cancelled
- [ ] Auth: register/login/forgot (Sanctum), satu akun = satu member (`members`, nomor member)
- [ ] Portal member `/portal`: Dashboard, Profile, My Training, Registration History, Payment History, Notifications, Support
- [ ] Role dasar: super_admin, administrator, management, marketing, finance, training_coordinator, trainer, assessor, student, alumni (`roles`, `permissions`, `user_roles`) + policy
- [ ] Audit log tabel `audit_logs` (actor, action, model, before/after)
- [ ] Notifikasi email (pendaftaran diterima, status berubah) + template

### PHASE 2 — Operasional TMS (PRD §5.3, §5.4, §5.7, §5.11)
- [ ] Training Program Mgmt: CRUD paket (kode, kategori, tujuan, kompetensi, persyaratan, durasi, JP, level, metode, harga, kuota, gambar, status draft/published/archived), kurikulum per paket, jenis program (reguler/in-house/private/online/blended/custom/bundling), jenis sertifikat & completion rule
- [ ] Schedule & Class: CRUD jadwal (kode kelas, tanggal, jam, lokasi, metode, kuota, trainer, status draft→registration open→closed→confirmed→ongoing→completed→cancelled), kalender, daftar tunggu, riwayat & alasan perubahan + notifikasi
- [ ] Participant: verifikasi data/pembayaran/kuota, konversi pendaftar → member → enrollment, kehadiran per sesi
- [ ] Trainer & Assessor: profil (keahlian, pendidikan, sertifikasi, portofolio), penugasan ke kelas (`class_trainers`), portal trainer `/trainer` (Dashboard, My Classes, Participants, Attendance, Profile)
- [ ] Finance: invoice (nomor, biaya, diskon, promo, termin, jatuh tempo), upload bukti transfer, verifikasi finance, riwayat transaksi, laporan pendapatan
- [ ] Laporan operasional + ekspor (CSV/XLSX)
- [ ] Notifikasi WhatsApp API (adapter), log pengiriman

### PHASE 3 — LMS & Monitoring (PRD §5.8, §5.9)
- [ ] Struktur Program → Kelas → Course → Module → Lesson → Resource
- [ ] Resource: e-book/dokumen (PDF/PPT/DOC/XLS viewer + izin unduh), video (streaming, thumbnail, durasi, resume, tracking), quiz/pre-test/post-test, tugas + upload, forum/diskusi
- [ ] Learning progress (lesson dibuka, video ditonton %, modul selesai, tugas, kuis, nilai), akses berdasarkan enrollment & periode kelas
- [ ] Portal student: My Learning, My Progress, Assignments, Results
- [ ] Logbook per pertemuan (tanggal, topik, aktivitas, kompetensi, praktik, observasi, kendala, catatan, dokumentasi), penilaian individual + rubrik per program, kunci setelah validasi + audit
- [ ] Progress overview (kehadiran, materi, nilai, praktik, kompetensi, catatan) untuk peserta & trainer
- [ ] Trainer portal: Materials, Assignments, Quiz Results, Logbook, Progress, Assessment

### PHASE 4 — Sertifikat & Alumni (PRD §5.10, §5.6)
- [ ] Template sertifikat, penomoran unik, penandatangan, masa berlaku; status draft → pending validation → issued → revoked/replaced
- [ ] Generate PDF + QR, halaman verifikasi publik `/verifikasi/[nomor]`, preview, unduh, riwayat
- [ ] Bedakan sertifikat keikutsertaan vs kompetensi (lembaga berwenang)
- [ ] Dashboard alumni: unduh sertifikat, histori, update profil, daftar program baru dengan akun sama; status member registered/active/alumni/inactive

### PHASE 5 — Integrasi lanjutan (PRD §5.12, §5.13)
- [ ] Executive dashboard (pendaftar, peserta aktif, alumni, kelas, kapasitas, program populer, pendapatan, piutang, completion rate, peserta berisiko, trainer, sertifikat) + filter
- [ ] Payment gateway, WhatsApp automation, corporate portal, API publik, PWA
- [ ] Fitur AI terukur (setelah data memadai)

### NON-FUNGSIONAL (PRD §9) — berlaku lintas fase
- [ ] HTTPS, hashing, RBAC, validasi input, CSRF/XSS/SQLi, secure session, rate limit
- [ ] Privacy: consent, minimisasi data (NIK hanya bila perlu), retensi
- [ ] Performance: cache, lazy loading, kompresi gambar, CDN, video terpisah
- [ ] Backup terjadwal + uji restore; target availability 99,5%
- [ ] Aksesibilitas: kontras, label form, keyboard nav, alt text
- [ ] Pengujian: functional, role/permission, integration (registrasi, pembayaran, LMS, sertifikat), security (auth, upload), UAT, backup restore — kriteria AC-01..AC-12

### KEPUTUSAN BISNIS YANG MASIH TERBUKA (PRD §13.1) — tanyakan ke stakeholder
- [ ] Domain & brand final (pusatpelatihan.id tetap?)
- [ ] Daftar kategori/paket/kurikulum/sertifikat riil (menggantikan dummy)
- [ ] Kebijakan harga, diskon, pembayaran, refund
- [ ] Aturan kelulusan per program
- [ ] Akses materi pasca-kelas
- [ ] Penerbit/penandatangan sertifikat & mekanisme verifikasi
- [ ] Penyedia email, WA API, payment gateway, object storage, video
- [ ] Kebijakan data pribadi, retensi, backup, PIC operasional
- [ ] Foto asli PPTI untuk hero & galeri (pengganti hotlink pihak ketiga)
- [ ] Link media sosial (footer `.so` kosong di website lama)

---

## 4. LOG PROGRES

### 2026-11-10
- Reverse engineering pusatpelatihan.id selesai (semua halaman, CSS, JS, sprite, data dummy, screenshot desktop+mobile) → `docs/`.
- PRD diekstrak ke `docs/PRD.md`.
- Next.js 16 di-scaffold di `frontend/` (TS, App Router, Tailwind 4 tanpa preflight), tema di-port 1:1 ke `src/styles/theme.css`, landing page dibangun lengkap 13 komponen (checklist 1B). `tsc` & `eslint` lulus. Diverifikasi di browser (desktop & mobile) dan dibandingkan dengan screenshot asli: identik.
- Jalankan: `cd frontend && npm run dev` → http://localhost:3000
- Belum: halaman lain (1C), `next build` produksi, backend Laravel (PHP/Composer belum terpasang di mesin ini), git init di root `D:\PPTI` (nested `.git` bawaan create-next-app sudah dihapus).
