<?php

declare(strict_types=1);

function json_for_html_script(mixed $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
}

function json_for_html_attribute(mixed $value): string
{
    return h(json_for_html_script($value));
}

function page_asset_manifest(string $page): array
{
    if (preg_match('/^[a-z0-9-]+$/', $page) !== 1) throw new InvalidArgumentException('Nama halaman tidak valid.');
    $path = LINNMS_UI_ROOT . '/assets/pages/' . $page . '/assets.json';
    $contents = file_get_contents($path);
    if ($contents === false) throw new RuntimeException('Aset halaman tidak tersedia.');
    $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) throw new RuntimeException('Daftar aset halaman tidak valid.');
    foreach (['styles', 'scripts'] as $kind) {
        if (!is_array($manifest[$kind] ?? null)) throw new RuntimeException('Daftar aset halaman tidak valid.');
        foreach ($manifest[$kind] as $asset) {
            if (!is_string($asset) || !str_starts_with($asset, 'assets/') || str_contains($asset, '..')
                || !is_file(LINNMS_UI_ROOT . '/' . $asset)) {
                throw new RuntimeException('Aset halaman tidak ditemukan.');
            }
        }
    }
    return $manifest;
}

function nav_active(string $page, string $target): string
{
    return $page === $target ? 'active' : '';
}

function render_flash_toast(array $flashes): void
{
    if ($flashes === []) return;

    $types = ['success', 'error', 'waiting', 'scanning', 'warning', 'info'];

    $notification = null;
    foreach ($flashes as $flash) {
        if (!is_array($flash)) continue;
        $message = trim((string)($flash['message'] ?? ''));
        if ($message === '') continue;
        $type = strtolower(trim((string)($flash['type'] ?? 'info')));
        if (!in_array($type, $types, true)) $type = 'info';
        $notification = [
            'type' => $type,
            'message' => $message,
        ];
    }
    if ($notification === null) return;

    $type = $notification['type'];
    $message = $notification['message'];
    ?>
    <template data-flash-toast data-type="<?= h($type) ?>"><?= h($message) ?></template>
    <?php
}

function pagination_numbers(int $current, int $totalPages, int $edge = 1, int $around = 2): array
{
    $current = max(1, $current);
    $totalPages = max(1, $totalPages);
    if ($totalPages <= 1) return [1];

    $collected = [];
    $collect = static function (int $page) use (&$collected, $totalPages): void {
        if ($page >= 1 && $page <= $totalPages && !in_array($page, $collected, true)) $collected[] = $page;
    };
    for ($page = 1; $page <= $edge + 1; $page++) $collect($page);
    for ($page = $current - max(1, $around); $page <= $current + max(1, $around); $page++) $collect($page);
    for ($page = $totalPages - $edge; $page <= $totalPages; $page++) $collect($page);

    sort($collected);
    $sequence = [];
    $previous = 0;
    foreach ($collected as $page) {
        if ($previous > 0 && $page > $previous + 1) $sequence[] = '...';
        $sequence[] = $page;
        $previous = $page;
    }
    return $sequence;
}
function page_info_top(int $page, int $pages): string
{
    return '' . max(1, $page) . ' dari ' . max(1, $pages);
}
function render_numbered_pagination(array $config): void
{
    $page = max(1, (int)($config['page'] ?? 1));
    $pages = max(1, (int)($config['pages'] ?? 1));
    $url = $config['url'];
    $label = trim((string)($config['label'] ?? 'Halaman'));
    if ($label === '') $label = 'Halaman';
    $gotoName = trim((string)($config['goto'] ?? ''));
    $extraClass = trim((string)($config['class'] ?? ''));
    $total = array_key_exists('total', $config) ? max(0, (int)$config['total']) : null;
    $gotoAttr = static function (int $targetPage) use ($gotoName): string {
        return $gotoName === '' ? '' : ' ' . $gotoName . '="' . $targetPage . '"';
    };
    ?>
    <div class="table-pagination pagination-numbered<?= $extraClass === '' ? '' : ' ' . h($extraClass) ?>" <?= $total === 0 ? 'hidden' : '' ?>>
        <div class="page-controls">
            <?php if ($page > 1): ?><a class="button secondary table-page-button" href="<?= h($url($page - 1)) ?>"<?= $gotoAttr($page - 1) ?> aria-label="<?= h($label) ?> sebelumnya"><i class="bi bi-chevron-left" aria-hidden="true"></i></a><?php else: ?><span class="button secondary table-page-button disabled" aria-hidden="true"><i class="bi bi-chevron-left" aria-hidden="true"></i></span><?php endif; ?>
            <ul class="page-number-list">
                <?php foreach (pagination_numbers($page, $pages) as $number): ?>
                    <?php if ($number === '...'): ?><li class="page-number-ellipsis" aria-hidden="true">&hellip;</li>
                    <?php elseif ((int)$number === $page): ?><li><span class="page-number is-active" aria-current="page"><?= (int)$number ?></span></li>
                    <?php else: ?><li><a class="page-number" href="<?= h($url((int)$number)) ?>"<?= $gotoAttr((int)$number) ?> aria-label="<?= h($label) ?> <?= (int)$number ?>"><?= (int)$number ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php if ($page < $pages): ?><a class="button secondary table-page-button" href="<?= h($url($page + 1)) ?>"<?= $gotoAttr($page + 1) ?> aria-label="<?= h($label) ?> berikutnya"><i class="bi bi-chevron-right" aria-hidden="true"></i></a><?php else: ?><span class="button secondary table-page-button disabled" aria-hidden="true"><i class="bi bi-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
        </div>
    </div>
    <?php
}
