# LINNMS

**LINNMS** adalah *Network Management System* untuk membantu pemantauan dan pengelolaan informasi perangkat jaringan, termasuk OLT dan ONU.

Repository ini menyediakan **image LINNMS** dan contoh instalasinya untuk **Docker Compose** maupun **CasaOS**. Kode aplikasi yang telah difilter disinkronkan dari branch sumber; direktori bernama `vendor` tidak disertakan. Pada image rilis, **hanya PHP di `runtime/vendor/` yang terenkripsi dan diperiksa sebagai payload PQ**. PHP aplikasi, halaman web, dan konfigurasi lain disalin apa adanya. Pemeriksaan sintaks PHP sebelum enkripsi hanya dijalankan pada `runtime/vendor/`. Image rilis tersedia melalui `ghcr.io/ramonveredic/linnms`.

## Pilih cara instalasi

| Lingkungan | File | Penggunaan |
| --- | --- | --- |
| Docker Compose | [`docker-compose.yml`](docker/docker-compose.yml) | Jalankan LINNMS dari terminal di server Linux. |
| CasaOS | [`casaos-compose.yaml`](docker/casaos-compose.yaml) | Impor konfigurasi ke CasaOS melalui *Custom Install*. |

## Instalasi melalui Docker Compose

1. Pasang Docker dan plugin Docker Compose pada server.
2. Unduh repository:

   ```bash
   git clone https://github.com/RamonVeredic/LINNMS.git
   cd LINNMS
   ```

3. Periksa konfigurasi dan port di [`docker-compose.yml`](docker/docker-compose.yml). Secara default file ini menggunakan `ghcr.io/ramonveredic/linnms:latest`. Untuk memakai tag rilis tertentu, buat file `.env` di root repository:

   ```dotenv
   LINNMS_IMAGE=ghcr.io/ramonveredic/linnms:1.0.0
   ```

   Ubah `1.0.0` sesuai versi image yang tersedia. Hindari perubahan tag yang tidak terkontrol pada instalasi penting. Jangan memasukkan kredensial ke Git.

4. Validasi konfigurasi, ambil image, kemudian jalankan:

   ```bash
   docker compose -f docker/docker-compose.yml config
   docker compose -f docker/docker-compose.yml pull
   docker compose -f docker/docker-compose.yml up -d
   docker compose -f docker/docker-compose.yml ps
   ```

5. Buka `http://IP-SERVER:84` dari browser, kemudian ikuti penyiapan awal pada aplikasi. Port HTTPS container diteruskan melalui `4143`; penggunaan HTTPS publik perlu konfigurasi sertifikat yang sesuai.

Jika registry meminta autentikasi, lakukan `docker login ghcr.io` menggunakan akun dan token yang memiliki izin membaca paket tersebut.

### Periksa layanan dan log

```bash
docker compose -f docker/docker-compose.yml ps
docker compose -f docker/docker-compose.yml logs --tail=100 app
docker compose -f docker/docker-compose.yml logs --tail=100 db
```

Nama container utama pada contoh ini adalah `linnms` dan `linnms-db`. Docker Compose menggunakan named volume `linnms-state`, `linnms-db`, dan `linnms-run` untuk data persisten dan komunikasi database.

## Instalasi melalui CasaOS

1. Buka antarmuka web CasaOS dan masuk ke **App Store**.
2. Pilih **Custom Install** / **Install a customized app**, kemudian **Import**.
3. Buka [`casaos-compose.yaml`](docker/casaos-compose.yaml) dan salin seluruh isi YAML ke kolom impor. Tinjau pengaturan container, port, dan volume sebelum memasang.
4. Pastikan direktori **`/DATA/AppData/linnms/`** dapat dibuat dan ditulisi oleh Docker.
5. Selesaikan instalasi, lalu buka `http://IP-SERVER:84`.

Konfigurasi CasaOS menyimpan data secara terpisah di:

- `/DATA/AppData/linnms/state` — konfigurasi dan state aplikasi.
- `/DATA/AppData/linnms/database` — data MariaDB.
- `/DATA/AppData/linnms/run` — socket komunikasi database.

CasaOS menggunakan image rilis `ghcr.io/ramonveredic/linnms:latest` pada contoh awal. Anda dapat menggantinya dengan tag rilis yang tersedia sebelum instalasi atau pembaruan. Jika port `84` atau `4143` telah terpakai, ubah port host pada konfigurasi terlebih dahulu.

## Pembaruan dan pencadangan

Sebelum pembaruan, catat tag image yang digunakan dan **cadangkan data aplikasi serta database secara konsisten**. Pada Docker Compose, untuk mengambil image baru dan menjalankannya kembali:

```bash
docker compose -f docker/docker-compose.yml pull
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml ps
```

Untuk CasaOS, perbarui image melalui pengaturan aplikasi dengan mempertahankan seluruh lokasi penyimpanan. Pastikan data yang dicadangkan dapat dipulihkan sebelum melakukan upgrade.

**Jangan menjalankan `docker compose down -v` untuk update atau troubleshooting:** opsi `-v` menghapus named volume yang dapat memuat database dan konfigurasi penting.

## Sinkronisasi source

Setiap commit ke `LINNMS-Staging/master` mengirim pemicu ke workflow [LINNMS Sync](.github/workflows/sync.yml) pada repositori ini. Sinkronisasi juga bisa dijalankan secara manual melalui GitHub Actions. Tidak ada jadwal enam jam. Riwayat sinkronisasi tercatat di Actions repositori publik ini.

Hanya file aplikasi dan konfigurasi yang diizinkan yang disalin. Seluruh direktori `vendor` serta dokumentasi, README, dan file build milik repositori ini tidak ikut ditimpa. Commit hanya dibuat jika hasil sinkronisasi berubah; judulnya mengikuti commit sumber dan SHA sumber dicatat dalam isi commit.

Source di luar `vendor` menjadi **publik**. Pastikan tidak ada rahasia atau kredensial dalam source. Workflow pemicu di Staging menggunakan secret `LINNMS_READ_TOKEN` untuk mengirim permintaan sinkronisasi ke `LINNMS`. Nama secret sama di kedua repositori, tetapi harus terpasang secara terpisah: secret yang sudah terpasang di `LINNMS` tidak otomatis tersedia di `LINNMS-Staging`. Gunakan token yang sama, dengan izin menulis ke `LINNMS`, tanpa membuat token baru. Workflow publik juga menggunakan `LINNMS_READ_TOKEN` untuk membaca Staging; nilainya harus tetap privat. GitHub mencatat run pemicu di Staging dan run sinkronisasi di LINNMS.
