<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/functions.php';

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) die('يجب تسجيل الدخول أولاً');

$page_path = 'pages/forms/ai-training.php';
$menuStmt  = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_training') {
        $question  = trim($_POST['question'] ?? '');
        $sql_query = trim($_POST['sql_query'] ?? '');
        $response  = trim($_POST['response'] ?? '');
        $intent    = trim($_POST['intent'] ?? '');
        if ($question && $sql_query && $response && $intent) {
            $stmt = $pdo->prepare("INSERT INTO ai_training (question, intent, sql_query, expected_response) VALUES (?,?,?,?)");
            $stmt->execute([$question, $intent, $sql_query, $response]);
            log_action($pdo, 'create', 'تدريب', $pdo->lastInsertId(), [], ['question'=>$question,'intent'=>$intent]);
            $success = 'تم إضافة تدريب جديد بنجاح';
        } else {
            $error = 'يرجى تعبئة جميع الحقول المطلوبة';
        }
    }

    if ($_POST['action'] === 'add_question') {
        $question = trim($_POST['question'] ?? '');
        $intent   = trim($_POST['intent'] ?? '');
        $category = trim($_POST['category'] ?? '');
        if ($question && $intent && $category) {
            $stmt = $pdo->prepare("INSERT INTO ai_questions (question, intent, category) VALUES (?,?,?)");
            $stmt->execute([$question, $intent, $category]);
            log_action($pdo, 'create', 'سؤال', $pdo->lastInsertId(), [], ['question'=>$question,'intent'=>$intent,'category'=>$category]);
            $success = 'تم إضافة سؤال تدريبي جديد';
        } else {
            $error = 'يرجى تعبئة جميع الحقول';
        }
    }

    if ($_POST['action'] === 'delete_training' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM ai_training WHERE id = ?")->execute([$_POST['id']]);
        log_action($pdo, 'delete', 'تدريب', $_POST['id'], [], []);
        $success = 'تم حذف التدريب';
    }

    if ($_POST['action'] === 'delete_question' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM ai_questions WHERE id = ?")->execute([$_POST['id']]);
        log_action($pdo, 'delete', 'سؤال', $_POST['id'], [], []);
        $success = 'تم حذف السؤال';
    }

    if ($_POST['action'] === 'toggle_training' && isset($_POST['id'])) {
        $row = $pdo->prepare("SELECT is_active FROM ai_training WHERE id = ?");
        $row->execute([$_POST['id']]);
        $current = $row->fetchColumn();
        $new = $current ? 0 : 1;
        $pdo->prepare("UPDATE ai_training SET is_active = ? WHERE id = ?")->execute([$new, $_POST['id']]);
        log_action($pdo, 'update', 'تدريب', $_POST['id'], ['is_active'=>$current], ['is_active'=>$new]);
        $success = $new ? 'تم تفعيل التدريب' : 'تم إيقاف التدريب';
    }

    if ($_POST['action'] === 'edit_training' && isset($_POST['id'])) {
        $id        = (int)$_POST['id'];
        $question  = trim($_POST['question'] ?? '');
        $intent    = trim($_POST['intent']   ?? '');
        $sql_query = trim($_POST['sql_query'] ?? '');
        $response  = trim($_POST['response']  ?? '');
        if ($question && $intent && $sql_query && $response) {
            $old = $pdo->prepare("SELECT * FROM ai_training WHERE id = ?");
            $old->execute([$id]);
            $oldData = $old->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE ai_training SET question=?, intent=?, sql_query=?, expected_response=? WHERE id=?")
                ->execute([$question, $intent, $sql_query, $response, $id]);
            log_action($pdo, 'update', 'تدريب', $id, $oldData ?: [], ['question'=>$question,'intent'=>$intent]);
            $success = 'تم تحديث التدريب بنجاح';
        } else {
            $error = 'يرجى تعبئة جميع الحقول';
        }
    }

    if ($_POST['action'] === 'edit_question' && isset($_POST['id'])) {
        $id       = (int)$_POST['id'];
        $question = trim($_POST['question'] ?? '');
        $intent   = trim($_POST['intent']   ?? '');
        $category = trim($_POST['category'] ?? '');
        if ($question && $intent && $category) {
            $old = $pdo->prepare("SELECT * FROM ai_questions WHERE id = ?");
            $old->execute([$id]);
            $oldData = $old->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE ai_questions SET question=?, intent=?, category=? WHERE id=?")->execute([$question, $intent, $category, $id]);
            log_action($pdo, 'update', 'سؤال', $id, $oldData ?: [], ['question'=>$question,'intent'=>$intent,'category'=>$category]);
            $success = 'تم تحديث السؤال بنجاح';
        } else {
            $error = 'يرجى تعبئة جميع الحقول';
        }
    }

    // إعادة توجيه بعد العملية لمنع إعادة الإرسال
    if ($success) {
        echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم',text:" . json_encode($success) . "}));window.location.href='ai-training.php';</script>";
        exit;
    }
}

// جلب البيانات
$training  = $pdo->query("SELECT * FROM ai_training ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$questions = $pdo->query("SELECT * FROM ai_questions ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$categoryLabels = [
    'clients'  => 'العملاء',
    'users'    => 'المستخدمون',
    'tickets'  => 'البلاغات',
    'tasks'    => 'المهام',
    'branches' => 'الفروع',
    'other'    => 'أخرى',
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تدريب الذكاء الاصطناعي</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;overflow-x:hidden;scrollbar-width:none}

.ai-section {
    background:#fff;border-radius:14px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    margin-bottom:22px;overflow:hidden;
    border:1px solid #f0f2f7;
}
.ai-section-head {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    padding:13px 20px;display:flex;align-items:center;gap:12px;
}
.ai-section-head .s-icon {
    width:34px;height:34px;background:rgba(255,255,255,.2);
    border-radius:8px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:14px;flex-shrink:0;
}
.ai-section-head h5 {margin:0;color:#fff;font-weight:700;font-size:.9rem;}
.ai-section-head small {color:rgba(255,255,255,.75);font-size:.75rem;}
.ai-section-body {padding:20px;}

.f-label {font-size:.82rem;font-weight:700;color:#475569;margin-bottom:5px;display:block;}
.f-input {width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:9px 13px;font-size:.85rem;transition:.2s;}
.f-input:focus {outline:none;border-color:var(--crm-primary,#1a5276);box-shadow:0 0 0 3px rgba(26,82,118,.1);}
.f-group {margin-bottom:14px;}

.btn-ai-add {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));
    color:#fff;border:none;border-radius:9px;padding:9px 22px;
    font-weight:700;font-size:.85rem;cursor:pointer;transition:.2s;
    display:inline-flex;align-items:center;gap:7px;
}
.btn-ai-add:hover{opacity:.9;transform:translateY(-1px);}

/* جدول */
.ai-table th {
    background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9)) !important;
    color:#fff !important;font-size:.78rem;white-space:nowrap;text-align:center;
    border:none !important;padding:10px 8px;
}
.ai-table td {vertical-align:middle;text-align:center;font-size:.8rem;padding:8px 6px;}
.ai-table tbody tr:hover {background:#f8fafc;}

.badge-active   {background:#d1fae5;color:#065f46;padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;}
.badge-inactive {background:#f1f5f9;color:#64748b;padding:4px 12px;border-radius:20px;font-size:.72rem;font-weight:700;}

.stat-mini {
    background:#fff;border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    padding:16px;text-align:center;border:1px solid #f0f2f7;
}
.stat-mini .sm-val {font-size:1.8rem;font-weight:800;line-height:1;}
.stat-mini .sm-lbl {font-size:.74rem;color:#888;margin-top:3px;}

.intent-badge {
    background:#ede9fe;color:#5b21b6;
    font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:6px;
    font-family:monospace;display:inline-block;max-width:120px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.sql-snippet {
    font-family:monospace;font-size:.72rem;color:#0369a1;
    background:#f0f9ff;padding:2px 6px;border-radius:4px;
    max-width:150px;overflow:hidden;text-overflow:ellipsis;
    white-space:nowrap;display:inline-block;
}
.cat-badge {
    font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:20px;
    background:#fef3c7;color:#92400e;
}
</style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
<?php include(__DIR__ . '/../../main-header.php'); ?>
<?php include(__DIR__ . '/../../main-sidebar.php'); ?>

<div class="content-wrapper">

    <!-- ══ الترويسة ══ -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="uni-header">
                <div>
                    <h4><i class="fas fa-robot ml-2"></i> تدريب الذكاء الاصطناعي</h4>
                    <small>إدارة بيانات التدريب والأسئلة لنظام المساعد الذكي</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">تدريب AI</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- رسالة خطأ -->
        <?php if ($error): ?>
        <div class="alert d-flex align-items-center gap-2" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px 16px;gap:10px">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- ══ إحصائيات ══ -->
        <div class="row mb-4">
            <div class="col-6 col-md-3 mb-3">
                <div class="stat-mini">
                    <div class="sm-val" style="color:var(--crm-primary,#1a5276)"><?= count($training) ?></div>
                    <div class="sm-lbl">إجمالي التدريبات</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stat-mini">
                    <div class="sm-val" style="color:#22c55e"><?= count(array_filter($training, fn($t)=>$t['is_active'])) ?></div>
                    <div class="sm-lbl">تدريبات نشطة</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stat-mini">
                    <div class="sm-val" style="color:#7c3aed"><?= count($questions) ?></div>
                    <div class="sm-lbl">أسئلة تدريبية</div>
                </div>
            </div>
            <div class="col-6 col-md-3 mb-3">
                <div class="stat-mini">
                    <div class="sm-val" style="color:#0369a1"><?= count(array_unique(array_column($training,'intent'))) ?></div>
                    <div class="sm-lbl">نيات مختلفة</div>
                </div>
            </div>
        </div>

        <!-- ══ نموذجا الإضافة ══ -->
        <div class="row">

            <!-- إضافة تدريب -->
            <div class="col-lg-6 mb-4">
                <div class="ai-section">
                    <div class="ai-section-head">
                        <div class="s-icon"><i class="fas fa-brain"></i></div>
                        <div>
                            <h5>إضافة تدريب جديد</h5>
                            <small>ربط سؤال طبيعي باستعلام SQL ورد متوقع</small>
                        </div>
                    </div>
                    <div class="ai-section-body">
                        <form method="post">
                            <input type="hidden" name="action" value="add_training">
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-question-circle ml-1" style="color:var(--crm-primary,#1a5276)"></i>السؤال بالعربي</label>
                                <input type="text" name="question" class="f-input" placeholder='مثال: "كم عدد العملاء النشطين؟"' required>
                            </div>
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-tag ml-1" style="color:var(--crm-primary,#1a5276)"></i>النية (Intent)</label>
                                <input type="text" name="intent" class="f-input" placeholder="مثال: count_active_clients" dir="ltr" required>
                                <span style="font-size:.72rem;color:#94a3b8">استخدم snake_case بالإنجليزية</span>
                            </div>
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-code ml-1" style="color:var(--crm-primary,#1a5276)"></i>استعلام SQL</label>
                                <textarea name="sql_query" class="f-input" rows="2" placeholder="SELECT COUNT(*) FROM clients WHERE status='active'" dir="ltr" required></textarea>
                            </div>
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-reply ml-1" style="color:var(--crm-primary,#1a5276)"></i>الرد المتوقع</label>
                                <input type="text" name="response" class="f-input" placeholder='مثال: "عدد العملاء النشطين: {result}"' required>
                            </div>
                            <button type="submit" class="btn-ai-add">
                                <i class="fas fa-plus"></i> إضافة تدريب
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- إضافة سؤال تدريبي -->
            <div class="col-lg-6 mb-4">
                <div class="ai-section">
                    <div class="ai-section-head">
                        <div class="s-icon"><i class="fas fa-comments"></i></div>
                        <div>
                            <h5>إضافة سؤال تدريبي</h5>
                            <small>تنويعات لغوية تُعزز فهم النظام</small>
                        </div>
                    </div>
                    <div class="ai-section-body">
                        <form method="post">
                            <input type="hidden" name="action" value="add_question">
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-language ml-1" style="color:var(--crm-primary,#1a5276)"></i>السؤال</label>
                                <input type="text" name="question" class="f-input" placeholder='مثال: "أخبرني بعدد العملاء"' required>
                            </div>
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-link ml-1" style="color:var(--crm-primary,#1a5276)"></i>النية (Intent) — يجب مطابقة intent في التدريب</label>
                                <select name="intent" class="f-input" required>
                                    <option value="">اختر النية</option>
                                    <?php foreach (array_unique(array_column($training,'intent')) as $intent): ?>
                                    <option value="<?= htmlspecialchars($intent) ?>"><?= htmlspecialchars($intent) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="f-group">
                                <label class="f-label"><i class="fas fa-folder ml-1" style="color:var(--crm-primary,#1a5276)"></i>التصنيف</label>
                                <select name="category" class="f-input" required>
                                    <?php foreach ($categoryLabels as $k=>$v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn-ai-add" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
                                <i class="fas fa-plus"></i> إضافة سؤال
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ جدول بيانات التدريب ══ -->
        <div class="ai-section">
            <div class="ai-section-head">
                <div class="s-icon"><i class="fas fa-database"></i></div>
                <div>
                    <h5>بيانات التدريب (ai_training)</h5>
                    <small><?= count($training) ?> سجل تدريبي</small>
                </div>
            </div>
            <div class="ai-section-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 ai-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>السؤال</th>
                                <th>النية</th>
                                <th>استعلام SQL</th>
                                <th>الرد</th>
                                <th>الحالة</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($training)): ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">لا توجد بيانات تدريب — أضف أول تدريب من الأعلى</td></tr>
                            <?php endif; ?>
                            <?php foreach ($training as $t): ?>
                            <tr>
                                <td><strong><?= $t['id'] ?></strong></td>
                                <td style="text-align:right;max-width:200px">
                                    <span title="<?= htmlspecialchars($t['question']??'') ?>">
                                        <?= htmlspecialchars(mb_substr($t['question']??'',0,50)) ?><?= mb_strlen($t['question']??'')>50?'…':'' ?>
                                    </span>
                                </td>
                                <td><span class="intent-badge" title="<?= htmlspecialchars($t['intent']) ?>"><?= htmlspecialchars($t['intent']) ?></span></td>
                                <td><span class="sql-snippet" title="<?= htmlspecialchars($t['sql_query']??'') ?>"><?= htmlspecialchars(mb_substr($t['sql_query']??'',0,50)) ?></span></td>
                                <td style="max-width:150px;text-align:right">
                                    <small title="<?= htmlspecialchars($t['expected_response']??'') ?>">
                                        <?= htmlspecialchars(mb_substr($t['expected_response']??'',0,40)) ?><?= mb_strlen($t['expected_response']??'')>40?'…':'' ?>
                                    </small>
                                </td>
                                <td>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="toggle_training">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="<?= $t['is_active'] ? 'badge-active' : 'badge-inactive' ?>" style="border:none;cursor:pointer">
                                            <?= $t['is_active'] ? '● نشط' : '○ موقف' ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-warning" data-toggle="modal"
                                        data-target="#editTraining<?= $t['id'] ?>" title="تعديل" style="border-radius:6px">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="post" style="display:inline"
                                        onsubmit="return confirm('هل أنت متأكد من حذف هذا التدريب؟')">
                                        <input type="hidden" name="action" value="delete_training">
                                        <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="border-radius:6px"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <!-- مودال تعديل التدريب -->
                            <div class="modal fade" id="editTraining<?= $t['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content" style="border-radius:14px;overflow:hidden">
                                <div class="modal-header" style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff">
                                    <h5 class="modal-title"><i class="fas fa-edit ml-2"></i> تعديل التدريب #<?= $t['id'] ?></h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="edit_training">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <div class="modal-body">
                                        <div class="form-group">
                                            <label class="f-label">السؤال</label>
                                            <input type="text" name="question" class="form-control" value="<?= htmlspecialchars($t['question']??'') ?>" required>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label class="f-label">النية (Intent)</label>
                                                <input type="text" name="intent" class="form-control" value="<?= htmlspecialchars($t['intent']) ?>" dir="ltr" required>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label class="f-label">استعلام SQL</label>
                                            <textarea name="sql_query" class="form-control" rows="3" dir="ltr" required><?= htmlspecialchars($t['sql_query']??'') ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label class="f-label">الرد المتوقع</label>
                                            <input type="text" name="response" class="form-control" value="<?= htmlspecialchars($t['expected_response']??'') ?>" required>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                                    </div>
                                </form>
                            </div></div></div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══ جدول الأسئلة التدريبية ══ -->
        <div class="ai-section">
            <div class="ai-section-head" style="background:linear-gradient(135deg,#5b21b6,#7c3aed)">
                <div class="s-icon"><i class="fas fa-comments"></i></div>
                <div>
                    <h5>الأسئلة التدريبية (ai_questions)</h5>
                    <small><?= count($questions) ?> سؤال مُضاف</small>
                </div>
            </div>
            <div class="ai-section-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 ai-table">
                        <thead style="background:linear-gradient(135deg,#5b21b6,#7c3aed) !important">
                            <tr>
                                <th>#</th>
                                <th>السؤال</th>
                                <th>النية</th>
                                <th>التصنيف</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($questions)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">لا توجد أسئلة تدريبية — أضف أسئلة من النموذج أعلاه</td></tr>
                            <?php endif; ?>
                            <?php foreach ($questions as $q): ?>
                            <tr>
                                <td><strong><?= $q['id'] ?></strong></td>
                                <td style="text-align:right;max-width:250px">
                                    <span title="<?= htmlspecialchars($q['question']??'') ?>">
                                        <?= htmlspecialchars(mb_substr($q['question']??'',0,60)) ?><?= mb_strlen($q['question']??'')>60?'…':'' ?>
                                    </span>
                                </td>
                                <td><span class="intent-badge"><?= htmlspecialchars($q['intent']) ?></span></td>
                                <td><span class="cat-badge"><?= htmlspecialchars($categoryLabels[$q['category']] ?? $q['category']) ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-sm" data-toggle="modal"
                                        data-target="#editQuestion<?= $q['id'] ?>"
                                        style="background:#7c3aed;color:#fff;border-radius:6px;width:32px;height:32px;padding:0">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="post" style="display:inline"
                                        onsubmit="return confirm('هل أنت متأكد من حذف هذا السؤال؟')">
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" style="border-radius:6px"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <!-- مودال تعديل السؤال -->
                            <div class="modal fade" id="editQuestion<?= $q['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content" style="border-radius:14px;overflow:hidden">
                                <div class="modal-header" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff">
                                    <h5 class="modal-title"><i class="fas fa-edit ml-2"></i> تعديل السؤال #<?= $q['id'] ?></h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="action" value="edit_question">
                                    <input type="hidden" name="id" value="<?= $q['id'] ?>">
                                    <div class="modal-body">
                                        <div class="form-group">
                                            <label class="f-label">السؤال</label>
                                            <input type="text" name="question" class="form-control" value="<?= htmlspecialchars($q['question']??'') ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="f-label">النية (Intent)</label>
                                            <select name="intent" class="form-control" required>
                                                <option value="">اختر النية</option>
                                                <?php foreach (array_unique(array_column($training,'intent')) as $intent): ?>
                                                <option value="<?= htmlspecialchars($intent) ?>" <?= $q['intent']===$intent?'selected':'' ?>><?= htmlspecialchars($intent) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="f-label">التصنيف</label>
                                            <select name="category" class="form-control" required>
                                                <?php foreach ($categoryLabels as $k=>$v): ?>
                                                <option value="<?= $k ?>" <?= $q['category']===$k?'selected':'' ?>><?= $v ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                                        <button type="submit" class="btn" style="background:#7c3aed;color:#fff">حفظ التغييرات</button>
                                    </div>
                                </form>
                            </div></div></div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
</body>
</html>
