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
└─ backend/                 ← Laravel 13 API (modular monolith), PHP 8.3 — scaffold selesai 2026-11-10
```

Keputusan:
- **Frontend** memakai CSS tema asli (class sama) untuk semua halaman publik → jaminan identik. Tailwind untuk portal/admin.
- **Backend** Laravel sebagai REST API (`/api/v1/*`) + Sanctum; frontend fetch via server components / route handlers.
- **DB** PostgreSQL. Storage S3-compatible. Video: **embed YouTube** (keputusan 2026-11-12; tidak memakai layanan streaming berbayar).

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
- [x] Scaffold **Laravel 13.40** (PHP 8.3.36 via winget, Composer 2.11) di `backend/`; Sanctum (`install:api`, `HasApiTokens`, `statefulApi()`), `.env.example` PostgreSQL, CORS `config/cors.php` origin dari `FRONTEND_URL` + credentials, `GET /api/v1/health`, tes `tests/Feature/Api/HealthTest.php`
- [x] Docker compose (postgres 16, backend, frontend) di root + `backend/Dockerfile`, `frontend/Dockerfile` (standalone), `.env.example` kedua app. Belum diuji: Docker daemon tidak aktif saat dikerjakan
- [x] Git init di root + push ke https://github.com/fajar288/PusatPelatihanVMES (branch `main`)
- [x] CI lint+build (GitHub Actions) — `.github/workflows/ci.yml`: frontend typegen+tsc+eslint+build di tiap push/PR ke `main`; job backend aktif otomatis saat `backend/composer.json` ada

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
- [x] Verifikasi tambahan di 1320px & 900px + `npm run build` produksi (lulus, 57 halaman)
- [x] Lighthouse: preload LCP hero (`<link rel=preload fetchpriority=high>` di `app/page.tsx`), `next/image` untuk logo & 20 partner (AVIF/WebP, srcset). Desktop beranda: performa 78→87, LCP 3,2→2,0 s, gambar tidak responsif 3,3 MB→0,86 MB (sisa = 4 foto hero hotlink yang akan diganti). A11y 91 (kontras `.cta-n`/`.b2`/`.btn`, `h4` footer, dot slider kecil — tidak diubah demi clone 1:1, keputusan stakeholder)

#### 1C. Halaman publik lain (clone)
- [x] `/kursus` katalog: `CatalogHero` + `CatalogBar` (cari/filter kategori/mode/urut) + grid `CourseCard` (+ query string `?q=&kategori=&mode=&urut=`)
- [x] `/kursus/[slug]` detail kursus: `.kd` + chips + `.kic` (durasi, mode, level, sertifikat) + jadwal `.kjl` + overview `.kov` + sidebar harga/CTA WA/`why` + related (`detail/*`, data overview di `data/courses.ts`)
- [x] `/jadwal` list jadwal (baris `.card` border-left lime, tanggal pill, tombol "Lihat detail") + kalender bulanan (PRD §5.4) — `catalog/ScheduleCatalog` + `ScheduleCalendar`
- [x] `/jadwal/[id]` detail batch + tombol Daftar → `/daftar?jadwal=` (form awal kirim ke WA; API 1E menyusul). Data batch (kode, jam, lokasi, kuota, trainer) di `data/schedules.ts`
- [x] `/tutorial`, `/berita`, `/kegiatan`, `/dokumentasi` list artikel/galeri (`g-artikel` layout) — route `app/[slug]` + `catalog/PostCatalog`; 18 post dummy di `data/articles.ts`
- [x] `/artikel/[slug]` detail post (`.kd` + sidebar) — `ui/PostBody` render blok konten
- [x] `/tentang` (profil, visi-misi, fasilitas, trainer, layanan, kerja sama — PRD §5.1) memakai gaya `.tk`, `.wg`, `.kbx` — konten draf di `data/about.ts` (perlu review PPTI)
- [x] `/kontak` (form konsultasi + kartu kontak + peta) — form kirim ke WA sementara (`forms/ContactForm`), CSS baru di `styles/extra.css`
- [x] `/kebijakan-privasi`, `/syarat-ketentuan`, `/faq`, `/cara-pendaftaran` (`.ph0` + `.pb`) — konten draf di `data/pages.ts` (perlu review legal/PPTI)
- [x] `/search?q=` hasil pencarian (kursus, jadwal, artikel)
- [x] 404 page gaya `.nores` (`app/not-found.tsx`)
- [x] Tombol WhatsApp floating (`layout/WaFloat`, `#25a244`)
- [x] SEO: metadata per halaman, OpenGraph, `app/sitemap.ts`, `app/robots.ts`, JSON-LD Organization/Course/EducationEvent/Article (`lib/seo.tsx`, `NEXT_PUBLIC_SITE_URL`)

#### 1D. Backend — Public API & CMS (PRD §5.2)
- [x] Migrasi & model: `training_categories` (hierarkis: 8 area beranda → kategori paket), `training_packages`, `training_curricula`, `training_schedules`, `partners` (kolom `type` client|principal|certification menggantikan tabel `principals`), `testimonials`, `galleries`, `posts` (type tutorial|news|activity|documentation), `pages`, `menus`, `banners`, `site_settings` (key→JSON). `training_classes` TIDAK dibuat: PRD §5.4 menyamakan jadwal = kelas; enrollment menyusul Phase 2. Seed dari konten frontend: `frontend/scripts/export-seed.mjs` → `backend/database/seeders/data/*.json` → `CmsSeeder` (idempoten)
- [x] Endpoint publik: `GET /api/v1/home` (agregat, cache 5 mnt), `/packages` (q/kategori/mode/urut + meta), `/packages/{slug}` (jadwal, kurikulum, terkait), `/schedules` (+`/schedules/{code}`), `/posts` (type/q/urut, paginasi), `/posts/{slug}` (body HTML), `/partners`, `/testimonials`, `/galleries`, `/categories`, `/menus`, `/settings`, `/pages/{slug}`, `/search`. Resource JSON berbentuk sama dengan tipe di `frontend/src/data`. Tes `PublicApiTest` (12 tes/123 asersi)
- [x] Frontend: ganti `src/data/*` dengan fetch API — `src/lib/cms.ts` (`"use cache"` + `cacheLife('hours')` + `cacheTag('cms', ...)`), fallback otomatis ke data statis bila API tak terjangkau (`src/lib/fallback.ts`; build CI tetap hijau), webhook `POST /api/revalidate` (header `x-revalidate-secret`, env `REVALIDATE_SECRET`) → `revalidateTag`. Komponen beranda/layout menerima data lewat props. URL jadwal kini memakai kode kelas (`/jadwal/CCNA-2611`). Nomor WA & identitas kontak masih dari `src/data/site.ts` (`waLink`) — dipakai komponen klien di banyak tempat; dipindah ke settings saat admin CMS siap
- [x] Admin CRUD **Filament 5.11** di `/admin` (`feat/admin-filament`): 11 resource — kategori, paket, jadwal, mitra/principal, testimoni (approval), galeri, post (RichEditor, thumbnail, tag, penulis, status, SEO), halaman statis, menu (header + 3 kolom footer), banner hero, site_settings (JSON; editor terstruktur menyusul). Akses: `users.role` ∈ `User::ADMIN_ROLES` (`canAccessPanel`), seeder `AdminUserSeeder` (`ADMIN_EMAIL`/`ADMIN_PASSWORD`). Perubahan konten → `CmsObserver` → flush cache API + webhook `POST {FRONTEND_URL}/api/revalidate` (`REVALIDATE_SECRET`). Tes `AdminPanelTest`
- [x] Upload gambar: `FileUpload` Filament ke disk default (`FILESYSTEM_DISK=public` lokal via `storage:link`, atau `s3` + `AWS_*` untuk S3-compatible), resize di sisi klien (maks 1600px; logo 1200, banner 1920). URL publik lewat `App\Support\Media::url()` di semua resource API. Kompresi server-side (WebP) belum — opsional

#### 1E. Registrasi online & akun member (PRD §5.5, §5.6)
- [x] Form pendaftaran `/daftar?jadwal=` individu + kolektif perusahaan (daftar peserta) → server action → `POST /api/v1/registrations`; pendaftar login otomatis terkait member & terisi otomatis; hasil menampilkan nomor registrasi. Dokumen pendukung diunggah lewat admin/bukti bayar (unggah dokumen di form menyusul)
- [x] Nomor registrasi otomatis (`REG-YYYYMM-0001`), enum `RegistrationStatus` dengan transisi tervalidasi (`RegistrationService::transition`), invoice otomatis saat menunggu pembayaran, unggah bukti transfer → verifikasi, konfirmasi menambah `registered_count` & mengaktifkan member. Admin: Filament `Registrations` (aksi Ubah status) + `Payments` (verifikasi/tolak)
- [x] Auth API token Sanctum: `POST /auth/register|login|logout|forgot-password|reset-password` (tautan reset ke frontend), `GET/PATCH /me`; `members` (`MBR-YYYY-000001`), pendaftaran tamu dengan email sama otomatis terkait saat akun dibuat
- [x] Portal member `/portal` (dashboard, profil, pelatihan saya, riwayat pendaftaran + detail dengan progres status/unggah bukti/batalkan, riwayat pembayaran, notifikasi, bantuan) + halaman `/login`, `/register`, `/lupa-password`, `/reset-password`. Sesi = token Sanctum di cookie httpOnly (`lib/auth.ts`, server actions di `lib/actions/*`). Route per-pengguna memakai `instant = false`
- [x] Role dasar: `roles`, `permissions`, `role_permission`, `user_roles` (`RolesSeeder`), `User::hasRole/hasPermission`, `RegistrationPolicy`
- [x] Audit log `audit_logs` (`AuditLog::record` + `AuditObserver` untuk Registration/Payment/Member/Schedule), resource Filament hanya-baca, `GET /me/notifications`
- [x] Notifikasi email `RegistrationReceived`, `RegistrationStatusChanged` (template MailMessage bahasa Indonesia, MAIL_MAILER=log lokal), reset sandi

### PHASE 2 — Operasional TMS (PRD §5.3, §5.4, §5.7, §5.11)
- [x] Training Program Mgmt: CRUD paket lengkap (Filament `TrainingPackages`), kurikulum per paket (`CurriculaRelationManager`, bisa diurutkan), jenis program (`program_type`), jenis sertifikat (`certificate_type`) & completion rule (`completion_rule` JSON: kehadiran min %, nilai min, wajib tugas/post-test; `completionRule()` dengan default). Terekspos di API paket
- [x] Schedule & Class: CRUD jadwal + tab Trainer (penugasan `class_trainers` dengan peran), Sesi (`class_sessions`), Peserta (enrollment), Riwayat perubahan. Daftar tunggu (`waitlists`, `POST /schedules/{code}/waitlist`, form di detail jadwal saat penuh, Filament aksi Tawarkan/Jadikan pendaftaran). Perubahan tanggal/jam/lokasi/metode/status → `schedule_changes` + alasan dari form admin + email `ScheduleChanged` ke pendaftar aktif. Kalender publik sudah ada (1C)
- [x] Participant: verifikasi data/pembayaran/kuota (1E), konversi pendaftar terkonfirmasi → member → `enrollments` (`EnrollmentService`, idempoten; kolektif = per peserta), kehadiran per sesi (`attendances`, Filament `Kehadiran` dengan aksi massal), `GET /me/enrollments` + portal "Pelatihan Saya" (sesi & kehadiran)
- [x] Trainer & Assessor: profil (`trainers`) + penugasan (`class_trainers`) + portal trainer `/trainer` (Dashboard, Kelas Saya, detail kelas dengan peserta & matriks kehadiran per sesi, Profil) via API `/trainer/*` (akses = akun dengan profil trainer aktif)
- [x] Finance: invoice (nomor, biaya, diskon manual/promo `promo_codes`, termin n× dengan jatuh tempo berjarak, `FinanceService`), aksi "Promo / diskon" & "Buat tagihan termin" di pendaftaran, upload bukti & verifikasi (1E), riwayat transaksi (resource Pembayaran + portal), laporan pendapatan (halaman Laporan: terverifikasi, menunggu, piutang, per bulan)
- [x] Laporan operasional (`ReportService`: pendaftaran, pembayaran, kelas & kapasitas, peserta & kehadiran) + halaman admin `/admin/laporan` + ekspor CSV/XLSX (`/admin/export/{type}.{csv|xlsx}`, openspout, izin `reports.view`)
- [ ] Notifikasi WhatsApp API (adapter), log pengiriman — **ditunda** atas permintaan (2026-11-10); menunggu keputusan penyedia WA API (§13.1)

### PHASE 3 — LMS & Monitoring (PRD §5.8, §5.9)
- [x] Struktur Program → Kelas → Course → Module → Lesson → Resource: `courses` (per paket, opsional khusus satu jadwal), `course_modules`, `lessons` (tipe text|document|video|link|quiz|assignment, `settings` JSON per tipe), `lesson_resources` (berkas/tautan + izin unduh). Admin Filament grup **LMS**: resource Course (+tab Modul, aksi "Kelola lesson") dan Lesson (+tab Berkas & tautan, Soal, Pengumpulan tugas); izin `lms.manage`
- [x] Resource: e-book/dokumen (PDF/gambar/video/audio viewer inline via proxy `/portal/belajar/berkas/[id]`, Office buka/unduh, izin unduh per berkas), video (YouTube/Vimeo embed atau file MP4; thumbnail, durasi, resume posisi, tracking % ditonton, selesai otomatis ≥ `min_watch_pct`), kuis/pre-test/post-test (`quiz_questions` single/multiple/true-false/isian, `quiz_attempts` dinilai otomatis, batas waktu, maks. percobaan, pembahasan), tugas + upload (`assignment_submissions`, tenggat, nilai maks., kirim ulang sebelum dinilai), forum/diskusi (`discussion_posts` per course/lesson, balasan, label Trainer)
- [x] Learning progress (`lesson_progress`: dibuka, video %, selesai; modul/course % dari lesson wajib; enrollment → `attending` saat mulai belajar). Akses = enrollment aktif (enrolled/attending/completed) pada kelas dari paket course + periode kelas (`available_before_class`, `access_days_after`) + `available_from` per lesson. API `/me/learning/*` (`LearningController`, `LmsService`, `LmsPresenter`)
- [x] Portal student: Belajar `/portal/belajar` (My Learning), detail course (modul/lesson/status, forum), halaman lesson (materi, berkas, video, kuis, tugas, tanya jawab, navigasi prev/next), Progres Saya `/portal/progres`, Tugas `/portal/tugas`, Hasil `/portal/hasil`
- [x] Logbook per pertemuan (`training_logbooks`: sesi opsional, tanggal, topik, aktivitas, kompetensi, praktik, observasi, kendala, catatan, dokumentasi berkas) + catatan per peserta (`logbook_entries`: status praktik, observasi — AC-08). Rubrik per program (`assessment_rubrics` + `rubric_criteria`: kode aspek, bobot, skor maks., deskriptor level; rubrik bawaan 6 aspek PRD §5.9 dibuat otomatis bila program belum punya) & penilaian individual (`competency_assessments` + `assessment_scores`; total = rata-rata tertimbang %, ambang kompeten per rubrik). Validasi → `is_locked`; trainer tidak bisa mengubah; admin buka kunci (alasan) / ubah → `audit_logs` `logbook.*`/`assessment.*` (`MonitoringService`). Admin Filament grup **Monitoring** (Rubrik penilaian, Logbook + tab Catatan peserta, Penilaian kompetensi); izin `monitoring.manage`
- [x] Progress overview (`MonitoringService::overview/classOverview`): kehadiran, materi, nilai kuis/tugas, praktik (dari logbook), kompetensi, catatan trainer. Peserta: `GET /me/progress` (hanya logbook/penilaian tervalidasi) → `/portal/progres`. Trainer: `/trainer/progres` → tabel kelas `/trainer/progres/[code]` → detail peserta `/trainer/progres/[code]/[id]`
- [x] Trainer portal: Materials `/trainer/materi`, Assignments `/trainer/tugas`, Quiz Results `/trainer/kuis`, Logbook `/trainer/logbook/[code]` (form + riwayat, ubah draf, validasi & kunci, unduh dokumentasi via proxy `/trainer/logbook/berkas/[id]/[i]`), Assessment `/trainer/penilaian/[code]` (skor per kriteria dengan deskriptor level, perkiraan total langsung, simpan draf / validasi & kunci), Progress `/trainer/progres`

### PHASE 4 — Sertifikat & Alumni (PRD §5.10, §5.6)
- [x] Template sertifikat (`certificate_templates`: judul/kalimat dengan placeholder, penerbit, penandatangan 1–2 + gambar tanda tangan, logo/latar, warna, orientasi, format nomor `PPTI-{TYPE}-{YYYY}{MM}-{SEQ}`, masa berlaku bulan; bawaan per jenis dibuat otomatis; `training_packages.certificate_template_id`), penomoran unik urut per jenis/tahun + `verification_code` acak; status `draft → pending_validation → issued → revoked/replaced` (`CertificateService`: submit/reject/issue/revoke/replace; izin `certificates.manage` vs `certificates.issue` pejabat berwenang)
- [x] Aturan kelulusan (`CompletionService::evaluate/complete`): kehadiran min., nilai min. (nilai akhir → rata-rata kuis/tugas → kompetensi), tugas wajib, post-test wajib; admin menetapkan lulus/tidak (bisa dipaksa, tercatat audit) dari relation manager Peserta kelas (per peserta & semua)
- [x] Generate PDF (dompdf, Blade `certificates/default`, A4 landscape/portrait, lampiran materi/unit kompetensi hal. 2, tanda air PRATINJAU untuk draf) + QR (bacon-qr-code SVG → URL verifikasi), halaman verifikasi publik `/verifikasi` + `/verifikasi/[nomor]?k=` (API `GET /verify/{number}`), pratinjau/unduh admin (`/admin/certificates/{id}/pdf`, contoh template `/admin/certificate-templates/{id}/preview`), unduh member (`/me/certificates/{number}/pdf` → proxy `/portal/sertifikat/unduh/[number]`), riwayat penerbitan di audit log (relation manager Riwayat + `/portal/sertifikat/[number]`); email `CertificateIssued`
- [x] Jenis participation / competency / vendor dibedakan (kode nomor PST/KMP/VND, template & masa berlaku berbeda; kompetensi mensyaratkan penilaian kompetensi tervalidasi & kompeten)
- [x] Dashboard alumni: status member otomatis registered → active → alumni (`MemberService::syncStatus` via event Enrollment, peran student↔alumni, `members.alumni_since`), kartu Dashboard Alumni di `/portal` (daftar program baru dengan akun sama, histori, profil), menu `/portal/sertifikat` (unduh, pratinjau, verifikasi, detail & riwayat), sertifikat tampil di Pelatihan Saya; admin Filament grup Sertifikat (Sertifikat + Template; aksi ajukan/terbitkan/kembalikan/cabut/ganti/buat ulang PDF, bulk terbitkan, "Buat draf untuk kelas")

### PHASE 5 — Integrasi lanjutan (PRD §5.12, §5.13)
- [x] Executive dashboard (pendaftar, peserta aktif, alumni, kelas, kapasitas, program populer, pendapatan, piutang, completion rate, peserta berisiko, trainer, sertifikat) + filter — `/admin/dashboard-eksekutif` (izin `analytics.view`), ekspor ringkasan/peserta berisiko/kinerja trainer CSV/XLSX
- [x] API publik integrasi pihak ketiga `/api/public/v1` (API key per klien + scope, rate limit, batas IP, log permintaan, webhook bertanda tangan HMAC, OpenAPI 3.1 + dokumentasi `/docs/api`, admin Integrasi → Klien API)
- [x] PWA: manifest + ikon (maskable, apple-touch), service worker (cache aset/gambar/halaman publik, fallback `/offline`, area per-pengguna tidak di-cache), `experimental.useOffline` + banner offline, notifikasi versi baru, kartu "Pasang aplikasi" di portal member & trainer
- [x] Notifikasi & komunikasi (PRD §5.12): notifikasi aplikasi di portal (baca/belum dibaca, badge), web push PWA (VAPID), email opsional, template pesan (admin), preferensi per kategori, log terkirim/gagal semua kanal, pengingat otomatis (kelas H-1, tenggat tugas, materi baru), pengumuman admin
- [x] Pembayaran **manual** (keputusan 2026-11-12, tanpa payment gateway): transfer ke rekening resmi / Virtual Account statis → unggah bukti di portal → verifikasi finance. Admin **TMS: Pendaftaran & Keuangan → Rekening pembayaran** (izin `payments.verify`); tampil di portal & email tagihan. Sisa: isi data rekening/VA resmi PPTI di admin
- [ ] WhatsApp automation (kanal WA di modul notifikasi) — penyedia WA **diurus nanti** → lanjut di Roadmap 5 tahap 5D (driver `log`)
- [ ] Corporate portal → dikerjakan di **PHASE 5 — Roadmap 5** di bawah
- [x] Chatbot informasi publik memakai **Claude (Anthropic API)** — dibangun **nonaktif** (aktifkan: `CHATBOT_ENABLED=true` + `ANTHROPIC_API_KEY` di .env backend). Hanya data katalog website, tanpa data pribadi peserta; log + KPI di admin **Landing Page → Chatbot AI**
- [ ] Fitur AI terukur lain (PRD Roadmap 5 §9: rekomendasi training, early warning, analisis feedback, LMS assistant) — ditunda (lihat Roadmap 5 tahap 5G)

### PHASE 5 — Roadmap 5: Enterprise, Integration & Intelligence (`docs/PRD-Roadmap5.md`, aturan kerja `docs/PROMPT-Roadmap5.md`)

**Keputusan & penyimpangan yang disengaja dari PRD Roadmap 5**
- **Payment gateway TIDAK dikerjakan** (PRD §5, §10 langkah 3–4, §15 butir webhook) — penyimpangan yang disengaja: atasan mengonfirmasi ulang 2026-11-12 bahwa pembayaran tetap manual (transfer bank / VA statis + unggah bukti + verifikasi finance). Aturan PRD yang tetap berlaku untuk alur manual: invoice lunas tidak bisa dibayar ulang, status hanya berubah lewat verifikasi finance, refund punya alasan/penyetuju/waktu/referensi, semua perubahan diaudit. Tidak ada form nomor kartu.
- **WhatsApp ditunda**: kanal WA hanya antarmuka dengan driver `log`, dimatikan lewat konfigurasi.
- **AI** (pilihan nomor 3, 2026-11-12): chatbot publik Claude dari PR #22 dipertahankan (nonaktif, `CHATBOT_ENABLED`); asisten belajar di portal member hanya kerangka "Belum tersedia" (`AI_CHATBOT_ENABLED=false`, endpoint 503 `ai_unavailable`, tanpa panggilan API AI). Rekomendasi, early warning berbasis model, dan analisis feedback tidak dikerjakan.
- Kolom teks `members.company` / `registrations.company` tetap sebagai isian, **tidak** dipakai untuk hak akses perusahaan.

**Keputusan terbuka (dibuat bisa dikonfigurasi, default aman)**
- [ ] Pembuatan akun organisasi — default: hanya admin VMES yang membuat organisasi lalu mengundang Corporate Owner (setting agar pendaftaran mandiri perusahaan bisa dibuka nanti)
- [ ] Pajak invoice — default tarif 0%, baris pajak disembunyikan bila 0 (5C)
- [ ] Akses histori setelah resign — setting per organisasi, default PRD §20.7: peserta dengan employment INACTIVE hilang dari daftar karyawan aktif dan detail progresnya tidak bisa dibuka, tetapi enrollment sponsored lamanya tetap dihitung di laporan agregat dan status sertifikatnya tetap terlihat
- [ ] Verifikasi email peserta — aplikasi belum punya alur verifikasi email; sampai ada (rencana 5B bersama undangan), kecocokan email akun yang belum terverifikasi diperlakukan sebagai kandidat yang perlu dikonfirmasi, bukan dipakai otomatis (PRD §20.5)

**5A Fondasi data enterprise** (`feat/enterprise-foundation`)
- [ ] `organizations` (profil legal, alamat, industri, kontak, data pajak/invoice, logo, status, `needs_review`, setting), `organization_units` (departemen/cost center/proyek), `employment_relationships` (member, organisasi, employee ID, unit, posisi, mulai, selesai, ACTIVE/INACTIVE, sumber), `corporate_users` (user ↔ organisasi + peran perusahaan + batas unit opsional)
- [ ] `enrollments.type` (self/corporate) + `sponsor_organization_id` (+ unit sponsor); aturan R1/R2 di service **dan** constraint DB
- [ ] Migrasi backfill: enrollment individu → SELF; enrollment dari pendaftaran kolektif → CORPORATE dengan organisasi hasil normalisasi nama perusahaan, ditandai `needs_review`
- [ ] `IdentityMatchingService` sesuai tabel §20.5 + review queue untuk data meragukan; backfill `member_id` kosong lewat service ini (tanpa membuat akun otomatis)
- [ ] Query scope server-side `visibleToOrganization` + Policy; organization_id tidak bisa diganti lewat URL/API (§20.21)
- [ ] Sponsorship Transfer (§20.20): permintaan → otorisasi → berlaku per tanggal efektif, audit tak bisa diubah
- [ ] Resign → employment INACTIVE, akses corporate dicabut sesuai kebijakan, member/sertifikat/histori tetap
- [ ] Admin Filament: Organisasi, Employment, Corporate User, Review identitas, Transfer sponsor
- [ ] Tes setiap baris tabel §11 + skenario §20.7–20.8

**5B Corporate Portal MVP** (`feat/corporate-portal`)
- [ ] `training_requests` (status §4.2 DRAFT → … → COMPLETED/CANCELLED) + `request_seats`; reserved seat tidak membuat Member
- [ ] Quotation dibuat admin VMES; approval perusahaan opsional per organisasi
- [ ] Penugasan peserta: manual, unggah Excel/CSV (template + laporan galat per baris), tautan undangan bertoken (verifikasi + consent), cari member existing — semua lewat identity matching, tolak enrollment ganda di batch yang sama
- [ ] Request terjadwal → enrollment CORPORATE bersponsor; pembatalan satu peserta hanya mengubah enrollment itu
- [ ] Pendaftaran kolektif form publik tetap jalan, admin wajib menautkan ke organisasi sebelum konfirmasi
- [ ] Frontend `/corporate/*` (login akun sama): ringkasan, request, peserta, progres (tanpa catatan privat trainer), sertifikat (status), keuangan, pengguna perusahaan, ekspor sesuai scope; batas privasi §20.11

**5C Transaksi** (`feat/payment-transactions`) — tanpa payment gateway
- [ ] Invoice lengkap (item, subtotal, pajak dapat dikonfigurasi, diskon, total, jatuh tempo) untuk individu & training request
- [ ] `payment_transactions` untuk setiap bukti & verifikasi/penolakan; pemetaan status → Pending/Paid/Failed/Refunded + Expired (job terjadwal)
- [ ] Invoice lunas menolak bukti baru; verifikasi ganda tidak membuat enrollment/notifikasi ganda
- [ ] Rekonsiliasi finance (selisih nominal, unggah mutasi CSV opsional); alur refund (alasan, penyetuju, waktu, referensi)
- [ ] Finance perusahaan: lihat invoice organisasi, unggah bukti, status verifikasi di portal perusahaan

**5D Otomasi notifikasi** (`feat/notification-engine`)
- [ ] Katalog event §6, penerima per scope (termasuk admin perusahaan hanya untuk enrollment bersponsor), penjadwalan, retry, status Queued/Sent/Delivered/Failed/Read, versi template, kanal WA driver `log`

**5E API enterprise** (`feat/api-enterprise`)
- [ ] Klien API terikat organisasi, endpoint §7 dengan scope/rate limit/versioning, `Idempotency-Key`, event webhook domain baru, OpenAPI diperbarui

**5F Analitik lanjutan** (`feat/advanced-analytics`)
- [ ] `docs/KPI.md` dari §8.1 dan implementasi persis definisinya
- [ ] Corporate vs self-funded, funnel corporate, cohort/repeat order, AOV, analitik perusahaan §20.15, master kompetensi + target per posisi (competency gap dasar)

**5G Kerangka asisten belajar AI** (`feat/ai-chatbot-shell`)
- [ ] Panel "Asisten Belajar" di portal member berlabel "Belum tersedia", flag `AI_CHATBOT_ENABLED`, endpoint 503 `ai_unavailable`, tabel `ai_insights` kosong (use case, versi model, versi prompt, referensi data, reviewer); chatbot publik PR #22 tidak diubah

**5H Hardening** (`feat/roadmap5-hardening`)
- [ ] Tes keamanan organization scope (IDOR antar perusahaan, ekspor, token dicabut), cakupan audit, indeks DB untuk kueri dashboard
- [ ] Checklist acceptance criteria §15 + §20.22 dengan status tiap butir

### NON-FUNGSIONAL (PRD §9) — berlaku lintas fase
- [ ] HTTPS, hashing, RBAC, validasi input, CSRF/XSS/SQLi, secure session, rate limit
- [ ] Privacy: consent, minimisasi data (NIK hanya bila perlu), retensi
- [ ] Performance: cache, lazy loading, kompresi gambar, CDN, video terpisah
- [ ] Backup terjadwal + uji restore; target availability 99,5%
- [ ] Aksesibilitas: kontras, label form, keyboard nav, alt text
- [ ] Pengujian: functional, role/permission, integration (registrasi, pembayaran, LMS, sertifikat), security (auth, upload), UAT, backup restore — kriteria AC-01..AC-12

### KEPUTUSAN BISNIS YANG MASIH TERBUKA (PRD §13.1) — tanyakan ke stakeholder
- [x] Domain & brand final — **pusatpelatihan.id tetap** (2026-11-12)
- [ ] Daftar kategori/paket/kurikulum/sertifikat riil (menggantikan dummy)
- [ ] Kebijakan harga, diskon, pembayaran, refund
- [ ] Aturan kelulusan per program
- [ ] Akses materi pasca-kelas
- [ ] Penerbit/penandatangan sertifikat & mekanisme verifikasi
- [ ] Penyedia layanan — keputusan 2026-11-12: pembayaran **manual** (tanpa gateway), AI **Claude**, video **embed YouTube**; email, WA API, server/hosting & object storage **diurus nanti**
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
- Belum: halaman lain (1C), `next build` produksi, backend Laravel (PHP/Composer belum terpasang di mesin ini). Repo: https://github.com/fajar288/PusatPelatihanVMES (commit awal 9c4c927).
- Halaman `/kursus` (katalog: hero `.cath`, bar `.catb` cari/kategori/mode/urut dengan logika identik `draw()` main.js, grid `.cc`) dan `/kursus/[slug]` (detail: chips, `.kic`, jadwal `.kjl`, overview `.kov`, sidebar `.pcd` + `why`, related `.rcg`) selesai. Data detail (level, sertifikat, overview) ditambahkan ke `src/data/courses.ts`. 404 `.nores`. Diverifikasi 1320/900/375px via Edge headless; `tsc`, `eslint`, `next build` lulus.
- Catatan teknis: `cacheComponents` aktif → `searchParams` harus dibaca di dalam `<Suspense>` (lihat `app/kursus/page.tsx`).
- Seluruh checklist 1C selesai: `/jadwal` (+kalender), `/jadwal/[id]`, `/daftar` (form awal), list kategori `/tutorial` `/berita` `/kegiatan` `/dokumentasi`, `/artikel/[slug]`, `/tentang`, `/kontak`, 4 halaman statis, `/search`, WA melayang, SEO (sitemap/robots/JSON-LD). Form kontak & daftar sementara mengirim ke WhatsApp sampai API Laravel (1D/1E) ada. Konten `data/about.ts`, `data/pages.ts`, artikel non-tutorial, dan detail batch adalah DRAF yang harus direview PPTI. Diverifikasi 1320/375px via Edge headless; `tsc`, `eslint`, `next build` lulus.
- Pola Next 16 (cacheComponents): `searchParams` dibaca di komponen anak dalam `<Suspense>`; `new Date()` di client component dibungkus `useSyncExternalStore` (lihat `ScheduleCalendar`).
- Backend: PHP 8.3.36 (winget `PHP.PHP.8.3`, php.ini dibuat dari php.ini-production dengan ekstensi curl/fileinfo/gd/intl/mbstring/openssl/pdo_pgsql/pgsql/sqlite/zip/sodium) + Composer 2.11.1 (`%LOCALAPPDATA%\Composerin`, di PATH user). `composer create-project laravel/laravel backend` → Laravel 13.40. Sanctum, CORS, `.env.example` pgsql, health endpoint, Pint + 5 tes lulus. `docker-compose.yml` + Dockerfile keduanya ditulis (belum diuji, Docker daemon mati).
- 1D tahap 1 (branch `feat/cms-schema-api`): skema training + CMS (2 migrasi, 12 model), seeder dari JSON ekspor frontend, 15 endpoint publik `/api/v1/*` dengan resource yang meniru bentuk data frontend, 12 tes lulus. Tersisa 1D: admin CRUD (Filament — cek dukungan Laravel 13), upload S3, dan penggantian `src/data/*` di frontend dengan fetch API.
- 1D tahap 2 (branch `feat/frontend-api`, di atas `feat/cms-schema-api`): semua halaman publik membaca API lewat `lib/cms.ts` dengan cache Next 16 + fallback statis; body post/halaman kini HTML dari CMS (`dangerouslySetInnerHTML` ke `.pb`, sama seperti WordPress lama). Diverifikasi end-to-end dengan `artisan serve` (semua halaman, sitemap 47 URL) dan build tanpa backend (fallback). Tersisa 1D: admin CRUD + upload S3.
- 1D tahap 3 (branch `feat/admin-filament`, di atas `feat/cms-schema-api`): panel admin Filament 5 (11 resource), peran dasar `users.role`, observer invalidasi cache + webhook revalidasi frontend (diuji end-to-end: `Page::touch()` → POST /api/revalidate 200), upload gambar ke disk public/S3. 16 tes / 150 asersi lulus. Seluruh checklist 1D selesai.
- 1E tahap 1 (branch `feat/registration-auth`): backend registrasi (individu/kolektif), status & invoice, auth token Sanctum, member, roles/permissions, audit log, email, Filament Pendaftaran/Pembayaran/Audit log. 28 tes lulus. Tersisa 1E: form `/daftar` ke API + halaman auth + portal member `/portal` di frontend (PR berikutnya).
- 1E tahap 2 (branch `feat/portal-frontend`, di atas `feat/registration-auth`): auth cookie httpOnly via server actions, form `/daftar` ke API, halaman auth, portal member 7 halaman (gaya tema + `styles/extra.css` bagian portal), tautan "Portal Member" di footer (menu CMS). Diuji end-to-end: register+registrasi via API lalu semua halaman portal 200 dengan cookie, tamu → 307 ke /login. `tsc`, `eslint`, `next build` lulus. Seluruh checklist 1E selesai.
- Phase 2 tahap 1 (branch `feat/tms-crud`): kurikulum, jenis program/sertifikat/completion rule, trainer & penugasan, sesi kelas, enrollment otomatis saat konfirmasi, kehadiran, daftar tunggu, riwayat perubahan jadwal + notifikasi. 33 tes backend lulus; frontend: portal Pelatihan Saya memakai `/me/enrollments`, form daftar tunggu di detail jadwal penuh. Tersisa Phase 2: portal trainer, finance lanjutan (diskon/promo/termin/laporan), laporan & ekspor, WhatsApp API.
- Phase 2 tahap 2 (branch `feat/tms-phase2-b`): portal trainer (API + frontend `/trainer`), kode promo & diskon, tagihan termin, laporan pendapatan, halaman Laporan + ekspor CSV/XLSX. 36 tes backend lulus. Adapter WhatsApp ditunda; selain itu Phase 2 selesai.
- Phase 3 tahap 1 (branch `feat/lms-core`): LMS inti — course/modul/lesson/berkas, video + tracking, kuis otomatis, tugas + penilaian trainer, learning progress, forum, akses berdasarkan enrollment & periode kelas; admin Filament grup LMS; portal peserta (Belajar/Progres/Tugas/Hasil) & trainer (Materi/Tugas/Hasil kuis). 44 tes backend lulus; `tsc`, `eslint`, `next build` lulus; diuji end-to-end via curl + cookie. Demo: `php artisan db:seed --class=LmsDemoSeeder`. Tersisa Phase 3: logbook, rubrik & penilaian individual, progress overview lengkap (tahap 2).

### 2026-11-11
- Phase 3 tahap 2 (branch `feat/lms-logbook-assessment`): logbook per pertemuan + catatan per peserta + dokumentasi, rubrik per program (bawaan 6 aspek PRD §5.9) & penilaian kompetensi individual, validasi → kunci, buka kunci admin + audit log, progress overview lengkap untuk peserta (`/me/progress` → `/portal/progres`) & trainer (`/trainer/logbook`, `/trainer/penilaian`, `/trainer/progres`); admin Filament grup Monitoring. 48 tes backend lulus; `tsc`, `eslint`, `next build` lulus; diuji end-to-end via curl + cookie (logbook multipart + unduh dokumentasi, kunci, visibilitas peserta). Seluruh checklist Phase 3 selesai.
- Catatan teknis: `actingAs($u, 'sanctum')` mengubah guard default — tes halaman admin setelahnya harus `actingAs($admin, 'web')`. Closure Filament (`modifyQueryUsing`, filter `query`) disuntik berdasarkan nama parameter: pakai `$query`, bukan `$q`. `backend/CLAUDE.md` meminta Laravel Boost; pemasangan ditolak kebijakan izin sesi, belum dipasang.
- Phase 4 (branch `feat/certificates-alumni`): aturan kelulusan + penetapan lulus/tidak, template sertifikat, sertifikat dengan nomor unik & alur draf → validasi → terbit → cabut/ganti, PDF (dompdf) + QR (bacon-qr-code), verifikasi publik `/verifikasi/[nomor]`, portal `/portal/sertifikat` (+ detail & riwayat, unduh via proxy), status member otomatis → alumni + dashboard alumni, admin Filament grup Sertifikat, izin `certificates.manage`/`certificates.issue`. Paket baru: `barryvdh/laravel-dompdf`, `bacon/bacon-qr-code`. 52 tes backend lulus; `tsc`, `eslint`, `next build` lulus; diuji end-to-end via curl + cookie (portal, PDF proxy, verifikasi) dan screenshot Edge headless. Seluruh checklist Phase 4 selesai. Demo lokal: skrip tinker membuat `alumni@example.com / rahasia123` dengan sertifikat terbit.
- Catatan teknis: Blade memperlakukan `kata@endif` (tanpa spasi) sebagai email → "unexpected end of file"; beri spasi sebelum `@endif`. Port 3000 bisa ditempati `next start` dari build lama (route baru 404) — cek proses sebelum uji E2E. Filament resource dengan model ber-`getRouteKeyName()` khusus: set `$recordRouteKeyName = 'id'` bila URL panel ingin memakai id.
- Phase 5 tahap 1 (branch `feat/executive-dashboard`): Dashboard Eksekutif `/admin/dashboard-eksekutif` (PRD 5.13) - `AnalyticsService` menghitung peserta (pendaftar, konversi, aktif, alumni, program populer), kelas (fase, kapasitas), keuangan (pendapatan, invoice, menunggu verifikasi, piutang & jatuh tempo, per bulan/program), pembelajaran (completion rate, kehadiran, progres LMS, kuis, kompetensi, peserta berisiko), trainer (penugasan, sesi, logbook, evaluasi), sertifikat (terbit, status); filter periode/kategori/paket/kelas/trainer/status; 11 widget Filament (stats, Chart.js, tabel); ekspor CSV/XLSX `executive`, `at-risk`, `trainers` lewat `/admin/export`. Izin baru `analytics.view` (administrator, management). 56 tes backend lulus. Tersisa Phase 5: payment gateway, WhatsApp automation, corporate portal, API publik, PWA, fitur AI (menunggu keputusan penyedia/data).
- Catatan teknis: `Sanctum::actingAs` mengganti guard default ke `sanctum` - dalam tes yang lalu memakai `actingAs($user)` untuk panel, panggil `$this->app['auth']->shouldUse('web')` (gejala: 302 ke /admin/login). Widget khusus halaman diberi `$isDiscovered = false` agar tidak muncul di dashboard bawaan; data custom tabel Filament lewat `->records(fn (int $page, int $recordsPerPage) => LengthAwarePaginator)`.
- Phase 5 tahap 2 (branch `feat/public-api-pwa`): **API Publik** `/api/public/v1` terpisah dari API internal frontend (`/api/v1`) - tabel `api_clients` (key `ppti_<prefix>_<rahasia>` disimpan hash sha256, ditampilkan sekali; scope `catalog.read`, `registrations.write`, `registrations.read`, `certificates.verify`; rate limit/menit; daftar IP/CIDR; kedaluwarsa; webhook URL + secret terenkripsi + event), `api_request_logs`, `webhook_deliveries`, kolom `registrations.api_client_id` + `external_ref` (idempoten). Middleware `api.client` (X-Api-Key / Bearer, header X-RateLimit-*, log setelah respons) & `api.scope:<scope>`; galat seragam `{"error":{"code","message","fields"}}`. Endpoint: `GET /` (identitas klien), `/categories`, `/programs[/{slug}]`, `/schedules[/{code}]` (filter, paginasi, `updated_since`), `POST/GET /registrations[/{number}]` (hanya milik klien; cek jadwal dibuka, batas pendaftaran, sisa kursi), `GET /certificates/{number}?code=`. Webhook keluar (job `DeliverWebhook`, 5 percobaan bertahap) `registration.created|status_changed`, `certificate.issued|revoked` dengan header `X-PPTI-Signature: t=..,v1=HMAC-SHA256(secret, "t.body")`. Spesifikasi `App\Support\PublicApiSpec` → `/api/public/v1/openapi.json` + Redoc `/docs/api`; tes memastikan setiap route terdokumentasi. Admin grup **Integrasi → Klien API** (buat/ganti key, secret webhook, uji webhook, log permintaan, riwayat & kirim ulang webhook), izin `api.manage` (administrator), audit `api_client.*`. Log & riwayat webhook dipangkas harian (`model:prune`, `API_LOG_RETENTION_DAYS`). **PWA**: `app/manifest.ts` + ikon dari emblem logo (`scripts/generate-pwa-icons.mjs`, sharp), `public/sw.js` buatan sendiri (tanpa Serwist), `PwaProvider` (registrasi SW hanya di produksi atau `NEXT_PUBLIC_ENABLE_SW=1`, banner offline `useOffline`, tawaran muat ulang versi baru), `InstallPrompt` (beforeinstallprompt / petunjuk iOS), `/offline`, header `sw.js` no-cache. Tervalidasi di Edge headless: SW aktif, halaman publik terbuka offline dari cache, `/portal` & halaman belum dikunjungi → `/offline`. 64 tes backend lulus.
- Catatan teknis: observer webhook `ShouldHandleEventsAfterCommit` → nilai lama memakai `getPrevious()` (bukan `getOriginal()`). Job webhook memakai `release()` bukan exception agar antrean `sync` tidak menggagalkan request pemicu. Di Git Bash argumen yang diawali `/` (mis. `/kursus`) diubah jadi path Windows → pakai `MSYS_NO_PATHCONV=1` saat menjalankan skrip node dengan path URL.
- Phase 5 tahap 3 (branch `feat/notification-center`): **Notifikasi & komunikasi (PRD §5.12)** - tabel `notifications` (database channel Laravel), `notification_templates` (kunci peristiwa, kategori, judul/isi aplikasi & push, subjek/isi email, kanal, aktif; bawaan dibuat otomatis `NotificationTemplate::ensureDefaults`), `notification_preferences` (per pengguna × kategori: email, push), `notification_logs` (semua notifikasi & kanal, termasuk email transaksional lama, lewat listener `LogSentNotification` + kegagalan per kanal), `push_subscriptions`. `NotificationCenter::send` render placeholder `{var}`, pilih kanal (aplikasi selalu; email/push sesuai preferensi + `communication_pref.email` profil), kirim per kanal (gagal satu kanal tidak menggagalkan lainnya), dedupe untuk pengingat. `NotificationEvents`: pendaftaran diterima/status berubah, perubahan jadwal, materi baru (event `Lesson::saved`), tenggat tugas < 24 jam, tugas dinilai, penilaian kompetensi tervalidasi, sertifikat terbit; perintah `notifications:reminders` (tiap jam 07-20: kelas H-1, tenggat tugas, materi yang mulai tersedia). Web Push: `minishlink/web-push` ^11 (`WebPushSender`, kanal `WebPushChannel`, langganan kedaluwarsa 404/410 dihapus), kunci `php artisan webpush:vapid` → `VAPID_*`. API `/me/notifications` (+ `read`, `read-all`), `/me/notification-settings` (GET/PUT), `/me/push-subscriptions` (POST/DELETE/test, limiter bernama `push-subscribe`/`push-test`); `GET /me` menyertakan `unreadNotifications`. Admin grup **Notifikasi**: Template notifikasi (edit, aksi "Kirim pengumuman" ke peserta kelas/kelas aktif/alumni/semua member) dan Log notifikasi; izin `notifications.manage` (administrator, management, marketing, training coordinator). Frontend `/portal/notifikasi` (tab semua/belum dibaca, klik = tandai dibaca + menuju tautan, tandai semua, paginasi, pengaturan per kategori, `PushToggle` aktif/matikan/uji), badge belum dibaca di menu portal, `sw.js` v2 menangani `push` & `notificationclick`. `PwaProvider` tidak lagi me-reload halaman saat SW pertama kali mengambil alih (hanya setelah "Muat ulang"). Tervalidasi: 69 tes backend; E2E build produksi + Edge headless - langganan push ke WNS asli tersimpan, push uji terkirim (HTTP 201) dan diterima handler `push` di SW (headless tidak bisa menampilkan notifikasi), klik notifikasi menandai dibaca.
- Catatan teknis: `throttle:N,1` tanpa nama memakai satu penghitung per pengguna lintas route → pakai limiter bernama. Notifikasi memakai UUIDv7 (`SystemNotification::$id`) agar urutan stabil. PHP Windows dev: `OPENSSL_CONF` harus di-set sebelum PHP start (putenv tidak berpengaruh) dan `curl.cainfo` perlu CA bundle (mis. salinan `C:\Program Files\Git\usr\ssl\certs\ca-bundle.crt` di path tanpa spasi) agar push ke layanan push berjalan; produksi Linux tidak perlu. Dev server :3000 lama mengalami loop reload terus-menerus (juga di /kursus) setelah restart otomatis karena `next.config` berubah → restart manual `npm run dev`.

### 2026-11-12
- Keputusan atasan dicatat (§2, Phase 5, §13.1): pembayaran manual (transfer + unggah bukti + verifikasi finance; nomor Virtual Account statis per bank bila PPTI punya rekening bisnis), AI memakai Claude (arah: chatbot), video embed YouTube, domain tetap pusatpelatihan.id; WA, email, server/hosting diurus nanti.
- Video YouTube memakai IFrame Player API (`components/lms/VideoPlayer.tsx`): posisi & durasi dibaca dari player (durasi tidak wajib diisi lagi), lanjut dari posisi terakhir (`start`), persentase = titik terjauh yang ditonton berurutan (seek ke depan tidak dihitung; berlaku juga untuk file MP4). Vimeo / API gagal dimuat tetap memakai hitungan waktu di halaman. Tervalidasi di Edge headless: 31 dtk → 3%, muat ulang lanjut dari detik 47 → 6%.
- Rekening pembayaran manual (branch `feat/payment-accounts`): tabel `payment_accounts` (jenis transfer bank / Virtual Account statis, bank, nomor, atas nama, petunjuk, urutan, aktif), admin Filament `Rekening pembayaran` (ManageRecords, urut seret, izin `payments.verify`), API `GET /me/payment-accounts`, email "Menunggu pembayaran" mencantumkan rekening aktif (tanpa data → kalimat umum lama), portal `/portal/pembayaran` & kartu unggah bukti di detail pendaftaran menampilkan daftar rekening + tombol "Salin nomor" (`PaymentAccounts`, `CopyText`). 74 tes backend lulus.
- Chatbot AI publik (branch `feat/ai-chatbot`): SDK resmi `anthropic-ai/sdk` ^1.8, model `claude-opus-5-5` (effort `low`, server-side fallback `fallbacks: default` bila model menolak), prompt sistem + data katalog (profil & kontak, alur pendaftaran, program, jadwal mendatang dengan harga/sisa kursi/tautan) di-cache (`cache_control`). `config/chatbot.php` — NONAKTIF bawaan; batas 6/menit & 40/hari per IP + kuota global 500/hari. API publik `GET /chat/status`, `POST /chat` (riwayat dikirim klien, maks. 12 giliran, 800 karakter), `POST /chat/feedback`. Measurable AI (Roadmap 5 §9): tabel `chat_logs` (pertanyaan/jawaban, status answered/unresolved/refused/error, model + versi prompt, token termasuk cache, latensi, 👍/👎 pengunjung, tinjauan admin), IP disimpan sebagai HMAC, dipangkas 90 hari; penanda `[[PERLU_ADMIN]]` dari model → status "tidak terjawab". Admin **Chatbot AI** (izin `cms.manage`/`analytics.view`): KPI 30 hari + aksi Tinjau. Frontend `components/chat/ChatWidget.tsx` (tidak dirender bila nonaktif; memanggil backend langsung via CORS sehingga IP pengunjung terbaca), tautan path internal & URL jadi link, saran pertanyaan, 👍/👎, tombol WhatsApp admin; layar penuh di ponsel. Tes: `ChatbotTest` (9) dengan `FakeChatModel` + `AnthropicChatModelTest` (bentuk request SDK lewat transport palsu: header beta, `cache_control`, `fallbacks`, effort). 85 tes backend lulus. Catatan: SDK PHP ini mengirim API key sebagai `Authorization: Bearer`. Produksi di balik proxy/CDN perlu `trustProxies` agar batas per IP memakai IP asli.
