<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
$page_path = "pages/tables/view-permissions.php";
if (!$current_user_id) die("خطأ: يجب تسجيل الدخول أولاً");

$menuStmt = $pdo->prepare("SELECT id FROM sys_menu WHERE link = ?");
$menuStmt->execute([$page_path]);
$current_page_id = $menuStmt->fetchColumn() ?? 0;

$can_add = 0;
if ($current_page_id > 0) {
    $accStmt = $pdo->prepare("SELECT can_add FROM user_menu_access WHERE user_id=? AND menu_id=?");
    $accStmt->execute([$current_user_id, $current_page_id]);
    $can_add = (int)($accStmt->fetchColumn() ?: 0);
}

// جلب الأدوار مع عدد المستخدمين لكل دور
$roles = $pdo->query("
    SELECT r.id, r.role_name, r.role_code,
           COUNT(ur.user_id) AS users_count
    FROM sys_roles r
    LEFT JOIN user_roles ur ON r.id = ur.role_id
    GROUP BY r.id, r.role_name, r.role_code
    ORDER BY r.role_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// حذف دور
if (isset($_GET['delete_id'])) {
    $del = (int)$_GET['delete_id'];
    $pdo->prepare("DELETE FROM sys_roles WHERE id=?")->execute([$del]);
    echo "<script>sessionStorage.setItem('app_message',JSON.stringify({icon:'success',title:'تم الحذف',text:'تم حذف الدور بنجاح'}));window.location.href='view-permissions.php';</script>";
    exit;
}

// ألوان ثابتة للأدوار (دوري حسب الترتيب)
$roleColors = [
    ['bg'=>'#dbeafe','text'=>'#1d4ed8','icon'=>'fas fa-crown'],
    ['bg'=>'#d1fae5','text'=>'#065f46','icon'=>'fas fa-user-shield'],
    ['bg'=>'#fef3c7','text'=>'#92400e','icon'=>'fas fa-user-cog'],
    ['bg'=>'#fce7f3','text'=>'#9d174d','icon'=>'fas fa-user-tag'],
    ['bg'=>'#ede9fe','text'=>'#5b21b6','icon'=>'fas fa-users-cog'],
    ['bg'=>'#ffedd5','text'=>'#9a3412','icon'=>'fas fa-user-tie'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الصلاحيات والأدوار</title>
<link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">
<link rel="stylesheet" href="../../dist/css/adminlte.min.css">
<link rel="stylesheet" href="https://cdn.rtlcss.com/bootstrap/v4.2.1/css/bootstrap.min.css">
<link rel="stylesheet" href="../../dist/css/custom.css?v=202606261542">
<style>
::-webkit-scrollbar{display:none}
body{direction:rtl;text-align:right;overflow-x:hidden;scrollbar-width:none;background:#f0f2f7}

/* ══ إحصائيات ══ */
.stat-pill {
    background:#fff;
    border-radius:14px;
    padding:18px 22px;
    display:flex;align-items:center;gap:14px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    border:1px solid #f0f2f7;
}
.stat-pill .sp-icon {
    width:48px;height:48px;flex-shrink:0;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.2rem;color:#fff;
}
.stat-pill .sp-val { font-size:1.7rem;font-weight:800;line-height:1; }
.stat-pill .sp-lbl { font-size:.76rem;color:#888;margin-top:2px; }

/* ══ بطاقة الدور ══ */
.role-card {
    background:#fff;
    border-radius:16px;
    box-shadow:0 2px 16px rgba(0,0,0,.06);
    border:1px solid #f0f2f7;
    overflow:hidden;
    transition:.2s;
    height:100%;
    display:flex;flex-direction:column;
}
.role-card:hover {
    transform:translateY(-3px);
    box-shadow:0 8px 28px rgba(0,0,0,.1);
}
.role-card-head {
    padding:18px 20px 14px;
    display:flex;align-items:flex-start;gap:12px;
}
.role-icon {
    width:46px;height:46px;flex-shrink:0;
    border-radius:12px;
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;
}
.role-name { font-size:1rem;font-weight:800;color:#1e293b;margin:0 0 3px; }
.role-code-badge {
    font-size:.7rem;font-weight:700;
    padding:2px 10px;border-radius:20px;
    display:inline-flex;align-items:center;gap:4px;
}
.role-card-body {
    padding:0 20px 14px;
    flex:1;display:flex;flex-direction:column;gap:8px;
}
.role-meta-item {
    display:flex;align-items:center;justify-content:space-between;
    font-size:.78rem;color:#64748b;
    padding:6px 0;
    border-bottom:1px solid #f8fafc;
}
.role-meta-item:last-child{border-bottom:none}
.role-meta-item strong{color:#334155}
.users-badge {
    display:inline-flex;align-items:center;gap:5px;
    font-size:.72rem;font-weight:700;
    padding:3px 10px;border-radius:20px;
}
.role-card-footer {
    padding:12px 16px;
    border-top:1px solid #f0f2f7;
    display:flex;gap:8px;
    background:#fafbfc;
}
.btn-role-edit {
    flex:1;background:linear-gradient(135deg,#f59e0b,#d97706);
    color:#fff;border:none;border-radius:8px;
    padding:8px;font-size:.78rem;font-weight:700;
    text-align:center;transition:.2s;text-decoration:none;
    display:flex;align-items:center;justify-content:center;gap:6px;
}
.btn-role-edit:hover{opacity:.9;transform:translateY(-1px);color:#fff;text-decoration:none}
.btn-role-perms {
    flex:1;background:linear-gradient(135deg,#1a5276,#2980b9);
    color:#fff;border:none;border-radius:8px;
    padding:8px;font-size:.78rem;font-weight:700;
    text-align:center;transition:.2s;text-decoration:none;
    display:flex;align-items:center;justify-content:center;gap:6px;
}
.btn-role-perms:hover{opacity:.9;transform:translateY(-1px);color:#fff;text-decoration:none}
.btn-role-del {
    background:#fef2f2;border:1.5px solid #fecaca;
    color:#dc2626;border-radius:8px;
    padding:8px 12px;font-size:.78rem;font-weight:700;
    cursor:pointer;transition:.2s;
    display:flex;align-items:center;justify-content:center;
}
.btn-role-del:hover{background:#dc2626;color:#fff;border-color:#dc2626}

/* ══ شريط البحث ══ */
.search-bar-vp {
    background:#fff;
    border-radius:12px;
    box-shadow:0 2px 10px rgba(0,0,0,.05);
    padding:14px 18px;
    display:flex;align-items:center;gap:12px;
    margin-bottom:20px;
    border:1px solid #f0f2f7;
}
.search-bar-vp input {
    border:none;outline:none;flex:1;font-size:.88rem;color:#334155;background:transparent;
}
.search-bar-vp i { color:#94a3b8;font-size:.9rem; }

/* ══ رسالة فارغة ══ */
.empty-state {
    text-align:center;padding:60px 20px;
    background:#fff;border-radius:16px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
}
.empty-state i { font-size:3.5rem;color:#cbd5e1;margin-bottom:16px; }
.empty-state h5 { color:#475569;font-weight:700; }
.empty-state p { color:#94a3b8;font-size:.85rem; }
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
                    <h4><i class="fas fa-shield-alt ml-2"></i>إدارة الصلاحيات والأدوار</h4>
                    <small>عرض وتعديل وحذف أدوار المستخدمين في النظام</small>
                </div>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="../../index.php"><i class="fas fa-home ml-1"></i>الرئيسية</a></li>
                    <li class="breadcrumb-item active">الصلاحيات</li>
                </ol>
            </div>
        </div>
    </section>

    <section class="content">
    <div class="container-fluid">

        <!-- ══ إحصائيات سريعة ══ -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="stat-pill">
                    <div class="sp-icon" style="background:linear-gradient(135deg,#1a5276,#2980b9)">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div>
                        <div class="sp-val"><?= count($roles) ?></div>
                        <div class="sp-lbl">إجمالي الأدوار</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-pill">
                    <div class="sp-icon" style="background:linear-gradient(135deg,#065f46,#059669)">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="sp-val"><?= array_sum(array_column($roles,'users_count')) ?></div>
                        <div class="sp-lbl">إجمالي المستخدمين المُعيَّنين</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="stat-pill">
                    <div class="sp-icon" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <div>
                        <div class="sp-val"><?= count(array_filter($roles, fn($r) => $r['users_count'] == 0)) ?></div>
                        <div class="sp-lbl">أدوار بدون مستخدمين</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ شريط البحث وزر الإضافة ══ -->
        <div class="d-flex align-items-center gap-3 mb-4" style="gap:12px">
            <div class="search-bar-vp flex-grow-1" style="margin-bottom:0">
                <i class="fas fa-search"></i>
                <input type="text" id="roleSearch" placeholder="ابحث عن دور أو كود...">
            </div>
            <?php if ($can_add): ?>
            <a href="../forms/add-role.php" class="btn d-flex align-items-center gap-2"
                style="background:linear-gradient(135deg,var(--crm-page-bar-from,#1a5276),var(--crm-page-bar-to,#2980b9));color:#fff;border-radius:10px;padding:10px 20px;font-weight:700;font-size:.88rem;white-space:nowrap;gap:8px;box-shadow:0 4px 14px rgba(26,82,118,.3)">
                <i class="fas fa-plus"></i> إضافة دور جديد
            </a>
            <?php endif; ?>
        </div>

        <!-- ══ بطاقات الأدوار ══ -->
        <?php if (empty($roles)): ?>
        <div class="empty-state">
            <i class="fas fa-shield-alt"></i>
            <h5>لا توجد أدوار مُعرَّفة</h5>
            <p>ابدأ بإضافة دور جديد لتنظيم صلاحيات المستخدمين</p>
            <?php if ($can_add): ?>
            <a href="../forms/add-role.php" class="btn btn-primary mt-2" style="border-radius:10px">
                <i class="fas fa-plus ml-1"></i>إضافة أول دور
            </a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="row" id="rolesGrid">
            <?php foreach ($roles as $i => $role):
                $clr = $roleColors[$i % count($roleColors)];
                $usersCount = (int)$role['users_count'];
            ?>
            <div class="col-lg-4 col-md-6 mb-4 role-item"
                data-name="<?= strtolower(htmlspecialchars($role['role_name'])) ?>"
                data-code="<?= strtolower(htmlspecialchars($role['role_code'])) ?>">
                <div class="role-card">

                    <div class="role-card-head">
                        <div class="role-icon" style="background:<?= $clr['bg'] ?>">
                            <i class="<?= $clr['icon'] ?>" style="color:<?= $clr['text'] ?>"></i>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="role-name"><?= htmlspecialchars($role['role_name']) ?></div>
                            <span class="role-code-badge" style="background:<?= $clr['bg'] ?>;color:<?= $clr['text'] ?>">
                                <i class="fas fa-code" style="font-size:.6rem"></i>
                                <?= htmlspecialchars($role['role_code']) ?>
                            </span>
                        </div>
                        <!-- عدد المستخدمين -->
                        <div class="users-badge" style="background:<?= $usersCount > 0 ? '#d1fae5' : '#f1f5f9' ?>;color:<?= $usersCount > 0 ? '#065f46' : '#94a3b8' ?>">
                            <i class="fas fa-user" style="font-size:.6rem"></i>
                            <?= $usersCount ?>
                        </div>
                    </div>

                    <div class="role-card-body">
                        <div class="role-meta-item">
                            <span><i class="fas fa-users ml-1" style="color:<?= $clr['text'] ?>"></i>المستخدمون المُعيَّنون</span>
                            <strong><?= $usersCount > 0 ? $usersCount . ' مستخدم' : '—' ?></strong>
                        </div>
                        <div class="role-meta-item">
                            <span><i class="fas fa-hashtag ml-1" style="color:<?= $clr['text'] ?>"></i>كود الدور</span>
                            <strong dir="ltr"><?= htmlspecialchars($role['role_code']) ?></strong>
                        </div>
                        <div class="role-meta-item">
                            <span><i class="fas fa-circle ml-1" style="color:<?= $usersCount > 0 ? '#22c55e' : '#cbd5e1' ?>;font-size:.5rem"></i>الحالة</span>
                            <strong style="color:<?= $usersCount > 0 ? '#22c55e' : '#94a3b8' ?>">
                                <?= $usersCount > 0 ? 'مُستخدَم' : 'غير مُستخدَم' ?>
                            </strong>
                        </div>
                    </div>

                    <div class="role-card-footer">
                        <a href="edit-role.php?id=<?= $role['id'] ?>" class="btn-role-edit">
                            <i class="fas fa-edit"></i>تعديل
                        </a>
                        <a href="assign-permissions.php?role_id=<?= $role['id'] ?>" class="btn-role-perms">
                            <i class="fas fa-key"></i>الصلاحيات
                        </a>
                        <?php if ($can_add): ?>
                        <button type="button" class="btn-role-del"
                            onclick="confirmDelete(<?= $role['id'] ?>, '<?= addslashes(htmlspecialchars($role['role_name'])) ?>', <?= $usersCount ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- رسالة لا نتائج بحث -->
        <div id="noResults" style="display:none">
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <h5>لا توجد نتائج</h5>
                <p>لم يُعثَر على دور يطابق بحثك</p>
            </div>
        </div>
        <?php endif; ?>

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
// ── بحث فوري ──
document.getElementById('roleSearch').addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    var items = document.querySelectorAll('.role-item');
    var visible = 0;
    items.forEach(function(item) {
        var match = !q || item.dataset.name.includes(q) || item.dataset.code.includes(q);
        item.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('noResults').style.display = (visible === 0 && q) ? '' : 'none';
});

// ── حذف مع تأكيد ──
function confirmDelete(id, name, usersCount) {
    var warningText = usersCount > 0
        ? 'تحذير: هذا الدور مُعيَّن لـ <strong>' + usersCount + ' مستخدم</strong>. حذفه سيؤثر عليهم.'
        : 'سيتم حذف الدور نهائياً ولا يمكن التراجع عن هذا الإجراء.';

    Swal.fire({
        title: 'حذف: ' + name,
        html: warningText,
        icon: usersCount > 0 ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-trash ml-1"></i>نعم، احذف',
        cancelButtonText: 'إلغاء',
        reverseButtons: true
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = '?delete_id=' + id;
        }
    });
}
</script>
</body>
</html>
