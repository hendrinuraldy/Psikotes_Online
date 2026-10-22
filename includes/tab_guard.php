<?php if (isLoggedIn()): ?>
<script>
(function () {
    <?php if (!empty($_SESSION['baru_login'])): unset($_SESSION['baru_login']); ?>
    // Login baru berhasil -> tandai tab ini sebagai tab yang sah
    try { sessionStorage.setItem('tab_ok', '1'); } catch (e) {}
    <?php endif; ?>

    var ok = false;
    try { ok = !!sessionStorage.getItem('tab_ok'); } catch (e) {}

    if (!ok) {
        // Tab baru / tab lama sudah ditutup -> paksa logout
        window.location.replace('<?= baseUrl('logout.php') ?>');
    }
})();
</script>
<?php endif; ?>