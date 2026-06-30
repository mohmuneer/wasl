<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$stmt = $pdo->query("SELECT * FROM sys_theme LIMIT 1");
$visuals = $stmt->fetch();
if (!$visuals) $visuals = ['id'=>1,'system_font'=>'Cairo','sidebar_color'=>'#343a40','header_color'=>'#ffffff'];

// ── إحصائيات قاعدة البيانات ──
try {
    $tables_count = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $db_name = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $db_size_row = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch();
    $db_size = $db_size_row['size_mb'] ?? '—';
} catch (Exception $e) {
    $tables_count = '—'; $db_name = '—'; $db_size = '—';
}

// ── تصدير القاعدة ──
if (isset($_POST['export_db'])) {
    $fileName = 'backup_' . $db_name . '_' . date('Y-m-d_H-i-s') . '.sql';
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) $tables[] = $row[0];

    $sqlScript = "-- ========================================================\n";
    $sqlScript .= "-- نسخة احتياطية: $db_name\n";
    $sqlScript .= "-- التاريخ: " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- ========================================================\n\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n";

    foreach ($tables as $table) {
        $sqlScript .= "-- ── جدول: $table ──\n";
        $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";
        $q = $pdo->query("SHOW CREATE TABLE `$table`");
        $row = $q->fetch(PDO::FETCH_NUM);
        $sqlScript .= $row[1] . ";\n\n";
        $q2 = $pdo->query("SELECT * FROM `$table`");
        $cols = $q2->columnCount();
        while ($row2 = $q2->fetch(PDO::FETCH_NUM)) {
            $sqlScript .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < $cols; $j++) {
                if (isset($row2[$j])) $sqlScript .= '"' . str_replace("\n","\\n",addslashes($row2[$j])) . '"';
                else $sqlScript .= 'NULL';
                if ($j < $cols-1) $sqlScript .= ',';
            }
            $sqlScript .= ");\n";
        }
        $sqlScript .= "\n";
    }
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;\n";

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($sqlScript));
    echo $sqlScript;
    exit;
}

// ── استيراد القاعدة ──
$import_success = $import_error = null;
if (isset($_POST['import_db'])) {
    $file = $_FILES['sql_file']['tmp_name'] ?? '';
    if (!empty($file)) {
        try {
            $sql = file_get_contents($file);
            if ($sql === false) throw new Exception("تعذر قراءة الملف.");
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;");
            $import_success = "تمت استعادة قاعدة البيانات بنجاح!";
        } catch (Exception $e) {
            $import_error = "فشل الاستيراد: " . $e->getMessage();
        }
    } else {
        $import_error = "يرجى اختيار ملف SQL أولاً.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>النسخ الاحتياطي</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar{display:none}
body{overflow-x:hidden;scrollbar-width:none;direction:rtl}

/* ══ بطاقات الإحصاء ══ */
.stat-card {
    background:#fff;
    border-radius:14px;
    padding:18px 20px;
    display:flex;align-items:center;gap:16px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    border:1px solid #f0f2f5;
    height:100%;
}
.stat-card .stat-icon {
    width:52px;height:52px;flex-shrink:0;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.3rem;color:#fff;
}
.stat-card .stat-value { font-size:1.6rem;font-weight:800;line-height:1; }
.stat-card .stat-label { font-size:.78rem;color:#888;margin-top:2px; }

/* ══ بطاقة النسخ الاحتياطي ══ */
.backup-card {
    background:#fff;
    border-radius:16px;
    box-shadow:0 4px 24px rgba(0,0,0,.07);
    overflow:hidden;
    height:100%;
    display:flex;flex-direction:column;
    border:1px solid #f0f2f5;
    transition:.25s;
}
.backup-card:hover { transform:translateY(-3px);box-shadow:0 8px 32px rgba(0,0,0,.1); }

.backup-card .bc-head {
    padding:24px 24px 16px;
    display:flex;align-items:center;gap:14px;
}
.backup-card .bc-head .bc-icon {
    width:56px;height:56px;flex-shrink:0;
    border-radius:14px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.5rem;color:#fff;
}
.backup-card .bc-head h5 { margin:0;font-size:1.05rem;font-weight:700; }
.backup-card .bc-head small { color:#888;font-size:.8rem; }
.backup-card .bc-body { padding:0 24px 20px;flex:1; }
.backup-card .bc-footer { padding:16px 24px;border-top:1px solid #f0f2f5;background:#fafbfc; }

/* ══ upload zone ══ */
.upload-zone {
    border:2px dashed #d0d7e2;
    border-radius:12px;
    padding:20px;
    text-align:center;
    cursor:pointer;
    transition:.2s;
    background:#f8f9fc;
    position:relative;
}
.upload-zone:hover { border-color:var(--crm-primary,#1a5276);background:#f0f4f8; }
.upload-zone input[type=file] {
    position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;
}
.upload-zone .uz-icon { font-size:2rem;color:#b0b8c8;margin-bottom:8px; }
.upload-zone p { margin:0;color:#888;font-size:.83rem; }

/* ══ warning badge ══ */
.danger-badge {
    background:#fff5f5;
    border:1.5px solid #fecdd3;
    border-radius:10px;
    padding:12px 16px;
    display:flex;align-items:flex-start;gap:10px;
    margin-bottom:16px;
}
.danger-badge i { color:#dc2626;margin-top:2px;flex-shrink:0; }
.danger-badge p { margin:0;font-size:.83rem;color:#7f1d1d;line-height:1.5; }

/* ══ info badge ══ */
.info-badge {
    background:#f0f9ff;
    border:1.5px solid #bae6fd;
    border-radius:10px;
    padding:12px 16px;
    display:flex;align-items:flex-start;gap:10px;
    margin-bottom:16px;
}
.info-badge i { color:#0369a1;margin-top:2px;flex-shrink:0; }
.info-badge p { margin:0;font-size:.83rem;color:#0c4a6e;line-height:1.5; }

/* ══ btn export ══ */
.btn-export {
    background:linear-gradient(135deg,#1a5276,#2980b9);
    color:#fff;border:none;border-radius:10px;
    padding:12px 24px;font-weight:700;font-size:.9rem;
    width:100%;transition:.2s;
    display:flex;align-items:center;justify-content:center;gap:10px;
}
.btn-export:hover { opacity:.9;transform:translateY(-1px);color:#fff; }

/* ══ btn import ══ */
.btn-import {
    background:linear-gradient(135deg,#991b1b,#dc2626);
    color:#fff;border:none;border-radius:10px;
    padding:12px 24px;font-weight:700;font-size:.9rem;
    width:100%;transition:.2s;
    display:flex;align-items:center;justify-content:center;gap:10px;
}
.btn-import:hover { opacity:.9;transform:translateY(-1px);color:#fff; }
.btn-import:disabled { opacity:.5;transform:none; }

/* ══ steps timeline ══ */
.steps { padding:0;margin:0;list-style:none; }
.steps li {
    display:flex;align-items:flex-start;gap:12px;
    padding:10px 0;
    border-bottom:1px dashed #e8ecf0;
    font-size:.83rem;color:#555;
}
.steps li:last-child { border-bottom:none; }
.steps li .step-num {
    width:22px;height:22px;flex-shrink:0;
    background:var(--crm-page-bar-from,#1a5276);
    border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:.7rem;font-weight:700;
    margin-top:1px;
}

/* ── alerts ── */
.custom-alert {
    border-radius:12px;padding:14px 18px;
    display:flex;align-items:center;gap:12px;
    margin-bottom:20px;font-size:.88rem;
}
.custom-alert-success { background:#d1fae5;border:1px solid #6ee7b7;color:#065f46; }
.custom-alert-danger  { background:#fee2e2;border:1px solid #fca5a5;color:#7f1d1d; }
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ── الترويسة ── -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-database ml-2"></i> النسخ الاحتياطي وإدارة البيانات</h4>
                    <small>تصدير واستيراد وحماية قاعدة البيانات</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">النسخ الاحتياطي</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">

            <!-- ── رسائل النجاح/الخطأ ── -->
            <?php if ($import_success): ?>
            <div class="custom-alert custom-alert-success">
                <i class="fas fa-check-circle fa-lg"></i>
                <span><?= htmlspecialchars($import_success) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($import_error): ?>
            <div class="custom-alert custom-alert-danger">
                <i class="fas fa-exclamation-circle fa-lg"></i>
                <span><?= htmlspecialchars($import_error) ?></span>
            </div>
            <?php endif; ?>

            <!-- ══ إحصاءات قاعدة البيانات ══ -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#1a5276,#2980b9)">
                            <i class="fas fa-database"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= htmlspecialchars($db_name) ?></div>
                            <div class="stat-label">اسم قاعدة البيانات</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#065f46,#059669)">
                            <i class="fas fa-table"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $tables_count ?></div>
                            <div class="stat-label">عدد الجداول</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
                            <i class="fas fa-hdd"></i>
                        </div>
                        <div>
                            <div class="stat-value"><?= $db_size ?> <span style="font-size:1rem;font-weight:500">MB</span></div>
                            <div class="stat-label">حجم قاعدة البيانات</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ بطاقتا التصدير والاستيراد ══ -->
            <div class="row">

                <!-- ── التصدير ── -->
                <div class="col-lg-6 mb-4">
                    <div class="backup-card">
                        <div class="bc-head">
                            <div class="bc-icon" style="background:linear-gradient(135deg,#1a5276,#2980b9)">
                                <i class="fas fa-cloud-download-alt"></i>
                            </div>
                            <div>
                                <h5>تصدير نسخة احتياطية</h5>
                                <small>تنزيل قاعدة البيانات كاملةً بصيغة SQL</small>
                            </div>
                        </div>
                        <div class="bc-body">
                            <div class="info-badge">
                                <i class="fas fa-info-circle"></i>
                                <p>يتم تصدير جميع الجداول والبيانات بصيغة SQL مع تعليمات DROP/CREATE وINSERT، تصلح للاستعادة على أي خادم MySQL.</p>
                            </div>
                            <ul class="steps">
                                <li><div class="step-num">1</div>اضغط على زر إنشاء النسخة</li>
                                <li><div class="step-num">2</div>سيبدأ تنزيل ملف <code>.sql</code> تلقائياً</li>
                                <li><div class="step-num">3</div>احفظ الملف في مكان آمن</li>
                            </ul>
                        </div>
                        <div class="bc-footer">
                            <form method="POST" target="_blank" id="exportForm">
                                <button type="submit" name="export_db" id="exportBtn" class="btn-export">
                                    <i class="fas fa-download"></i>
                                    <span>إنشاء نسخة احتياطية الآن</span>
                                </button>
                            </form>
                            <p class="text-muted text-center mt-2 mb-0" style="font-size:.75rem;">
                                <i class="fas fa-clock ml-1"></i>
                                آخر تصدير: <?= date('Y-m-d H:i') ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ── الاستيراد ── -->
                <div class="col-lg-6 mb-4">
                    <div class="backup-card">
                        <div class="bc-head">
                            <div class="bc-icon" style="background:linear-gradient(135deg,#991b1b,#dc2626)">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <div>
                                <h5>استعادة نسخة احتياطية</h5>
                                <small>رفع ملف SQL لاستعادة البيانات</small>
                            </div>
                        </div>
                        <div class="bc-body">
                            <div class="danger-badge">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                                <p><strong>تحذير مهم:</strong> سيتم <strong>حذف جميع البيانات الحالية</strong> واستبدالها بالكامل بمحتوى الملف المرفوع. هذا الإجراء <strong>لا يمكن التراجع عنه</strong>. تأكد من وجود نسخة احتياطية حديثة قبل المتابعة.</p>
                            </div>
                            <form method="POST" enctype="multipart/form-data" id="importForm">
                                <div class="upload-zone" id="dropZone">
                                    <input type="file" name="sql_file" id="sqlFile" accept=".sql">
                                    <div class="uz-icon"><i class="fas fa-file-code"></i></div>
                                    <p id="uploadLabel">اسحب ملف SQL هنا أو اضغط للاختيار<br><small class="text-muted">يقبل ملفات .sql فقط</small></p>
                                </div>
                            </form>
                        </div>
                        <div class="bc-footer">
                            <button type="button" class="btn-import" id="importBtn" disabled onclick="confirmImport()">
                                <i class="fas fa-upload"></i>
                                <span>استعادة قاعدة البيانات</span>
                            </button>
                            <p class="text-muted text-center mt-2 mb-0" style="font-size:.75rem;">
                                <i class="fas fa-shield-alt ml-1"></i>
                                يتطلب تأكيداً مزدوجاً قبل التنفيذ
                            </p>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ══ تعليمات الأمان ══ -->
            <div class="appearance-section" style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.06);overflow:hidden;">
                <div style="padding:14px 20px;background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));border-radius:14px 14px 0 0;display:flex;align-items:center;gap:12px;">
                    <div style="width:36px;height:36px;background:rgba(255,255,255,.2);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h5 style="margin:0;color:#fff;font-weight:700;font-size:.95rem;">أفضل ممارسات النسخ الاحتياطي</h5>
                </div>
                <div style="padding:22px;">
                    <div class="row">
                        <div class="col-md-4">
                            <div style="text-align:center;padding:14px;">
                                <div style="width:50px;height:50px;border-radius:12px;background:#dbeafe;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.3rem;color:#1d4ed8">
                                    <i class="fas fa-calendar-check"></i>
                                </div>
                                <h6 style="font-weight:700;color:#333">جدولة دورية</h6>
                                <p class="text-muted mb-0" style="font-size:.82rem">يُنصح بعمل نسخة احتياطية أسبوعياً على الأقل، وقبل أي تحديثات كبيرة للنظام.</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="text-align:center;padding:14px;">
                                <div style="width:50px;height:50px;border-radius:12px;background:#d1fae5;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.3rem;color:#065f46">
                                    <i class="fas fa-folder-open"></i>
                                </div>
                                <h6 style="font-weight:700;color:#333">حفظ آمن</h6>
                                <p class="text-muted mb-0" style="font-size:.82rem">احفظ الملفات في موقع منفصل عن الخادم مثل التخزين السحابي أو قرص خارجي.</p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div style="text-align:center;padding:14px;">
                                <div style="width:50px;height:50px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:1.3rem;color:#92400e">
                                    <i class="fas fa-vial"></i>
                                </div>
                                <h6 style="font-weight:700;color:#333">اختبار الاستعادة</h6>
                                <p class="text-muted mb-0" style="font-size:.82rem">تأكد دورياً من صلاحية ملفات النسخ الاحتياطية عن طريق اختبار الاستعادة في بيئة تجريبية.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>
<footer class="main-footer"><?php include('../../main-footer.php') ?></footer>
</div>

<script src="../../plugins/jquery/jquery.min.js"></script>
<script src="../../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../../dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ── تفعيل زر الاستيراد عند اختيار ملف ──
document.getElementById('sqlFile').addEventListener('change', function() {
    const name = this.files[0]?.name || '';
    const btn = document.getElementById('importBtn');
    if (name.endsWith('.sql')) {
        document.getElementById('uploadLabel').innerHTML =
            '<strong style="color:#1a5276">' + name + '</strong><br><small class="text-success">ملف SQL صالح — جاهز للاستعادة</small>';
        btn.disabled = false;
    } else if (name) {
        document.getElementById('uploadLabel').innerHTML =
            '<strong style="color:#dc2626">' + name + '</strong><br><small class="text-danger">الملف يجب أن يكون بصيغة .sql</small>';
        btn.disabled = true;
    }
});

// ── تأكيد مزدوج قبل الاستيراد ──
function confirmImport() {
    Swal.fire({
        title: 'تأكيد الاستعادة',
        html: '<p style="font-size:15px">سيتم <strong style="color:#dc2626">حذف جميع البيانات الحالية</strong> واستبدالها بمحتوى الملف المرفوع.</p><p style="font-size:13px;color:#888">هذا الإجراء لا يمكن التراجع عنه.</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-upload ml-1"></i> نعم، استعادة الآن',
        cancelButtonText: 'إلغاء',
        reverseButtons: true
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'تأكيد نهائي',
                html: '<p style="font-size:14px">اكتب <strong>تأكيد</strong> في الحقل أدناه للمتابعة:</p>',
                input: 'text',
                inputPlaceholder: 'اكتب: تأكيد',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'إلغاء',
                confirmButtonText: 'تنفيذ الاستعادة',
                preConfirm: (val) => {
                    if (val !== 'تأكيد') {
                        Swal.showValidationMessage('يجب كتابة كلمة "تأكيد" بالضبط');
                    }
                }
            }).then(r2 => {
                if (r2.isConfirmed) document.getElementById('importForm').submit();
            });
        }
    });
}

// ── رسالة بعد التصدير ──
document.getElementById('exportBtn').addEventListener('click', function() {
    setTimeout(() => {
        Swal.fire({
            title: 'جاري التصدير...',
            text: 'يتم تجهيز ملف قاعدة البيانات وتنزيله.',
            icon: 'info',
            timer: 3000,
            timerProgressBar: true,
            showConfirmButton: false
        });
    }, 500);
});

// ── السحب والإفلات ──
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor='var(--crm-primary,#1a5276)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor='#d0d7e2'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor='#d0d7e2';
    const file = e.dataTransfer.files[0];
    if (file) {
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('sqlFile').files = dt.files;
        document.getElementById('sqlFile').dispatchEvent(new Event('change'));
    }
});
</script>
</body>
</html>
