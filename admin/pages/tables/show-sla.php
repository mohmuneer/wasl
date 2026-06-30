<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . "/../../../config/db.php";
require __DIR__ . '/../forms/functions.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/show-sla.php";

if (!$current_user_id) {
    die("خطأ: يجب تسجيل الدخول أولاً");
}

try {
    $userSql = "SELECT full_name FROM sys_users WHERE id = ?";
    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute([$current_user_id]);
    $current_user_name = $userStmt->fetchColumn() ?? "مستخدم غير معروف";

    $menuSql = "SELECT id FROM sys_menu WHERE link = ?";
    $menuStmt = $pdo->prepare($menuSql);
    $menuStmt->execute([$page_path]);
    $current_page_id = $menuStmt->fetchColumn() ?? 0;

    $can_add = 0; $can_edit = 0; $can_delete = 0;
    if ($current_page_id > 0) {
        $accessSql = "SELECT can_add, can_edit, can_delete FROM user_menu_access WHERE user_id = ? AND menu_id = ?";
        $accessStmt = $pdo->prepare($accessSql);
        $accessStmt->execute([$current_user_id, $current_page_id]);
        $permissions = $accessStmt->fetch(PDO::FETCH_ASSOC);
        $can_add = $permissions['can_add'] ?? 0;
        $can_edit = $permissions['can_edit'] ?? 0;
        $can_delete = $permissions['can_delete'] ?? 0;
    }

    // Handle toggle active
    if (isset($_GET['toggle_id'])) {
        $tid = (int)$_GET['toggle_id'];
        $cur = $pdo->prepare("SELECT is_active FROM sla_rules WHERE id = ?");
        $cur->execute([$tid]);
        $val = $cur->fetchColumn();
        $new = $val ? 0 : 1;
        $pdo->prepare("UPDATE sla_rules SET is_active = ? WHERE id = ?")->execute([$new, $tid]);
        log_action($pdo, 'update', 'قاعدة SLA', $tid, ['is_active' => $val], ['is_active' => $new]);
        header("Location: show-sla.php");
        exit;
    }

    // Handle delete
    if (isset($_GET['delete_id'])) {
        $did = (int)$_GET['delete_id'];
        $pdo->prepare("DELETE FROM sla_rules WHERE id = ?")->execute([$did]);
        log_action($pdo, 'delete', 'قاعدة SLA', $did, [], ['id' => $did]);
        header("Location: show-sla.php");
        exit;
    }

    // Handle add
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sla'])) {
        $name = trim($_POST['rule_name']);
        $priority = $_POST['priority'];
        $response_hours = $_POST['response_hours'];
        $resolution_hours = $_POST['resolution_hours'];
        $applies_to = trim($_POST['applies_to_type'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO sla_rules (rule_name, priority, response_hours, resolution_hours, applies_to_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $priority, $response_hours, $resolution_hours, $applies_to ?: null]);
        $new_id = $pdo->lastInsertId();
        log_action($pdo, 'create', 'قاعدة SLA', $new_id, [], [
            'rule_name' => $name, 'priority' => $priority,
            'response_hours' => $response_hours, 'resolution_hours' => $resolution_hours
        ]);
        header("Location: show-sla.php");
        exit;
    }

    // Handle edit
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_sla'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['rule_name']);
        $priority = $_POST['priority'];
        $response_hours = $_POST['response_hours'];
        $resolution_hours = $_POST['resolution_hours'];
        $applies_to = trim($_POST['applies_to_type'] ?? '');

        $stmt = $pdo->prepare("UPDATE sla_rules SET rule_name=?, priority=?, response_hours=?, resolution_hours=?, applies_to_type=? WHERE id=?");
        $stmt->execute([$name, $priority, $response_hours, $resolution_hours, $applies_to ?: null, $id]);
        log_action($pdo, 'update', 'قاعدة SLA', $id, [], [
            'rule_name' => $name, 'priority' => $priority,
            'response_hours' => $response_hours, 'resolution_hours' => $resolution_hours
        ]);
        header("Location: show-sla.php");
        exit;
    }

    $rules = $pdo->query("SELECT * FROM sla_rules ORDER BY FIELD(priority,'Urgent','High','Medium','Low'), id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $settings = $pdo->query("SELECT * FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $company_name = $settings['system_name'] ?? 'اسم الشركة';
    $company_logo = $settings['system_logo'] ?? 'default-logo.png';
    $company_address = $settings['address'] ?? 'العنوان';
    $base_assets_url = rtrim(dirname($_SERVER['SCRIPT_NAME'], 4), '/');

    $logo_path_internal = __DIR__ . '/../../dist/img/' . $company_logo;
    $logo_data_uri = '';
    if (file_exists($logo_path_internal)) {
        $logo_data = file_get_contents($logo_path_internal);
        $logo_base64 = base64_encode($logo_data);
        $logo_data_uri = 'data:image/png;base64,' . $logo_base64;
    }

} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// Fetch a single rule for edit modal
$edit_rule = null;
if (isset($_GET['edit_id'])) {
    $s = $pdo->prepare("SELECT * FROM sla_rules WHERE id = ?");
    $s->execute([(int)$_GET['edit_id']]);
    $edit_rule = $s->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="utf-8">
    <title>إدارة SLA</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="../../dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">

    <style>
        .card-ticket {
            border: none;
            border-top: 4px solid var(--uni-primary);
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
            border-radius: 10px;
        }
        .card-ticket .card-header {
            background: transparent;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .card-ticket .card-body { padding: 20px; }
        .filter-inline select { border-radius: 8px; border: 1px solid #ced4da; padding: 4px 10px; font-size: .85rem; }
        .filter-inline select:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        #slaTable th { white-space: nowrap; vertical-align: middle; text-align: center; background: #f8f9fa; border-bottom: 2px solid var(--uni-primary) !important; }
        #slaTable td { vertical-align: middle; text-align: center; }
        .dataTables_filter input { border-radius: 20px; padding: 5px 15px; border: 1px solid #ced4da; }
        .dataTables_filter input:focus { border-color: var(--uni-primary); box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        div.dataTables_wrapper div.dataTables_length select { border-radius: 6px; }
        div.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 6px; }
        .badge-pill-custom { padding: 6px 14px; font-weight: 600; border-radius: 20px; font-size: .8rem; }
        .btn-action { width: 34px; height: 34px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
        .btn-group-action { display: flex; gap: 4px; justify-content: center; flex-wrap: nowrap; }
    </style>
</head>

<body class="hold-transition layout-fixed">
    <div class="wrapper">
        <?php include(__DIR__ . '/../../main-header.php'); ?>
        <?php include(__DIR__ . '/../../main-sidebar.php'); ?>

        <div class="content-wrapper">
            <section class="content-header">
                <div class="container-fluid">
                    <div class="uni-header">
                        <h4><i class="fas fa-clock ml-2"></i> إدارة مستويات الخدمة (SLA)</h4>
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                            <li class="breadcrumb-item active">SLA</li>
                        </ol>
                    </div>
                </div>
            </section>

            <section class="content">
                <div class="container-fluid">
                    <div class="card card-ticket">
                        <div class="card-header">
                            <div class="d-flex align-items-center" style="gap:8px;flex-wrap:wrap;">
                                <select id="filter-priority" class="form-control-sm filter-inline">
                                    <option value="">الكل — الأولوية</option>
                                    <option value="عاجل">عاجل</option>
                                    <option value="عالي">عالي</option>
                                    <option value="متوسط">متوسط</option>
                                    <option value="منخفض">منخفض</option>
                                </select>
                                <select id="filter-active" class="form-control-sm filter-inline">
                                    <option value="">الكل — الحالة</option>
                                    <option value="نشط">نشط</option>
                                    <option value="متوقف">متوقف</option>
                                </select>
                                <button id="resetFilters" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> إعادة تعيين</button>
                            </div>
                            <?php if ($can_add == 1): ?>
                                <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addModal">
                                    <i class="fas fa-plus"></i> إضافة قاعدة
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="slaTable" class="table table-hover table-bordered">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>اسم القاعدة</th>
                                            <th>الأولوية</th>
                                            <th>الرد (ساعة)</th>
                                            <th>الإغلاق (ساعة)</th>
                                            <th>نوع العميل</th>
                                            <th>الحالة</th>
                                            <th>العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i = 1; foreach ($rules as $r): ?>
                                        <tr>
                                            <td><?= $i++; ?></td>
                                            <td><strong><?= htmlspecialchars($r['rule_name']) ?></strong></td>
                                            <td>
                                                <span class="badge badge-pill-custom badge-<?= $r['priority'] == 'Urgent' ? 'danger' : ($r['priority'] == 'High' ? 'warning' : ($r['priority'] == 'Medium' ? 'info' : 'secondary')) ?>">
                                                    <?= $r['priority'] == 'Urgent' ? 'عاجل' : ($r['priority'] == 'High' ? 'عالي' : ($r['priority'] == 'Medium' ? 'متوسط' : 'منخفض')) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($r['response_hours']) ?></td>
                                            <td><?= htmlspecialchars($r['resolution_hours']) ?></td>
                                            <td><?= htmlspecialchars($r['applies_to_type'] ?? '-') ?></td>
                                            <td>
                                                <a href="?toggle_id=<?= $r['id'] ?>" class="btn btn-sm <?= $r['is_active'] ? 'btn-success' : 'btn-secondary' ?>">
                                                    <?= $r['is_active'] ? 'نشط' : 'متوقف' ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="btn-group-action">
                                                    <?php if ($can_edit == 1): ?>
                                                        <a href="?edit_id=<?= $r['id'] ?>" class="btn btn-warning btn-sm btn-action" title="تعديل" data-toggle="modal" data-target="#editModal<?= $r['id'] ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_delete == 1): ?>
                                                        <a href="?delete_id=<?= $r['id'] ?>" class="btn btn-danger btn-sm btn-action" title="حذف" onclick="return confirm('هل أنت متأكد من حذف قاعدة SLA هذه؟')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($rules)): ?>
                                        <tr><td colspan="8" class="text-center text-muted">لا توجد قواعد SLA</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">إضافة قاعدة SLA جديدة</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="add_sla" value="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>اسم القاعدة</label>
                                    <input type="text" name="rule_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>الأولوية</label>
                                    <select name="priority" class="form-control" required>
                                        <option value="Urgent">عاجل</option>
                                        <option value="High">عالي</option>
                                        <option value="Medium">متوسط</option>
                                        <option value="Low">منخفض</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>مدة الرد (ساعات)</label>
                                    <input type="number" step="0.5" name="response_hours" class="form-control" value="2" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>مدة الإغلاق (ساعات)</label>
                                    <input type="number" step="0.5" name="resolution_hours" class="form-control" value="8" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>نوع العميل (اختياري)</label>
                            <input type="text" name="applies_to_type" class="form-control" placeholder="مثال: VIP، حكومي ...">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-info">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modals -->
    <?php foreach ($rules as $r): ?>
    <div class="modal fade" id="editModal<?= $r['id'] ?>" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">تعديل قاعدة SLA</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="edit_sla" value="1">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>اسم القاعدة</label>
                                    <input type="text" name="rule_name" class="form-control" value="<?= htmlspecialchars($r['rule_name']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>الأولوية</label>
                                    <select name="priority" class="form-control" required>
                                        <option value="Urgent" <?= $r['priority'] == 'Urgent' ? 'selected' : '' ?>>عاجل</option>
                                        <option value="High" <?= $r['priority'] == 'High' ? 'selected' : '' ?>>عالي</option>
                                        <option value="Medium" <?= $r['priority'] == 'Medium' ? 'selected' : '' ?>>متوسط</option>
                                        <option value="Low" <?= $r['priority'] == 'Low' ? 'selected' : '' ?>>منخفض</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>مدة الرد (ساعات)</label>
                                    <input type="number" step="0.5" name="response_hours" class="form-control" value="<?= $r['response_hours'] ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>مدة الإغلاق (ساعات)</label>
                                    <input type="number" step="0.5" name="resolution_hours" class="form-control" value="<?= $r['resolution_hours'] ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>نوع العميل (اختياري)</label>
                            <input type="text" name="applies_to_type" class="form-control" value="<?= htmlspecialchars($r['applies_to_type'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-warning">تحديث</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../../dist/js/adminlte.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.colVis.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="../../dist/js/pdfmake-arabic.js"></script>

    <script>
    var systemName = "<?= $company_name ?>";
    var systemAddress = "<?= $company_address ?>";
    var logoPath = "<?= $base_assets_url ?>/admin/dist/img/<?= rawurlencode($company_logo) ?>";
    var logoDataUri = "<?= $logo_data_uri ?>";
    var sessionUserName = "<?= $current_user_name ?>";

    $(document).ready(function() {
        const currentDate = new Date().toLocaleDateString('ar-EG');
        var table = $('#slaTable').DataTable({
            "dom": "<'row mb-3'<'col-md-6'B><'col-md-6 text-left'f>>" +
                   "<'row'<'col-12'tr>>" +
                   "<'row mt-3'<'col-md-5'i><'col-md-7'p>>",
            "buttons": [
                {
                    extend: 'print',
                    text: '<i class="fas fa-print ml-1"></i> طباعة',
                    className: 'btn btn-outline-primary btn-sm ml-1',
                    exportOptions: { columns: ':visible' },
                    customize: function (win) {
                        $(win.document.body).css({'direction': 'rtl', 'text-align': 'right'});
                        $(win.document.body).prepend(`
                            <div style="display: table; width: 100%; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px;">
                                <div style="display: table-cell; width: 33.33%; vertical-align: middle;">
                                    <h4 style="margin:0;">${systemName}</h4>
                                    <p>${systemAddress}</p>
                                </div>
                                <div style="display: table-cell; width: 33.33%; text-align: center; vertical-align: middle;">
                                    <img src="${logoPath}" style="height: 80px;">
                                </div>
                                <div style="display: table-cell; width: 33.33%; text-align: left; vertical-align: middle;">
                                    <h3 style="margin:0;">تقرير SLA</h3>
                                    <p>تاريخ الطباعة: ${currentDate}</p>
                                </div>
                            </div>
                        `);
                    }
                },
                {
                    extend: 'pdf',
                    text: 'PDF',
                    className: 'btn btn-outline-danger btn-sm ml-1',
                    exportOptions: { columns: ':visible' },
                    customize: function(doc) {
                        doc.defaultStyle.font = 'tahoma';
                        var d = new Date().toLocaleDateString('ar-EG');
                        doc.content.unshift({
                            stack: [
                                { image: logoDataUri, width: 70, alignment: 'center', margin: [0, 0, 0, 8] },
                                { text: systemName, alignment: 'center', fontSize: 20, bold: true, color: '#0d4a1c', margin: [0, 0, 0, 4] },
                                { text: 'تقرير SLA', alignment: 'center', fontSize: 14, color: '#555', margin: [0, 0, 0, 4] },
                                { text: 'تاريخ التقرير: ' + d, alignment: 'center', fontSize: 10, color: '#999', margin: [0, 0, 0, 12] },
                                { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 515, y2: 0, lineWidth: 2.5, color: '#0d4a1c' }] }
                            ],
                            margin: [0, 0, 0, 15]
                        });
                        doc.styles.tableHeader = {
                            bold: true, fontSize: 10, color: 'white',
                            fillColor: '#0d4a1c', alignment: 'center'
                        };
                        doc.styles.tableBodyOdd = { fillColor: '#f5f7fa' };
                        doc.content[0].layout = {
                            hLineWidth: function(i, node) {
                                if (i === 0 || i === node.table.body.length) return 2;
                                return 0.5;
                            },
                            vLineWidth: function() { return 0; },
                            hLineColor: function(i) {
                                if (i === 0 || i === 1) return '#0d4a1c';
                                return '#e0e4ea';
                            },
                            paddingTop: function() { return 5; },
                            paddingBottom: function() { return 5; },
                            paddingLeft: function() { return 8; },
                            paddingRight: function() { return 8; }
                        };
                    }
                },
                { extend: 'excelHtml5', text: '<i class="fas fa-file-excel ml-1"></i> إكسيل', className: 'btn btn-outline-success btn-sm ml-1', exportOptions: { columns: ':visible' } },
                { extend: 'colvis', text: '<i class="fas fa-columns ml-1"></i> أعمدة', className: 'btn btn-outline-secondary btn-sm' }
            ],
            "language": {
                search: "بحث:",
                lengthMenu: "عرض _MENU_ سجل",
                info: "عرض _START_ إلى _END_ من _TOTAL_ سجل",
                paginate: {
                    first: "الأول",
                    last: "الأخير",
                    next: "التالي",
                    previous: "السابق"
                }
            }
        });

        $('#filter-priority').on('change', function () { table.column(2).search(this.value).draw(); });
        $('#filter-active').on('change', function () { table.column(6).search(this.value).draw(); });
        $('#resetFilters').on('click', function () {
            $('#filter-priority').val('');
            $('#filter-active').val('');
            table.column(2).search('').draw();
            table.column(6).search('').draw();
        });

        // Auto-show edit modal if edit_id in URL
        <?php if (isset($_GET['edit_id'])): ?>
        $('#editModal<?= (int)$_GET['edit_id'] ?>').modal('show');
        <?php endif; ?>
    });
    </script>
<?php include __DIR__ . '/../../../admin/print_header.php'; ?>
</body>
</html>
