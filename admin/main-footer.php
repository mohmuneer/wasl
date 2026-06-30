<?php
/* main-footer.php — تذييل ديناميكي من sys_settings */
if (!isset($pdo)) return;
$_ft = $pdo->query("SELECT system_name, system_name_en, address, footer_tagline FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$_ftName    = htmlspecialchars($_ft['system_name']    ?? 'Ultimate Solutions CRM');
$_ftNameEn  = htmlspecialchars($_ft['system_name_en'] ?? 'Ultimate Solutions CRM');
$_ftTagline = htmlspecialchars($_ft['footer_tagline'] ?? 'نظام إدارة البلاغات الداخلية');
$_ftYear    = date('Y');
?>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div>
        <i class="fas fa-copyright" style="margin-left:4px;opacity:.7;"></i>
        <strong><?= $_ftYear ?></strong>
        <a href="#" style="font-weight:700;"><?= $_ftName ?></a>
        &mdash; <?= $_ftTagline ?>
    </div>
    <div style="font-size:.78rem;opacity:.8">
        <i class="fas fa-code" style="margin-left:4px;"></i>
        <?= $_ftNameEn ?>
    </div>
</div>
