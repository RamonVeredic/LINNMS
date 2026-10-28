# LINNMS

**LINNMS** adalah *Network Management System* untuk membantu pemantauan dan pengelolaan informasi perangkat jaringan, termasuk OLT dan ONU.

Repository ini menyediakan **image LINNMS Production** dan contoh instalasinya untuk **Docker Compose** maupun **CasaOS**. Source aplikasi tidak disertakan di repository ini. Pada image production, **hanya PHP di `runtime/vendor/` yang terenkripsi**; PHP aplikasi, halaman web, dan konfigurasi lain tetap plaintext. Image production tersedia melalui `ghcr.io/ramonveredic/linnms`.

## Pilih cara instalasi

| Lingkungan | File | Penggunaan |
| --- | --- | --- |
| Docker Compose | [`docker-compose.yml`](docker-compose.yml) | Jalankan LINNMS dari terminal di server Linux. |
| CasaOS | [`casaos-compose.yaml`](casaos-compose.yaml) | Impor konfigurasi ke CasaOS melalui *Custom Install*. |
| Instalasi tambahan | [`deploy/compose.secondary.yaml`](deploy/compose.secondary.yaml) | Contoh instance kedua dengan port dan direktori data terpisah. |

File yang sudah ada di [`deploy/`](deploy/) tetap dipertahankan. `docker-compose.yml` dan `casaos-compose.yaml` di root disediakan agar pengguna langsung menemukan konfigurasi instalasi. **Pilih salah satu metode untuk instance utama**; jangan menjalankan keduanya bersamaan pada host yang sama karena nama container dan portnya sama.

## Persyaratan

- Server Linux **amd64** atau **arm64**, dengan Docker Engine dan Docker Compose v2; untuk CasaOS gunakan server dengan CasaOS yang sudah berjalan.
- Koneksi internet untuk mengambil image dari GHCR, atau image yang sudah tersedia secara lokal.
- Port host **84/TCP** untuk HTTP dan **4143/TCP** untuk HTTPS, tidak sedang dipakai layanan lain.
- Koneksi dari server LINNMS ke alamat manajemen perangkat. Untuk perangkat yang menggunakan SNMP, periksa routing, ACL, dan akses **UDP 161**.
- Penyimpanan persisten untuk konfigurasi LINNMS dan database MariaDB.

> **Catatan keamanan:** File Compose yang disediakan mengikuti konfigurasi instalasi LINNMS saat ini: MariaDB berjalan melalui Unix socket lokal tanpa akses TCP, tetapi **akun database `root` menggunakan kata sandi kosong**. Konfigurasi ini ditujukan untuk host yang terisolasi dan dikelola administrator. Sebelum mengekspos aplikasi ke jaringan tidak tepercaya, gunakan kredensial database yang kuat, batasi akses host, dan siapkan HTTPS/reverse proxy. Perubahan kata sandi pada database yang sudah berisi data memerlukan migrasi kredensial; jangan hanya mengganti variabel lingkungan lalu menjalankan ulang container.

## Instalasi melalui Docker Compose

1. Pasang Docker dan plugin Docker Compose pada server.
2. Unduh repository:

   ```bash
   git clone https://github.com/RamonVeredic/LINNMS.git
   cd LINNMS
   ```

3. Periksa konfigurasi dan port di [`docker-compose.yml`](docker-compose.yml). Secara default file ini menggunakan `ghcr.io/ramonveredic/linnms:latest`. Untuk memakai tag rilis tertentu, buat file `.env` di root repository:

   ```dotenv
   LINNMS_IMAGE=ghcr.io/ramonveredic/linnms:1.0.0
   ```

   Ubah `1.0.0` sesuai versi image yang tersedia. Hindari perubahan tag yang tidak terkontrol pada instalasi penting. Jangan memasukkan kredensial ke Git.

4. Validasi konfigurasi, ambil image, kemudian jalankan:

   ```bash
   docker compose -f docker-compose.yml config
   docker compose -f docker-compose.yml pull
   docker compose -f docker-compose.yml up -d
   docker compose -f docker-compose.yml ps
   ```

5. Buka `http://IP-SERVER:84` dari browser, kemudian ikuti penyiapan awal pada aplikasi. Port HTTPS container diteruskan melalui `4143`; penggunaan HTTPS publik perlu konfigurasi sertifikat yang sesuai.

Jika registry meminta autentikasi, lakukan `docker login ghcr.io` menggunakan akun dan token yang memiliki izin membaca paket tersebut.

### Periksa layanan dan log

```bash
docker compose -f docker-compose.yml ps
docker compose -f docker-compose.yml logs --tail=100 app
docker compose -f docker-compose.yml logs --tail=100 db
```

Nama container utama pada contoh ini adalah `linnms` dan `linnms-db`. Docker Compose menggunakan named volume `linnms-state`, `linnms-db`, dan `linnms-run` untuk data persisten dan komunikasi database.

## Instalasi melalui CasaOS

1. Buka antarmuka web CasaOS dan masuk ke **App Store**.
2. Pilih **Custom Install** / **Install a customized app**, kemudian **Import**.
3. Buka [`casaos-compose.yaml`](casaos-compose.yaml) dan salin seluruh isi YAML ke kolom impor. Tinjau pengaturan container, port, dan volume sebelum memasang.
4. Pastikan direktori **`/DATA/AppData/linnms/`** dapat dibuat dan ditulisi oleh Docker.
5. Selesaikan instalasi, lalu buka `http://IP-SERVER:84`.

Konfigurasi CasaOS menyimpan data secara terpisah di:

- `/DATA/AppData/linnms/state` — konfigurasi dan state aplikasi.
- `/DATA/AppData/linnms/database` — data MariaDB.
- `/DATA/AppData/linnms/run` — socket komunikasi database.

CasaOS menggunakan image production `ghcr.io/ramonveredic/linnms:latest` pada contoh awal. Anda dapat menggantinya dengan tag rilis yang tersedia sebelum instalasi atau pembaruan. Jika port `84` atau `4143` telah terpakai, ubah port host pada konfigurasi terlebih dahulu.

## Pembaruan dan pencadangan

Sebelum pembaruan, catat tag image yang digunakan dan **cadangkan data aplikasi serta database secara konsisten**. Pada Docker Compose, untuk mengambil image baru dan menjalankannya kembali:

```bash
docker compose -f docker-compose.yml pull
docker compose -f docker-compose.yml up -d
docker compose -f docker-compose.yml ps
```

Untuk CasaOS, perbarui image melalui pengaturan aplikasi dengan mempertahankan seluruh lokasi penyimpanan. Pastikan data yang dicadangkan dapat dipulihkan sebelum melakukan upgrade.

**Jangan menjalankan `docker compose down -v` untuk update atau troubleshooting:** opsi `-v` menghapus named volume yang dapat memuat database dan konfigurasi penting.

## Build production dari master hasil merge

**Source aplikasi production berasal dari `RamonVeredic/LINNMS-Staging`, bukan dari isi repository LINNMS.** Repository ini menyediakan resep build, penyaringan file, dan konfigurasi instalasi. Image diberi label `org.opencontainers.image.revision` berisi commit SHA staging yang digunakan.

Di [GitHub Actions → LINNMS](https://github.com/RamonVeredic/LINNMS/actions/workflows/production.yml), pilih **Run workflow** pada branch `main`:

1. Untuk memeriksa kode terbaru, pilih `source_mode: master` dan `publish: false`. Workflow akan mengambil commit terbaru `LINNMS-Staging/master` saat proses fetch berlangsung, lalu mencatat SHA di log dan ringkasan Actions. Build ini **tidak** mendorong image ke registry.
2. Untuk menerbitkan kode `master` secara manual, gunakan `source_mode: master`, tentukan **versi rilis baru**, lalu aktifkan `publish: true`. Tidak perlu mengisi commit SHA. Workflow mengambil snapshot `LINNMS-Staging/master` saat proses fetch dan otomatis mencatat SHA hasil checkout di log, ringkasan Actions, dan metadata image.
3. Untuk rilis yang dapat diulang, pilih `source_mode: tag` dan isi `source_tag` dengan **tag rilis hasil merge ke `LINNMS-Staging/master`**, misalnya `v1.3.0`. Tag `staging-v*` hanya untuk pengujian dan tidak boleh dipakai sebagai sumber production. Saat tag `v1.3.0` dibuat di repository LINNMS, workflow otomatis mengambil **tag `v1.3.0` dari LINNMS-Staging**, bukan `staging-v1.3.0`. Pipeline memastikan commit tag sumber dapat dijangkau dari `master`.

**Penting:** Branch `master` dapat berubah setelah merge: build manual dari `master` mengambil HEAD pada saat fetch, bukan commit tetap yang ditentukan oleh nomor versi. Untuk rilis yang benar-benar dapat diulang, gunakan `source_mode: tag`. Hindari menerbitkan ulang tag image untuk versi yang sudah dipakai pengguna. Tag rilis `vX.Y.Z` pada LINNMS-Staging harus dibuat dari commit yang sudah masuk `master`; contoh `v1.3.0` menunjuk ke Merge #17 (`505ab32`). Tag pengujian `staging-vX.Y.Z` tidak digunakan untuk production. Menjalankan ulang workflow dari tag LINNMS lama memakai versi file workflow pada commit tag tersebut, bukan perubahan terbaru di `main`. Jika tag LINNMS sudah ada, jalankan **workflow_dispatch** dari `main` dengan `source_mode: tag`, `source_tag: vX.Y.Z`, dan `publish: false` dahulu. Jangan memindahkan tag yang sudah terbit; perbarui versi atau jalankan rilis manual sesuai prosedur. Selalu uji sebelum memublikasikan perubahan.

## Referensi

- [Compose untuk Docker](deploy/compose.yaml) — versi file instalasi dalam folder `deploy`.
- [Compose untuk CasaOS](deploy/compose.casaos.yaml) — versi file instalasi dalam folder `deploy`.
- [Compose instance kedua](deploy/compose.secondary.yaml) — opsi instance tambahan.
- [Dockerfile production](docker/Dockerfile.production) — resep pembuatan image terenkripsi.
- [LINNMS-Staging](https://github.com/RamonVeredic/LINNMS-Staging) — repository pengembangan.
- [LINNMS-protection](https://github.com/RamonVeredic/LINNMS-protection) — encoder dan native loader.

> File Compose di root adalah salinan dari konfigurasi `deploy/` saat dibuat. Jika mengubah port, image, volume, atau layanan, jaga kedua salinan tetap sinkron. Image production dibangun melalui workflow rilis, bukan dengan menjalankan Dockerfile production langsung tanpa konteks build terlindungi.
