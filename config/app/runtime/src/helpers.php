<?php

declare(strict_types=1);



$linnmsTimezone = trim((string)(getenv('LINNMS_TIMEZONE') ?: 'Asia/Jakarta'));
if (!in_array($linnmsTimezone, timezone_identifiers_list(), true)) {
    $linnmsTimezone = 'Asia/Jakarta';
}
date_default_timezone_set($linnmsTimezone);
unset($linnmsTimezone);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'aktivitas.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'api-olt.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cache-prune.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cache-prune.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'device-access.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'device-edit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'driver-registry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'olt-snapshot.php';
require_once LINNMS_VIEW_ROOT . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'format.php';
require_once LINNMS_VIEW_ROOT . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'DataOnuView.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'model-validate.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'olt-vlan.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'permintaan-http.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'routing-url.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'sesi-auth.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'tanggal-waktu.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'teks-validasi.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'telepon.php';
