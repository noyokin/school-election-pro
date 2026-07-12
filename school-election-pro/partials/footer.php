</main>
<footer class="site-footer">
    <div class="container footer-row">
        <span>© <?= date('Y') ?> <?= e(setting('school_name', APP_NAME)) ?></span>
        <span>Версия <?= e(APP_VERSION) ?> · Один ученик — один анонимный голос</span>
    </div>
</footer>
<script src="<?= $prefix ?>assets/vendor/qrcode-local.js" referrerpolicy="no-referrer"></script>
<script src="<?= $prefix ?>assets/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
