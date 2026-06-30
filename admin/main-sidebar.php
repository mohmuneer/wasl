<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$base_url = (isset($_SERVER['SERVER_NAME']) && str_ends_with($_SERVER['SERVER_NAME'], '.wuaze.com'))
    ? '/admin/'
    : '/UltimatesolutionsCrm/admin/';
$current_page = basename($_SERVER['PHP_SELF']);
$logged_user_id = $_SESSION['user_id'] ?? 0;
$role_code = $_SESSION['role_code'] ?? '';

// 1. جلب إعدادات النظام
$systemData = $pdo->query("SELECT system_name, system_logo FROM sys_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$systemName = $systemData['system_name'] ?? "T-LINK";

// 2. جلب الصلاحيات
$permissions = [];
if ($role_code === 'MainAdmin') {
    $all_ids = $pdo->query("SELECT id FROM sys_menu")->fetchAll(PDO::FETCH_COLUMN);
    $permissions = array_fill_keys($all_ids, 1);
} else {
    $stmt = $pdo->prepare("SELECT menu_id FROM user_menu_access WHERE user_id = ? AND can_view = 1");
    $stmt->execute([$logged_user_id]);
    $permissions = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), 1);
}

// 3. جلب جميع عناصر القائمة وبناء الهيكل الشجري
$all_menus = $pdo->query("SELECT * FROM sys_menu ORDER BY parent_id ASC, sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

$menu_tree = [];
foreach ($all_menus as $item) {
    if (isset($permissions[$item['id']])) {
        if ($item['parent_id'] == 0) {
            $menu_tree[$item['id']] = $item;
            $menu_tree[$item['id']]['children'] = [];
        } else {
            if (isset($menu_tree[$item['parent_id']])) {
                $menu_tree[$item['parent_id']]['children'][] = $item;
            }
        }
    }
}

// 4. حماية الصفحة الحالية
$is_protected = false;
foreach ($all_menus as $m) {
    if (basename($m['link']) == $current_page) {
        if (!isset($permissions[$m['id']]) && $current_page != 'index.php') {
            $is_protected = true;
        }
        break;
    }
}

if ($is_protected) {
    // يمكنك هنا إضافة توجيه أو رسالة خطأ
    exit("غير مسموح لك بالوصول لهذه الصفحة.");
}

// 5. جلب وصيانة مسار صورة المستخدم (تم دمج المنطق وتصحيحه)
$userImg = $_SESSION['file_path'] ?? '';
$uploads_web_path = (isset($_SERVER['SERVER_NAME']) && str_ends_with($_SERVER['SERVER_NAME'], '.wuaze.com'))
    ? '/uploads/'
    : '/UltimatesolutionsCrm/uploads/';
$uploads_dir = $_SERVER['DOCUMENT_ROOT'] . $uploads_web_path;

if (!empty($userImg) && file_exists($uploads_dir . $userImg)) {
    $fullImagePath = $uploads_web_path . $userImg;
} else {
    $fullImagePath = $base_url . "dist/img/avatar5.png";
}
?>

<style>
    .main-sidebar {
        transition: all 0.3s ease-in-out;
    }

    .nav-link.active {
        background-color: rgba(255, 255, 255, 0.1) !important;
        border-right: 4px solid #3498db;
    }

    .nav-treeview>.nav-item>.nav-link {
        padding-right: 2rem !important;
        font-size: 0.9rem;
    }

    .brand-link {
        border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
    }

    .user-panel {
        border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important;
        padding-bottom: 15px;
    }

    .user-panel .image img {
        object-fit: cover;
    }

    .sidebar {
        max-height: calc(100vh - 120px);
        overflow-y: auto;
        overflow-x: hidden;
    }

    .sidebar::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 2px;
    }

    .sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.4);
    }
</style>

<aside class="main-sidebar sidebar-dark-primary elevation-4"
    style="background-color: <?= $visuals['sidebar_color'] ?? '#1e272e'; ?> !important;">

    <a href="<?= $base_url ?>index.php" class="brand-link text-center">
        <img src="<?= $base_url ?>dist/img/<?= htmlspecialchars($systemData['system_logo'] ?? 'logo-tlink.png'); ?>"
            alt="Logo" class="brand-image  "
            style="float: none; max-height: 40px; background-color: white; padding: 2px; border-radius:5px">
        <span class="brand-text font-weight-bold d-block mt-2"><?= htmlspecialchars($systemName); ?></span>
    </a>

    <div class="sidebar">
        <!-- قسم صورة المستخدم والاسم -->
        <!-- <div class="user-panel mt-4 d-flex align-items-center">
            <div class="image">
                <img src="<?= $fullImagePath; ?>" class="img-circle elevation-2" alt="User"
                    style="width: 40px; height: 40px; border: 2px solid #34495e;">
            </div>
            <div class="info">
                <a href="#" class="d-block py-1 font-weight-light">
                    <?= htmlspecialchars($_SESSION['full_name'] ?? 'مستخدم'); ?>

                </a>


            </div>
        </div> -->


        <nav class="mt-3">
            <ul class="nav nav-pills nav-sidebar flex-column nav-child-indent" data-widget="treeview" role="menu"
                data-accordion="false">

                <?php foreach ($menu_tree as $main):
                    $has_sub = !empty($main['children']);
                    $is_open = false;

                    if ($has_sub) {
                        foreach ($main['children'] as $child) {
                            if (basename($child['link']) == $current_page) {
                                $is_open = true;
                                break;
                            }
                        }
                    } else {
                        $is_open = (basename($main['link']) == $current_page);
                    }
                ?>

                    <li class="nav-item <?= ($has_sub && $is_open) ? 'menu-open' : '' ?>">
                        <a href="<?= $has_sub ? '#' : $base_url . $main['link'] ?>"
                            class="nav-link <?= $is_open ? 'active' : '' ?>">
                            <i class="nav-icon <?= htmlspecialchars($main['icon'] ?: 'fas fa-th-large') ?>"></i>
                            <p>
                                <?= htmlspecialchars($main['title']) ?>
                                <?= $has_sub ? '<i class="right fas fa-angle-left"></i>' : '' ?>
                            </p>
                        </a>

                        <?php if ($has_sub): ?>
                            <ul class="nav nav-treeview">
                                <?php foreach ($main['children'] as $sub):
                                    $sub_active = (basename($sub['link']) == $current_page) ? 'active' : '';
                                ?>
                                    <li class="nav-item">
                                        <a href="<?= $base_url . $sub['link'] ?>" class="nav-link <?= $sub_active ?>">
                                            <i class="<?= htmlspecialchars($sub['icon'] ?: 'far fa-circle') ?> nav-icon"
                                                style="font-size: 0.8rem;"></i>
                                            <p><?= htmlspecialchars($sub['title']) ?></p>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>

            </ul>
        </nav>
    </div>
</aside>