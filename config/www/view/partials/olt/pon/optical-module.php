            <section class="olt-pon-modal-section">
                <h3><i class="bi bi-activity" aria-hidden="true"></i> Optical Module SFP</h3>
                <dl class="olt-optical-details">
                    <?php foreach ($oltOpticalRows as $oltOpticalRow): ?>
                        <div><dt><?= h($oltOpticalRow['label']) ?></dt><dd class="<?= h($oltOpticalRow['class']) ?>"><?= h($oltOpticalRow['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>
