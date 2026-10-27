1. Alur pengembangan sampai production
LINNMS-Staging / deplopment

Pengembangan fitur dan perbaikan bug

Merge untuk pengujian

LINNMS-Staging / staging

Testing aplikasi dengan source PHP asli

Pengujian lulus → merge

LINNMS-Staging / master

Source resmi yang siap dirilis

Jalankan production workflow

LINNMS + LINNMS-protection

Ambil source master → filter → encode → build AMD64/ARM64


GHCR — Production Image

nms:latest + nms:1.0.0


2. Cara memicu production saat ini
Setelah perubahan di LINNMS-Staging berhasil di-merge ke master:
1. Buka LINNMS → Production Workflow.
2. Klik Run workflow.
3. Pilih branch main, isi versi v1.0.0, kemudian jalankan dengan publish=false untuk validasi.
4. Setelah build dan pengujian deployment lulus, jalankan lagi dengan publish=true.
3. Tetapi saya menyarankan alur yang lebih otomatis
Untuk jangka panjang, lebih baik kamu cukup membuat tag rilis di LINNMS-Staging/master, bukan di LINNMS.

Contoh:
git switch master
git pull origin master

git tag v1.0.0
git push origin v1.0.0


Lalu sistem yang kita atur nantinya:
- Tag staging-v1.0.0 di LINNMS-Staging → membuat image staging.
- Tag v1.0.0 di LINNMS-Staging/master → otomatis memicu build production di LINNMS.
- LINNMS mengambil source tepat dari commit tag tersebut, bukan dari master yang mungkin sudah berubah.
- Setelah validasi berhasil, pipeline memublikasikan nms:1.0.0 dan nms:latest.
Perlu dibedakan: mekanisme otomatis dari tag staging ke repository LINNMS ini belum diimplementasikan. Saat ini tag v1.0.0 di LINNMS-Staging tidak otomatis memicu production.
4. Kabar baik: build production lengkap sudah lulus
Saya juga sudah mengecek GitHub Actions production terbaru. Tahap filter, encoding, dan Docker build AMD64/ARM64 semuanya berhasil. Image belum dipublikasikan karena build tersebut merupakan validasi otomatis dengan push=false.

Rekomendasi saya: gunakan satu pintu rilis, yaitu LINNMS-Staging/master. Untuk sementara jalankan production secara manual di LINNMS. Selanjutnya kita ubah agar cukup membuat tag vX.Y.Z di LINNMS-Staging, dan seluruh proses production berjalan otomatis di repository LINNMS, tanpa perlu membuat tag kedua.
