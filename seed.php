<?php
/**
 * Seed Script — إدراج بيانات افتراضية لجميع جداول النظام
 * يشغّل مرّة واحدة فقط: php seed.php
 * آمن لإعادة التشغيل (يستخدم INSERT IGNORE / WHERE NOT EXISTS)
 */

require __DIR__ . '/config/db.php';
require __DIR__ . '/admin/pages/forms/functions.php';

echo "=== بدء تعبئة البيانات الافتراضية ===\n\n";

$pdo->beginTransaction();

try {

    // ─────────────────────────────────────────────
    // 1. الصلاحيات (sys_permissions)
    // ─────────────────────────────────────────────
    $perms = [
        ['view_dashboard',  'عرض لوحة التحكم'],
        ['manage_tickets',  'إدارة التذاكر'],
        ['manage_clients',  'إدارة العملاء'],
        ['manage_agents',   'إدارة المندوبين'],
        ['manage_employees','إدارة الموظفين'],
        ['manage_docs',     'إدارة الوثائق'],
        ['manage_signatures','إدارة التوقيعات'],
        ['manage_reports',  'إدارة التقارير'],
        ['manage_settings', 'إدارة الإعدادات'],
        ['manage_users',    'إدارة المستخدمين'],
        ['manage_roles',    'إدارة الأدوار'],
        ['manage_sidebar',  'إدارة القائمة الجانبية'],
    ];
    $permStmt = $pdo->prepare("INSERT IGNORE INTO sys_permissions (perm_key, perm_name) VALUES (?, ?)");
    foreach ($perms as $p) {
        $permStmt->execute($p);
    }
    echo "✔ sys_permissions: " . count($perms) . " صلاحية\n";

    // ─────────────────────────────────────────────
    // 2. الفروع (branches)
    // ─────────────────────────────────────────────
    $brCount = $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn();
    if ($brCount == 0) {
        $branches = [
            ['الفرع الرئيسي - الرياض', 'الرياض', 'الرياض', 'شارع الملك فهد', '0112345678'],
            ['فرع جدة', 'جدة', 'مكة المكرمة', 'شارع الأمير سلطان', '0122345678'],
            ['فرع الدمام', 'الدمام', 'المنطقة الشرقية', 'شارع الملك عبدالعزيز', '0132345678'],
            ['فرع مكة', 'مكة المكرمة', 'مكة المكرمة', 'شارع أجياد', '0123345678'],
            ['فرع المدينة', 'المدينة المنورة', 'المدينة المنورة', 'شارع السلام', '0142345678'],
            ['فرع بريدة', 'بريدة', 'القصيم', 'شارع الملك فهد', '0162345678'],
            ['فرع أبها', 'أبها', 'عسير', 'شارع الملك عبدالعزيز', '0172345678'],
        ];
        $brStmt = $pdo->prepare("INSERT INTO branches (branch_name, city, region, address, phone, is_active) VALUES (?, ?, ?, ?, ?, 1)");
        foreach ($branches as $b) {
            $brStmt->execute($b);
        }
        echo "✔ branches: " . count($branches) . " فرع\n";
    } else {
        echo "– branches: موجود مسبقاً ($brCount)\n";
    }

    // ─────────────────────────────────────────────
    // 3. المناطق (regions)
    // ─────────────────────────────────────────────
    $rgCount = $pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn();
    if ($rgCount == 0) {
        $branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll(PDO::FETCH_KEY_PAIR);
        $regions = [];
        if (isset($branches[1])) {
            $regions[] = [1, 'منطقة الرياض الشمالية'];
            $regions[] = [1, 'منطقة الرياض الجنوبية'];
        }
        if (isset($branches[2])) {
            $regions[] = [2, 'منطقة جدة شمال'];
            $regions[] = [2, 'منطقة جدة جنوب'];
        }
        if (isset($branches[3])) {
            $regions[] = [3, 'منطقة الدمام'];
        }
        if (isset($branches[4])) {
            $regions[] = [4, 'منطقة مكة'];
        }
        $rgStmt = $pdo->prepare("INSERT INTO regions (branch_id, region_name) VALUES (?, ?)");
        foreach ($regions as $r) {
            $rgStmt->execute($r);
        }
        echo "✔ regions: " . count($regions) . " منطقة\n";
    } else {
        echo "– regions: موجود مسبقاً ($rgCount)\n";
    }

    // ─────────────────────────────────────────────
    // 4. الأقسام (departments)
    // ─────────────────────────────────────────────
    $dpCount = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    if ($dpCount == 0) {
        $regions = $pdo->query("SELECT id FROM regions")->fetchAll(PDO::FETCH_COLUMN);
        $deptNames = ['الدعم الفني', 'المبيعات', 'خدمة العملاء', 'الصيانة', 'المشتريات', 'الشؤون الإدارية'];
        $deptStmt = $pdo->prepare("INSERT INTO departments (department_name, region_id) VALUES (?, ?)");
        $c = 0;
        foreach ($regions as $rid) {
            foreach ($deptNames as $dn) {
                $deptStmt->execute([$dn, $rid]);
                $c++;
            }
        }
        echo "✔ departments: $c قسم\n";
    } else {
        echo "– departments: موجود مسبقاً ($dpCount)\n";
    }

    // ─────────────────────────────────────────────
    // 5. تصنيفات المشاكل (issue_categories)
    // ─────────────────────────────────────────────
    $icCount = $pdo->query("SELECT COUNT(*) FROM issue_categories")->fetchColumn();
    if ($icCount == 0) {
        $cats = [
            ['مشكلة شبكة', 'مشكلة في الاتصال بالشبكة أو الإنترنت', '#dc3545'],
            ['عطل في الجهاز', 'مشكلة في جهاز الكمبيوتر أو الطابعة', '#fd7e14'],
            ['مشكلة برمجية', 'مشكلة في أحد البرامج أو التطبيقات', '#ffc107'],
            ['طلب دعم فني', 'طلب مساعدة فنية عام', '#28a745'],
            ['استعلام', 'استفسار عام', '#17a2b8'],
            ['شكوى', 'شكوى من خدمة', '#6f42c1'],
        ];
        $icStmt = $pdo->prepare("INSERT IGNORE INTO issue_categories (category_name, description, color) VALUES (?, ?, ?)");
        foreach ($cats as $c) {
            $icStmt->execute($c);
        }
        echo "✔ issue_categories: " . count($cats) . " تصنيف\n";
    } else {
        echo "– issue_categories: موجود مسبقاً ($icCount)\n";
    }

    // ─────────────────────────────────────────────
    // 6. قواعد SLA (sla_rules)
    // ─────────────────────────────────────────────
    $slaCount = $pdo->query("SELECT COUNT(*) FROM sla_rules")->fetchColumn();
    if ($slaCount == 0) {
        $rules = [
            ['عاجل – ساعتين', 'Urgent', 2, 4],
            ['عالي – 4 ساعات', 'High', 4, 8],
            ['متوسط – 8 ساعات', 'Medium', 8, 24],
            ['منخفض – 24 ساعة', 'Low', 24, 72],
        ];
        $slaStmt = $pdo->prepare("INSERT IGNORE INTO sla_rules (rule_name, priority, response_hours, resolution_hours) VALUES (?, ?, ?, ?)");
        foreach ($rules as $r) {
            $slaStmt->execute($r);
        }
        echo "✔ sla_rules: " . count($rules) . " قاعدة\n";
    } else {
        echo "– sla_rules: موجود مسبقاً ($slaCount)\n";
    }

    // ─────────────────────────────────────────────
    // 7. ساعات العمل (business_hours)
    // ─────────────────────────────────────────────
    $bhCount = $pdo->query("SELECT COUNT(*) FROM business_hours")->fetchColumn();
    if ($bhCount == 0) {
        $days = [
            [0, 'الأحد', 1, '08:00:00', '17:00:00'],
            [1, 'الاثنين', 1, '08:00:00', '17:00:00'],
            [2, 'الثلاثاء', 1, '08:00:00', '17:00:00'],
            [3, 'الأربعاء', 1, '08:00:00', '17:00:00'],
            [4, 'الخميس', 1, '08:00:00', '17:00:00'],
            [5, 'الجمعة', 0, null, null],
            [6, 'السبت', 0, null, null],
        ];
        $bhStmt = $pdo->prepare("INSERT IGNORE INTO business_hours (day_of_week, day_name, is_working, open_time, close_time) VALUES (?, ?, ?, ?, ?)");
        foreach ($days as $d) {
            $bhStmt->execute($d);
        }
        echo "✔ business_hours: " . count($days) . " يوم\n";
    } else {
        echo "– business_hours: موجود مسبقاً ($bhCount)\n";
    }

    // ─────────────────────────────────────────────
    // 8. الإجازات (holiday_calendar)
    // ─────────────────────────────────────────────
    $hdCount = $pdo->query("SELECT COUNT(*) FROM holiday_calendar")->fetchColumn();
    if ($hdCount == 0) {
        $year = date('Y');
        $holidays = [
            ["$year-01-01", 'رأس السنة الميلادية', 0],
            ["$year-02-22", 'يوم التأسيس', 0],
            ["$year-03-01", 'إجازة منتصف الفصل', 0],
            ["$year-04-10", 'عيد الفطر المبارك', 1],
            ["$year-04-11", 'ثاني أيام عيد الفطر', 1],
            ["$year-04-12", 'ثالث أيام عيد الفطر', 1],
            ["$year-06-16", 'يوم عرفة', 1],
            ["$year-06-17", 'عيد الأضحى المبارك', 1],
            ["$year-06-18", 'ثاني أيام عيد الأضحى', 1],
            ["$year-06-19", 'ثالث أيام عيد الأضحى', 1],
            ["$year-09-23", 'اليوم الوطني السعودي', 0],
        ];
        $hdStmt = $pdo->prepare("INSERT IGNORE INTO holiday_calendar (holiday_date, holiday_name, is_recurring) VALUES (?, ?, ?)");
        foreach ($holidays as $h) {
            $hdStmt->execute($h);
        }
        echo "✔ holiday_calendar: " . count($holidays) . " إجازة\n";
    } else {
        echo "– holiday_calendar: موجود مسبقاً ($hdCount)\n";
    }

    // ─────────────────────────────────────────────
    // 9. العملاء (clients)
    // ─────────────────────────────────────────────
    $clCount = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    if ($clCount == 0) {
        $clients = [
            ['شركة التقنية المتطورة', 'company', 'TECH001', '0555000001', 'info@techco.com', password_hash('123456', PASSWORD_DEFAULT), 'الرياض', 'العليا', '12345'],
            ['مؤسسة النخبة للتجارة', 'company', 'ELITE002', '0555000002', 'info@elite.sa', password_hash('123456', PASSWORD_DEFAULT), 'جدة', 'الشاطئ', '23456'],
            ['شركة البناء الحديث', 'company', 'BUILD003', '0555000003', 'info@build.sa', password_hash('123456', PASSWORD_DEFAULT), 'الدمام', 'الخبر', '34567'],
            ['أحمد محمد السالم', 'individual', 'IND004', '0555000004', 'ahmed@email.com', password_hash('123456', PASSWORD_DEFAULT), 'الرياض', 'الملز', '45678'],
            ['شركة الخدمات المتكاملة', 'company', 'SERV005', '0555000005', 'info@services.sa', password_hash('123456', PASSWORD_DEFAULT), 'مكة', 'العزيزية', '56789'],
        ];
        $clStmt = $pdo->prepare("INSERT IGNORE INTO clients (client_name, client_type, client_code, phone, email, password, city, district, postal_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($clients as $c) {
            $clStmt->execute($c);
        }
        echo "✔ clients: " . count($clients) . " عميل\n";
    } else {
        echo "– clients: موجود مسبقاً ($clCount)\n";
    }

    // ─────────────────────────────────────────────
    // 10. المندوبين (agents)
    // ─────────────────────────────────────────────
    $agCount = $pdo->query("SELECT COUNT(*) FROM agents")->fetchColumn();
    if ($agCount == 0) {
        $branchIds = $pdo->query("SELECT id FROM branches LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $agents = [
            ['خالد العتيبي', '0555111001', 'khalid@wasl.sa', password_hash('123456', PASSWORD_DEFAULT), '1010101010', 'مندوب منطقة الرياض', $branchIds[0] ?? null],
            ['فيصل الغامدي', '0555111002', 'faisal@wasl.sa', password_hash('123456', PASSWORD_DEFAULT), '2020202020', 'مندوب منطقة جدة', $branchIds[1] ?? null],
            ['ناصر الدوسري', '0555111003', 'nasser@wasl.sa', password_hash('123456', PASSWORD_DEFAULT), '3030303030', 'مندوب المنطقة الشرقية', $branchIds[2] ?? null],
            ['ماجد الشهراني', '0555111004', 'majed@wasl.sa', password_hash('123456', PASSWORD_DEFAULT), '4040404040', 'مندوب مكة المكرمة', $branchIds[0] ?? null],
            ['سلطان الحربي', '0555111005', 'sultan@wasl.sa', password_hash('123456', PASSWORD_DEFAULT), '5050505050', 'مندوب القصيم', $branchIds[0] ?? null],
        ];
        $agStmt = $pdo->prepare("INSERT IGNORE INTO agents (agent_name, phone, email, password, national_id, address, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($agents as $a) {
            $agStmt->execute($a);
        }
        echo "✔ agents: " . count($agents) . " مندوب\n";
    } else {
        echo "– agents: موجود مسبقاً ($agCount)\n";
    }

    // ─────────────────────────────────────────────
    // 11. أنواع الوثائق (dms_document_types)
    // ─────────────────────────────────────────────
    $dtCount = $pdo->query("SELECT COUNT(*) FROM dms_document_types")->fetchColumn();
    if ($dtCount == 0 || $dtCount == 1) {
        $types = [
            ['عقد', 'عقود الشراكة والاتفاقيات', 3650],
            ['فاتورة', 'فواتير المبيعات والمشتريات', 1825],
            ['تقرير', 'تقارير دورية', 730],
            ['مذكرة داخلية', 'مذكرات وإعلانات داخلية', 365],
            ['خطاب رسمي', 'خطابات موجهة للجهات الرسمية', 1825],
            ['كشف حساب', 'كشوف حسابات العملاء', 1825],
            ['عرض سعر', 'عروض أسعار للعملاء', 365],
        ];
        $dtStmt = $pdo->prepare("INSERT IGNORE INTO dms_document_types (name, description, retention_period) VALUES (?, ?, ?)");
        foreach ($types as $t) {
            $dtStmt->execute($t);
        }
        echo "✔ dms_document_types: " . count($types) . " نوع\n";
    } else {
        echo "– dms_document_types: موجود مسبقاً ($dtCount)\n";
    }

    // ─────────────────────────────────────────────
    // 12. تصنيفات الوثائق (dms_categories)
    // ─────────────────────────────────────────────
    $dcCount = $pdo->query("SELECT COUNT(*) FROM dms_categories")->fetchColumn();
    if ($dcCount == 0) {
        $cats = [
            ['مالية', null, 'المستندات المالية والمحاسبية', 1],
            ['إدارية', null, 'المستندات الإدارية والتنظيمية', 2],
            ['فنية', null, 'المستندات الفنية والهندسية', 3],
            ['قانونية', null, 'العقود والمستندات القانونية', 4],
            ['شؤون موظفين', null, 'ملفات الموظفين والإجازات', 5],
            ['فواتير مبيعات', 1, 'فواتير البيع للعملاء', 6],
            ['فواتير مشتريات', 1, 'فواتير الشراء من الموردين', 7],
            ['عقود موظفين', 4, 'عقود التوظيف', 8],
        ];
        $dcStmt = $pdo->prepare("INSERT IGNORE INTO dms_categories (name, parent_id, description, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($cats as $c) {
            $dcStmt->execute($c);
        }
        echo "✔ dms_categories: " . count($cats) . " تصنيف\n";
    } else {
        echo "– dms_categories: موجود مسبقاً ($dcCount)\n";
    }

    // ─────────────────────────────────────────────
    // 13. الموظفين (dms_employees)
    // ─────────────────────────────────────────────
    $empCount = $pdo->query("SELECT COUNT(*) FROM dms_employees")->fetchColumn();
    if ($empCount == 0 || $empCount == 1) {
        $emps = [
            ['EMP001', 'أحمد عبدالله القحطاني', 'مدير النظام', 'تقنية المعلومات', 'ahmed@wasl.sa', '0555000101', 1],
            ['EMP002', 'نورة سعود العنزي', 'محاسب أول', 'المالية', 'noura@wasl.sa', '0555000102', 0],
            ['EMP003', 'محمد فهد الدوسري', 'فني دعم', 'الدعم الفني', 'mohamed@wasl.sa', '0555000103', 0],
            ['EMP004', 'سارة إبراهيم المطيري', 'موظف خدمة عملاء', 'خدمة العملاء', 'sara@wasl.sa', '0555000104', 0],
            ['EMP005', 'عبدالرحمن علي الشمري', 'مدير مبيعات', 'المبيعات', 'abdulrahman@wasl.sa', '0555000105', 1],
            ['EMP006', 'هند صالح الزهراني', 'محامي', 'القانونية', 'hind@wasl.sa', '0555000106', 0],
        ];
        // ربط الموظفين بأول مستخدمين نشطين في النظام (إن وجدوا)
        $sys_users = $pdo->query("SELECT id FROM sys_users WHERE status = 'active' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $empStmt = $pdo->prepare("INSERT IGNORE INTO dms_employees (emp_code, user_id, full_name, job_title, department, email, phone, can_sign) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($emps as $i => $e) {
            $user_id = $sys_users[$i] ?? null;
            $empStmt->execute([$e[0], $user_id, $e[1], $e[2], $e[3], $e[4], $e[5], $e[6]]);
        }
        echo "✔ dms_employees: " . count($emps) . " موظف\n";
    } else {
        echo "– dms_employees: موجود مسبقاً ($empCount)\n";
    }

    // ─────────────────────────────────────────────
    // 14. تذاكر تجريبية (tickets)
    // ─────────────────────────────────────────────
    $tkCount = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
    if ($tkCount == 0) {
        $branchIds = $pdo->query("SELECT id FROM branches LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $regionIds = $pdo->query("SELECT id FROM regions LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $deptIds   = $pdo->query("SELECT id FROM departments LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $catIds    = $pdo->query("SELECT id FROM issue_categories LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $slaIds    = $pdo->query("SELECT id FROM sla_rules LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $clientIds = $pdo->query("SELECT id FROM clients LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

        $tickets = [
            ['TK-20240001', 'REP-001', $clientIds[0] ?? null, $branchIds[0] ?? 1, $regionIds[0] ?? 1, $deptIds[0] ?? 1, 'مكتب الإدارة', $catIds[0] ?? 1, 'High', 'لا يعمل جهاز الشبكة في المكتب', 'In Progress', $slaIds[0] ?? null],
            ['TK-20240002', 'REP-002', $clientIds[1] ?? null, $branchIds[1] ?? 2, $regionIds[1] ?? 2, $deptIds[1] ?? 2, 'مستودع الخبر', $catIds[1] ?? 2, 'Medium', 'طلب تركيب جهاز جديد', 'Pending', $slaIds[1] ?? null],
            ['TK-20240003', 'REP-003', $clientIds[0] ?? null, $branchIds[0] ?? 1, $regionIds[0] ?? 1, $deptIds[0] ?? 1, 'قسم المحاسبة', $catIds[2] ?? 3, 'Low', 'استفسار عن فاتورة سابقة', 'Resolved', $slaIds[1] ?? null],
            ['TK-20240004', 'REP-004', null, $branchIds[0] ?? 1, $regionIds[0] ?? 1, $deptIds[0] ?? 1, 'مبنى الخدمات', $catIds[0] ?? 1, 'Urgent', 'انقطاع تام للإنترنت', 'Pending', $slaIds[0] ?? null],
            ['TK-20240005', 'REP-005', $clientIds[1] ?? null, $branchIds[1] ?? 2, $regionIds[1] ?? 2, $deptIds[1] ?? 2, 'الفرع الرئيسي', $catIds[1] ?? 2, 'Medium', 'طلب صيانة طابعة', 'In Progress', $slaIds[1] ?? null],
        ];

        $tkStmt = $pdo->prepare("INSERT IGNORE INTO tickets (ticket_number, reporter_ref, client_id, branch_id, region_id, department_id, location_name, category_id, priority, details, status, sla_rule_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        foreach ($tickets as $t) {
            $tkStmt->execute($t);
        }
        echo "✔ tickets: " . count($tickets) . " تذكرة\n";
    } else {
        echo "– tickets: موجود مسبقاً ($tkCount)\n";
    }

    // ─────────────────────────────────────────────
    // 15. تعليقات التذاكر (ticket_comments)
    // ─────────────────────────────────────────────
    $tcCount = $pdo->query("SELECT COUNT(*) FROM ticket_comments")->fetchColumn();
    if ($tcCount == 0) {
        $ticketRows = $pdo->query("SELECT id FROM tickets LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $users = $pdo->query("SELECT id FROM sys_users LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if ($ticketRows && $users) {
            $comments = [
                [$ticketRows[0], $users[0], 'تم استلام البلاغ وجاري التحقق', 0],
                [$ticketRows[0], $users[1], 'تم إرسال فني إلى الموقع', 1],
                [$ticketRows[1], $users[0], 'يرجى توضيح المواصفات المطلوبة', 0],
                [$ticketRows[2], $users[0], 'تم حل المشكلة وإغلاق التذكرة', 0],
            ];
            $cmtStmt = $pdo->prepare("INSERT IGNORE INTO ticket_comments (ticket_id, user_id, comment, is_internal) VALUES (?, ?, ?, ?)");
            foreach ($comments as $c) {
                $cmtStmt->execute($c);
            }
            echo "✔ ticket_comments: " . count($comments) . " تعليق\n";
        }
    } else {
        echo "– ticket_comments: موجود مسبقاً ($tcCount)\n";
    }

    // ─────────────────────────────────────────────
    // 16. أوامر العمل (work_orders)
    // ─────────────────────────────────────────────
    $woCount = $pdo->query("SELECT COUNT(*) FROM work_orders")->fetchColumn();
    if ($woCount == 0) {
        $ticketRows = $pdo->query("SELECT id FROM tickets WHERE status IN ('Pending','In Progress') LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $users = $pdo->query("SELECT id FROM sys_users LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if ($ticketRows && $users) {
            $orders = [
                [$ticketRows[0], $users[1], $users[0], 'إصلاح عطل الشبكة', 'الرجاء فحص أجهزة الشبكة في المكتب', 'High', 'In Progress'],
                [$ticketRows[1], $users[1], $users[0], 'تركيب جهاز جديد', 'تركيب جهاز كمبيوتر في مستودع الخبر', 'Normal', 'Pending'],
                [$ticketRows[3], $users[1], $users[0], 'معالجة انقطاع الإنترنت', 'انقطاع تام للإنترنت - يرجى التوجه فوراً', 'Critical', 'Pending'],
            ];
            $woStmt = $pdo->prepare("INSERT IGNORE INTO work_orders (ticket_id, assigned_to, created_by, title, details, priority, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($orders as $o) {
                $woStmt->execute($o);
            }
            echo "✔ work_orders: " . count($orders) . " أمر عمل\n";
        }
    } else {
        echo "– work_orders: موجود مسبقاً ($woCount)\n";
    }

    // ─────────────────────────────────────────────
    // 17. إشعارات (notifications)
    // ─────────────────────────────────────────────
    $notCount = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    if ($notCount == 0) {
        $users = $pdo->query("SELECT id FROM sys_users")->fetchAll(PDO::FETCH_COLUMN);
        if ($users) {
            $nots = [];
            foreach ($users as $uid) {
                $nots[] = [$uid, 'مرحباً بك في النظام', 'تم تفعيل حسابك بنجاح', 'system', 0];
            }
            $nots[] = [$users[0], 'تذكرة جديدة', 'تم إنشاء تذكرة جديدة بحاجة إلى متابعة', 'ticket', 1];
            $notStmt = $pdo->prepare("INSERT IGNORE INTO notifications (user_id, title, body, type, reference_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($nots as $n) {
                $notStmt->execute($n);
            }
            echo "✔ notifications: " . count($nots) . " إشعار\n";
        }
    } else {
        echo "– notifications: موجود مسبقاً ($notCount)\n";
    }

    // ─────────────────────────────────────────────
    // 18. رسائل داخلية (messages)
    // ─────────────────────────────────────────────
    $msgCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    if ($msgCount == 0) {
        $users = $pdo->query("SELECT id FROM sys_users")->fetchAll(PDO::FETCH_COLUMN);
        if (count($users) >= 2) {
            $msgs = [
                [$users[0], $users[1], 'السلام عليكم، يرجى متابعة التذاكر المعلقة', 0],
                [$users[1], $users[0], 'وعليكم السلام، جاري المتابعة', 0],
                [$users[0], $users[1], 'شكراً جزيلاً', 0],
            ];
            $msgStmt = $pdo->prepare("INSERT IGNORE INTO messages (sender_id, receiver_id, message_text, is_read) VALUES (?, ?, ?, ?)");
            foreach ($msgs as $m) {
                $msgStmt->execute($m);
            }
            echo "✔ messages: " . count($msgs) . " رسالة\n";
        }
    } else {
        echo "– messages: موجود مسبقاً ($msgCount)\n";
    }

    // ─────────────────────────────────────────────
    // 19. وثائق تجريبية (dms_documents)
    // ─────────────────────────────────────────────
    $docCount = $pdo->query("SELECT COUNT(*) FROM dms_documents")->fetchColumn();
    if ($docCount == 0) {
        $typeIds = $pdo->query("SELECT id FROM dms_document_types LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $catIds  = $pdo->query("SELECT id FROM dms_categories LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $empIds  = $pdo->query("SELECT id FROM dms_employees LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        $users   = $pdo->query("SELECT id FROM sys_users LIMIT 1")->fetchAll(PDO::FETCH_COLUMN);

        if ($typeIds && $users) {
            $docs = [
                ['DOC-2024-001', 'عقد صيانة مع شركة التقنية', $typeIds[0], $catIds[0] ?? null, 'عقد صيانة أجهزة الشبكة لمدة عام', 'archive/2024/01/c01.pdf', 'عقد صيانة.pdf', 'PDF', 1024000, 'تقنية المعلومات', 'approved', $users[0]],
                ['DOC-2024-002', 'فاتورة كهرباء شهر يناير', $typeIds[1], $catIds[1] ?? null, 'فاتورة استهلاك كهرباء للفرع الرئيسي', 'archive/2024/01/f01.pdf', 'فاتورة كهرباء.pdf', 'PDF', 512000, 'المالية', 'approved', $users[0]],
                ['DOC-2024-003', 'تقرير الأداء الربع الأول', $typeIds[2], $catIds[2] ?? null, 'تقرير أداء القسم للربع الأول 2024', 'archive/2024/01/r01.pdf', 'تقرير أداء.pdf', 'PDF', 2048000, 'الإدارة', 'draft', $users[0]],
                ['DOC-2024-004', 'خطاب رسمي لوزارة التجارة', $typeIds[0], $catIds[0] ?? null, 'خطاب بخصوص السجل التجاري', 'archive/2024/01/l01.pdf', 'خطاب رسمي.pdf', 'PDF', 256000, 'القانونية', 'approved', $users[0]],
                ['DOC-2024-005', 'عرض سعر لمشروع تطوير', $typeIds[1], $catIds[1] ?? null, 'عرض سعر لتطوير البنية التحتية', 'archive/2024/01/q01.pdf', 'عرض سعر.pdf', 'PDF', 768000, 'المبيعات', 'draft', $users[0]],
            ];
            $docStmt = $pdo->prepare("INSERT IGNORE INTO dms_documents (doc_number, title, type_id, category_id, description, file_path, file_name, file_format, file_size, department, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($docs as $d) {
                $docStmt->execute($d);
            }
            echo "✔ dms_documents: " . count($docs) . " وثيقة\n";
        }
    } else {
        echo "– dms_documents: موجود مسبقاً ($docCount)\n";
    }

    // ─────────────────────────────────────────────
    // 20. توقيعات تجريبية (dms_signatures)
    // ─────────────────────────────────────────────
    $sigCount = $pdo->query("SELECT COUNT(*) FROM dms_signatures")->fetchColumn();
    if ($sigCount == 0) {
        $docRows = $pdo->query("SELECT id FROM dms_documents LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        $empRows = $pdo->query("SELECT id FROM dms_employees LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if ($docRows && $empRows) {
            $sigs = [
                [$docRows[0], $empRows[0], 'sig_placeholder.png', 10, 10, 1, 150, 50, 'auto', 'signed', date('Y-m-d H:i:s')],
                [$docRows[1], $empRows[1], 'sig_placeholder.png', 20, 15, 1, 150, 50, 'auto', 'pending', null],
                [$docRows[0], $empRows[1], 'sig_placeholder.png', 10, 70, 1, 150, 50, 'manual', 'pending', null],
            ];
            $sigStmt = $pdo->prepare("INSERT IGNORE INTO dms_signatures (document_id, employee_id, signature_image, pos_x, pos_y, page_number, width, height, sign_type, status, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($sigs as $s) {
                $sigStmt->execute($s);
            }
            echo "✔ dms_signatures: " . count($sigs) . " توقيع\n";
        }
    } else {
        echo "– dms_signatures: موجود مسبقاً ($sigCount)\n";
    }

    $pdo->commit();
    echo "\n✓✓✓ تم تعبئة جميع البيانات الافتراضية بنجاح ✓✓✓\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n✗ فشل التعبئة: " . $e->getMessage() . "\n";
    throw $e;
}
