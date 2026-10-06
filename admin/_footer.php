        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    <?php if (function_exists('supabase_frontend_config_tag')): ?>
        <?= supabase_frontend_config_tag() ?>
    <?php endif; ?>
    <script src="assets/admin-ui.js"></script>
</body>
</html>
