        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
setInterval(function () {
    fetch('<?= BASE_URL ?>/peserta/ping.php', { cache: 'no-store' }).catch(function () {});
}, 30000);
</script>
</body>
</html>