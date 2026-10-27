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


## Release automation

- `LINNMS-Staging` tag `staging-vX.Y.Z` continues running **the existing staging image build** (unchanged).
- `LINNMS-Staging` tag `vX.Y.Z` after merging tested code to `master` triggers `.github/workflows/trigger-production.yml`. It dispatches this repository's `production.yml` on `main`, passing version, exact tag and tag commit SHA, with publishing enabled.
- Tagged production runs independently verify the tag SHA and that it is on `master`. **They build the tag snapshot, not moving master**, and publish `ghcr.io/ramonveredic/nms:X.Y.Z` and `nms:latest` only after build tests succeed.
- There is deliberately no production Git tag required in `LINNMS`. Direct manual workflow runs remain possible; publishing requires a `source_tag` that matches `version`.
- A push to `LINNMS/main` affecting build scripts still runs a no-publish `v0.0.0-candidate` validation build.

### One-time cross-repository GitHub permission

A GitHub Actions workflow in one repository **cannot dispatch another repository with its default `GITHUB_TOKEN`**. Create a **fine-grained PAT** with access only to `RamonVeredic/LINNMS`, permission **Actions: Read and write**, then add it to the **LINNMS-Staging** repository's Actions secrets under the name `LINNMS_DISPATCH_TOKEN`. Never commit or log this token. The `LINNMS` repository continues using its existing `LINNMS_READ_TOKEN` to read both private source/protection repositories.

### GHCR ownership/access prerequisite

The most recent publishing attempt failed with `denied: permission_denied: read_package` for existing package `ghcr.io/ramonveredic/nms:latest` despite a successful build. Under GitHub Packages → package `nms` → **Package settings → Manage Actions access**, grant `RamonVeredic/LINNMS` **Write** access (or otherwise ensure its workflow token has read/write package access). This is separate from the cross-repository dispatch token. Until corrected, version-tag dispatch may build successfully but its publishing step will fail without overwriting `latest`.

For production, restrict release-tag creation to trusted maintainers (repository rulesets / tag protection) because creating a release tag triggers publication.
