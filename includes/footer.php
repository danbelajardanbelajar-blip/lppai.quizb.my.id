        </div><!-- end content-area -->
        <footer class="footer">
            <div class="footer-left">
                <span>&copy; <?= date('Y') ?> <strong><?= APP_NAME ?></strong> — Lembaga Pengembangan Pendidikan Agama Islam. All rights reserved.</span>
            </div>
            <div class="footer-right">
                <span style="display:inline-flex; align-items:center; gap:6px;">
                    <span style="width:7px; height:7px; border-radius:50%; background:#10b981; display:inline-block; box-shadow:0 0 6px #10b981;"></span>
                    <span>Sistem Informasi Terpadu &bull; v2.0 Premium</span>
                </span>
            </div>
        </footer>
    </div><!-- end main-content -->
</div><!-- end app-wrapper -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/activity-tracker.js?v=<?= file_exists(__DIR__ . '/../assets/js/activity-tracker.js') ? filemtime(__DIR__ . '/../assets/js/activity-tracker.js') : time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js?v=<?= file_exists(__DIR__ . '/../assets/js/app.js') ? filemtime(__DIR__ . '/../assets/js/app.js') : time() ?>"></script>
</body>
</html>
