<?php
$serviceContact = is_array($serviceContact ?? null) ? $serviceContact : [];
$serviceContactLinks = [];

// Nomor telepon dari kontrol panel tidak ditampilkan di UI.
// if (trim((string)($serviceContact['phone'] ?? '')) !== '') $serviceContactLinks[] = ['label' => 'Telepon', 'value' => trim((string)$serviceContact['phone']), 'url' => 'tel:' . preg_replace('/[^0-9+]/', '', (string)$serviceContact['phone']), 'icon' => 'telephone'];
if (trim((string)($serviceContact['email'] ?? '')) !== '') $serviceContactLinks[] = ['label' => 'Email', 'value' => trim((string)$serviceContact['email']), 'url' => 'mailto:' . trim((string)$serviceContact['email']), 'icon' => 'envelope'];
if (trim((string)($serviceContact['github'] ?? '')) !== '') $serviceContactLinks[] = ['label' => 'GitHub', 'value' => trim((string)$serviceContact['github']), 'url' => (str_starts_with((string)$serviceContact['github'], 'http') ? trim((string)$serviceContact['github']) : 'https://github.com/' . trim((string)$serviceContact['github'])), 'icon' => 'github'];
if (trim((string)($serviceContact['web'] ?? '')) !== '') $serviceContactLinks[] = ['label' => 'Web', 'value' => trim((string)$serviceContact['web']), 'url' => (str_starts_with((string)$serviceContact['web'], 'http') ? trim((string)$serviceContact['web']) : 'https://' . trim((string)$serviceContact['web'])), 'icon' => 'globe2'];
?>
    <?php if ($serviceContactLinks !== []): ?>
        <div class="settings-auxiliary profile-service-contact" id="service-contact">

            <div class="profile-contact-links">
                <?php foreach ($serviceContactLinks as $contact): ?>
                    <?php $contactOpensNewTab = preg_match('~^https?://~i', (string)$contact['url']) === 1; ?>
                    <a href="<?= h($contact['url']) ?>" <?= $contactOpensNewTab ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <i class="bi bi-<?= h($contact['icon']) ?>" aria-hidden="true"></i>
                        <span class="profile-contact-value"><?= h($contact['value']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
