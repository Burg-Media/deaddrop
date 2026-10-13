<?php
declare(strict_types=1);
?>
</main>

<?php if (empty($terminalPage)): ?>
<footer class="site-footer">
    <div class="container">
        <small><?= e(APP_NAME) ?> <?= e(APP_VERSION) ?>: versleuteld in je browser, gelezen en weg. <a href="<?= APP_URL ?>/verify.php">Controleer deze node</a> · <a href="<?= e(APP_SOURCE_URL) ?>" rel="noopener">Broncode (AGPL-3.0)</a></small>
    </div>
</footer>
<?php endif; ?>
</body>
</html>
