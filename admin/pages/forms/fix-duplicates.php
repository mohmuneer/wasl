<?php
/**
 * سكربت تنظيف السجلات المكررة من جداول النظام
 * يُحذف السجلات المكررة مع الاحتفاظ بأقل ID لكل اسم مكرر
 * ثم يُضيف قيود UNIQUE لمنع تكرارها مستقبلاً
 * 
 * التشغيل: قم بزيارة هذا الملف من المتصفح (يتطلب صلاحية مدير)
 * أو شغّله عبر phpMyAdmin على SqlTab -> Run SQL file
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require dirname(__DIR__, 3) . '/config/db.php';
require dirname(__DIR__, 3) . '/config/tables.php';

$current_user_id = $_SESSION['user_id'] ?? 0;

// ─── تحقق بسيط من الصلاحية ──────────────────────────────────
$isAdmin = false;
if ($current_user_id > 0) {
    $r = $pdo->prepare("SELECT role_id FROM " . TBL_USER_ROLES . " WHERE user_id = ?");
    $r->execute([$current_user_id]);
    $roleId = (int)$r->fetchColumn();
    $isAdmin = ($roleId === 1); // افترض أن role_id=1 هو المدير
}

// إذا لم يكن المدير، اعرض رسالة
if (!$isAdmin) {
    echo "<div style='font-family:sans-serif;padding:40px;text-align:center'><h2>صلاحية مدير مطلوبة</h2><p>هذا السكربت يحتاج صلاحية المدير العام.</p></div>";
    exit;
}

echo "<html dir='rtl'><head><meta charset='utf-8'><title>تنظيف المكررات</title>
<style>body{font-family:Tahoma,sans-serif;padding:30px;background:#f5f5f5;max-width:800px;margin:auto}
.card{background:#fff;border-radius:8px;padding:20px;margin-bottom:15px;box-shadow:0 1px 4px rgba(0,0,0,.1)}
.success{color:#155724;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;padding:10px 15px;margin:5px 0}
.error{color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;padding:10px 15px;margin:5px 0}
.info{color:#0c5460;background:#d1ecf1;border:1px solid #bee5eb;border-radius:4px;padding:10px 15px;margin:5px 0}
code{background:#e8e8e8;padding:2px 6px;border-radius:3px;font-size:13px}
</style></head><body>
<h2 style='color:#333'>🔧 تنظيف السجلات المكررة</h2>";

// ─── الجداول المستهدفة ──────────────────────────────────────
$tables = [
    [
        'table'     => TBL_DOC_TYPES,
        'name_col'  => 'name',
        'label'     => 'أنواع الوثائق',
        'unique'    => 'uniq_doc_type_name',
    ],
    [
        'table'     => TBL_DOC_CATEGORIES,
        'name_col'  => 'name',
        'label'     => 'تصنيفات الوثائق',
        'unique'    => 'uniq_doc_category_name',
    ],
    [
        'table'     => TBL_APPROVAL_WORKFLOWS,
        'name_col'  => 'name',
        'label'     => 'سياسات الاعتماد',
        'unique'    => 'uniq_workflow_name',
    ],
    [
        'table'     => TBL_DEPARTMENTS,
        'name_col'  => 'department_name',
        'label'     => 'الأقسام',
        'unique'    => 'uniq_department_name',
    ],
];

$totalDeleted = 0;

try {
    $pdo->beginTransaction();

    foreach ($tables as $tbl) {
        $table     = $tbl['table'];
        $nameCol   = $tbl['name_col'];
        $label     = $tbl['label'];
        $uniqName  = $tbl['unique'];

        echo "<div class='card'><h4 style='margin-top:0'>📋 $label (<code>$table</code>)</h4>";

        // 1. العثور على المكررات
        $dupes = $pdo->query("
            SELECT $nameCol, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
            FROM `$table`
            GROUP BY $nameCol
            HAVING cnt > 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dupes)) {
            echo "<p class='info'>✅ لا توجد مكررات.</p>";
        } else {
            foreach ($dupes as $d) {
                $idList  = explode(',', $d['ids']);
                $keepId  = (int)$idList[0]; // أقل ID
                $delIds  = array_slice($idList, 1);

                echo "<p class='info' style='font-size:14px'>
                    🗑️ <strong>" . htmlspecialchars($d[$nameCol]) . "</strong> — 
                    تم العثور على {$d['cnt']} نسخ. 
                    سيتم الاحتفاظ بالـ ID <code>$keepId</code> وحذف IDs: <code>" . implode(',', $delIds) . "</code>
                </p>";

                foreach ($delIds as $delId) {
                    $delId = (int)$delId;
                    // تحقق إن كان هناك وثائق مرتبطة بهذا السجل قبل الحذف
                    if ($label === 'أنواع الوثائق') {
                        $ref = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_DOCUMENTS . " WHERE type_id = ?");
                        $ref->execute([$delId]);
                        if ($ref->fetchColumn() > 0) {
                            // تحديث الوثائق المرتبطة للإشارة إلى ID المحتفظ به
                            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET type_id = ? WHERE type_id = ?")
                               ->execute([$keepId, $delId]);
                        }
                    }
                    if ($label === 'تصنيفات الوثائق') {
                        $ref = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_DOCUMENTS . " WHERE category_id = ?");
                        $ref->execute([$delId]);
                        if ($ref->fetchColumn() > 0) {
                            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET category_id = ? WHERE category_id = ?")
                               ->execute([$keepId, $delId]);
                        }
                    }
                    if ($label === 'سياسات الاعتماد') {
                        $ref = $pdo->prepare("SELECT COUNT(*) FROM " . TBL_DOCUMENTS . " WHERE workflow_id = ?");
                        $ref->execute([$delId]);
                        if ($ref->fetchColumn() > 0) {
                            $pdo->prepare("UPDATE " . TBL_DOCUMENTS . " SET workflow_id = ? WHERE workflow_id = ?")
                               ->execute([$keepId, $delId]);
                        }
                    }

                    $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$delId]);
                    $totalDeleted++;
                }
            }
            echo "<p class='success'>✅ تم حذف المكررات بنجاح.</p>";
        }

        // 2. إضافة unique constraint إذا لم يوجد
        $check = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$uniqName'")->fetch();
        if (!$check) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$uniqName` UNIQUE (`$nameCol`)");
                echo "<p class='success'>🔒 تمت إضافة قيد UNIQUE على عمود <code>$nameCol</code>.</p>";
            } catch (Exception $e) {
                echo "<p class='error'>⚠️ فشل إضافة UNIQUE: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p class='info'>🔒 قيد UNIQUE موجود مسبقاً.</p>";
        }

        echo "</div>";
    }

    $pdo->commit();
    echo "<div class='card' style='background:#cce5ff;border-color:#b8daff'>
        <h3 style='margin:0;color:#004085'>✅ تم الانتهاء — تم حذف $totalDeleted سجلاً مكرراً</h3>
    </div>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='card error'><h3>❌ فشل التنظيف</h3><p>" . $e->getMessage() . "</p></div>";
}

echo "</body></html>";
