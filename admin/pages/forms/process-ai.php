<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: text/plain; charset=UTF-8");

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require __DIR__ . "/../../../config/db.php";

$current_user_id = $_SESSION['user_id'] ?? null;
if (!$current_user_id) { die("يجب تسجيل الدخول أولاً"); }

$query = trim($_POST['query'] ?? '');
if (empty($query)) { die("كيف أستطيع مساعدتك اليوم؟"); }

/* ──────────────────────────────────────────────────────
   1. التطبيع
   ────────────────────────────────────────────────────── */
function smartNormalize($text) {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/^(ال)/u', '', $text);
    $map = [
        "أ"=>"ا","إ"=>"ا","آ"=>"ا","ة"=>"ه","ى"=>"ي",
        "ئ"=>"ي","ؤ"=>"و","؟"=>"","?"=>"","\"" =>"","'"=>"",
    ];
    return trim(strtr($text, $map));
}

$cleanQuery = smartNormalize($query);

/* ──────────────────────────────────────────────────────
   2. استخراج الكيانات
   ────────────────────────────────────────────────────── */
$users    = $pdo->query("SELECT id, full_name  FROM sys_users")->fetchAll(PDO::FETCH_KEY_PAIR);
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll(PDO::FETCH_KEY_PAIR);
$regions  = $pdo->query("SELECT id, region_name FROM regions")->fetchAll(PDO::FETCH_KEY_PAIR);
$clients  = $pdo->query("SELECT id, client_name FROM clients")->fetchAll(PDO::FETCH_KEY_PAIR);
$agents   = $pdo->query("SELECT id, agent_name  FROM agents")->fetchAll(PDO::FETCH_KEY_PAIR);

$entities = [
    "{user}"             => "",
    "{branch}"           => "",
    "{region}"           => "",
    "{client}"           => "",
    "{agent}"            => "",
    "{status}"           => "",
    "{priority}"         => "",
    "{current_user_id}"  => $current_user_id,
];

foreach ($users    as $id => $name) { $n = smartNormalize($name); if ($n && mb_strpos($cleanQuery,$n)!==false){$entities["{user}"]=$name;break;} }
foreach ($branches as $id => $name) { $n = smartNormalize($name); if ($n && mb_strpos($cleanQuery,$n)!==false){$entities["{branch}"]=$name;break;} }
foreach ($regions  as $id => $name) { $n = smartNormalize($name); if ($n && mb_strpos($cleanQuery,$n)!==false){$entities["{region}"]=$name;break;} }
foreach ($clients  as $id => $name) { $n = smartNormalize($name); if ($n && mb_strpos($cleanQuery,$n)!==false){$entities["{client}"]=$name;break;} }
foreach ($agents   as $id => $name) { $n = smartNormalize($name); if ($n && mb_strpos($cleanQuery,$n)!==false){$entities["{agent}"]=$name;break;} }

/* ──────────────────────────────────────────────────────
   3. كشف النية (intent detection)
   ────────────────────────────────────────────────────── */
$foundIntent = "";

// ── لوحة التحكم / الملخص ──────────────────────────────
if (preg_match('/لوحه.*تحكم|لوحة.*تحكم|dashboard|ملخص.*نظام|نظرة.*عامه|نظرة.*عامة|موجز|تقرير.*شامل|تقرير.*عام|إحصاءات.*عامه|إحصاءات.*عامة/iu', $query)) {
    $foundIntent = 'show_dashboard';
}

// ── المستخدمون ───────────────────────────────────────
if (!$foundIntent && preg_match('/مستخدم|موظف.*نظام/iu', $query)) {
    if      (preg_match('/عدد|إحصاء|احصاء|كم/iu', $query)) {
        if      (preg_match('/نشط|نشطين|فعال/iu', $query))       $foundIntent = 'count_active_users';
        elseif  (preg_match('/غير|موقوف|معطل/iu', $query))       $foundIntent = 'count_inactive_users';
        else                                                       $foundIntent = 'count_users';
    }
    elseif  (preg_match('/دور|ادوار|أدوار|صلاحية/iu', $query))  $foundIntent = 'show_users_by_role';
    elseif  (preg_match('/معلومات|بيانات/iu', $query))           $foundIntent = 'show_user_details';
    elseif  (preg_match('/غير.*نشط|موقوف|معطل/iu', $query))     $foundIntent = 'show_inactive_users';
    elseif  (preg_match('/قائمه|قائمة|عرض|جميع/iu', $query))    $foundIntent = 'show_all_users';
    else                                                           $foundIntent = 'count_users';
}

// ── العملاء ──────────────────────────────────────────
if (!$foundIntent && preg_match('/عميل|عملاء|زبائن|زبون/iu', $query)) {
    if (preg_match('/عدد|إحصاء|احصاء|كم/iu', $query)) {
        if      (preg_match('/نشط|نشطين/iu', $query))            $foundIntent = 'count_active_clients';
        elseif  (preg_match('/نوع|فئه|فئة|تصنيف/iu', $query))   $foundIntent = 'count_clients_by_type';
        elseif  (preg_match('/فرد|أفراد/iu', $query))            $foundIntent = 'count_clients_individual';
        elseif  (preg_match('/شركه|شركة|شركات/iu', $query))      $foundIntent = 'count_clients_company';
        elseif  (preg_match('/حكومي|حكومية/iu', $query))         $foundIntent = 'count_clients_government';
        else                                                       $foundIntent = 'count_clients';
    }
    elseif (preg_match('/نوع|تصنيف/iu', $query))                 $foundIntent = 'count_clients_by_type';
    elseif (preg_match('/فرد|أفراد/iu', $query))                 $foundIntent = 'count_clients_individual';
    elseif (preg_match('/شركه|شركة|شركات/iu', $query))           $foundIntent = 'count_clients_company';
    elseif (preg_match('/حكومي/iu', $query))                     $foundIntent = 'count_clients_government';
    elseif (preg_match('/غير.*نشط|معطل/iu', $query))            $foundIntent = 'show_inactive_clients';
    elseif (preg_match('/جهه.*اتصال|جهة.*اتصال|معلومات.*اتصال/iu', $query)) $foundIntent = 'show_client_contacts';
    elseif (preg_match('/بلاغ/iu', $query))                      $foundIntent = 'show_tickets_by_client';
    elseif (preg_match('/معلومات|بيانات/iu', $query))            $foundIntent = 'show_client_details';
    elseif (preg_match('/قائمه|قائمة|عرض|جميع/iu', $query))     $foundIntent = 'show_all_clients';
    else                                                           $foundIntent = 'count_clients';
}

// ── البلاغات ─────────────────────────────────────────
if (!$foundIntent && preg_match('/بلاغ|بلاغات|تذكر|تذاكر/iu', $query)) {

    // بلاغاتي أولاً (المستخدم الحالي)
    if (preg_match('/بلاغاتي|تذاكري|المسند.*إليّ|إليّ|لي$/iu', $query)) {
        $foundIntent = 'show_my_tickets';
    }
    // حسب الفترة الزمنية
    elseif (preg_match('/اليوم/iu', $query))                      $foundIntent = 'count_tickets_today';
    elseif (preg_match('/أسبوع|هذا.*أسبوع/iu', $query))          $foundIntent = 'count_tickets_week';
    elseif (preg_match('/شهر|هذا.*شهر/iu', $query))              $foundIntent = 'count_tickets_month';
    // SLA
    elseif (preg_match('/مخالف|متأخر|sla|خرق|تجاوز/iu', $query)){
        if (preg_match('/إحصاء|تقرير|أداء|معدل|نسبه|نسبة/iu', $query)) $foundIntent = 'show_sla_stats';
        else                                                        $foundIntent = 'count_sla_breached';
    }
    elseif (preg_match('/SLA.*إحصاء|إحصاء.*SLA|أداء.*SLA|تقرير.*SLA/iu', $query)) $foundIntent = 'show_sla_stats';
    // عاجل / أولوية عالية
    elseif (preg_match('/عاجل|ملح|طارئ/iu', $query))             $foundIntent = 'count_urgent_tickets';
    elseif (preg_match('/عالي.*أولوية|أولوية.*عالي/iu', $query)) $foundIntent = 'count_high_priority_tickets';
    // مصعدة
    elseif (preg_match('/مصعد/iu', $query))                       $foundIntent = 'count_escalated_tickets';
    // متوسط وقت الحل
    elseif (preg_match('/متوسط.*وقت|معدل.*وقت|مده.*حل|مدة.*حل|كم.*يستغرق/iu', $query)) $foundIntent = 'show_avg_resolution_time';
    // أحدث
    elseif (preg_match('/أحدث|آخر|اخر/iu', $query))              $foundIntent = 'show_latest_tickets';
    // بلاغات عميل
    elseif ($entities['{client}'] !== '' && preg_match('/عميل/iu', $query)) $foundIntent = 'show_tickets_by_client';
    // عدد / إحصاء
    elseif (preg_match('/عدد|إحصاء|احصاء|كم/iu', $query)) {
        if      (preg_match('/معلق/iu', $query))                  $foundIntent = 'count_pending_tickets';
        elseif  (preg_match('/قيد.*تنفيذ|جاري|مفتوح/iu', $query))$foundIntent = 'count_in_progress_tickets';
        elseif  (preg_match('/منتهي|محلول|مغلق/iu', $query))     $foundIntent = 'count_resolved_tickets';
        elseif  (preg_match('/ملغي|ملغاه|ملغاة/iu', $query))     $foundIntent = 'count_cancelled_tickets';
        elseif  (preg_match('/أولوية/iu', $query))                $foundIntent = 'count_tickets_by_priority';
        elseif  (preg_match('/حاله|حالة/iu', $query))            $foundIntent = 'count_tickets_by_status';
        elseif  (preg_match('/فرع/iu', $query))                   $foundIntent = 'count_tickets_by_branch';
        elseif  (preg_match('/فئه|فئة|نوع|تصنيف/iu', $query))   $foundIntent = 'count_tickets_by_category';
        elseif  (preg_match('/منطقه|منطقة/iu', $query))          $foundIntent = 'count_tickets_by_region';
        else                                                        $foundIntent = 'count_tickets';
    }
    // حالة مباشرة
    elseif (preg_match('/معلق/iu', $query))                       $foundIntent = 'count_pending_tickets';
    elseif (preg_match('/قيد.*تنفيذ|جاري|مفتوح/iu', $query))    $foundIntent = 'count_in_progress_tickets';
    elseif (preg_match('/منتهي|محلول|مغلق/iu', $query))          $foundIntent = 'count_resolved_tickets';
    elseif (preg_match('/ملغي|ملغاه|ملغاة/iu', $query))          $foundIntent = 'count_cancelled_tickets';
    elseif (preg_match('/أولوية/iu', $query))                     $foundIntent = 'count_tickets_by_priority';
    elseif (preg_match('/فرع/iu', $query))                        $foundIntent = 'count_tickets_by_branch';
    elseif (preg_match('/فئه|فئة|نوع|تصنيف/iu', $query))        $foundIntent = 'count_tickets_by_category';
    elseif (preg_match('/منطقه|منطقة/iu', $query))               $foundIntent = 'count_tickets_by_region';
    elseif (preg_match('/حاله|حالة/iu', $query))                 $foundIntent = 'count_tickets_by_status';
    else                                                            $foundIntent = 'count_tickets';
}

// ── المهام / أوامر العمل ─────────────────────────────
if (!$foundIntent && preg_match('/مهمه|مهمة|مهام|أمر.*عمل|أوامر.*عمل/iu', $query)) {

    // مهامي
    if (preg_match('/مهامي|مهامك|المسند.*إليّ/iu', $query)) {
        if (preg_match('/عدد|كم/iu', $query)) $foundIntent = 'count_my_tasks';
        else                                   $foundIntent = 'show_my_tasks';
    }
    // متأخرة
    elseif (preg_match('/متأخر|فات.*موعد|تجاوز.*موعد/iu', $query)) {
        if (preg_match('/عدد|كم/iu', $query)) $foundIntent = 'count_overdue_tasks';
        else                                   $foundIntent = 'show_overdue_tasks';
    }
    // أحدث
    elseif (preg_match('/أحدث|آخر|اخر/iu', $query))               $foundIntent = 'show_latest_work_orders';
    // توزيع / مستخدم
    elseif (preg_match('/مستخدم|موظف|توزيع|أعباء/iu', $query))   $foundIntent = 'count_tasks_by_user';
    // إحصاء
    elseif (preg_match('/عدد|كم/iu', $query)) {
        if      (preg_match('/منجز|مكتمل|مغلق/iu', $query))      $foundIntent = 'count_completed_tasks';
        elseif  (preg_match('/معلق/iu', $query))                  $foundIntent = 'count_pending_tasks';
        elseif  (preg_match('/قيد.*تنفيذ|جاري/iu', $query))      $foundIntent = 'count_in_progress_tasks';
        elseif  (preg_match('/ملغي|ملغاه|ملغاة/iu', $query))     $foundIntent = 'count_cancelled_tasks';
        elseif  (preg_match('/حرج|critical/iu', $query))          $foundIntent = 'count_critical_tasks';
        elseif  (preg_match('/أولوية/iu', $query))                $foundIntent = 'count_tasks_by_priority';
        else                                                        $foundIntent = 'count_work_orders';
    }
    elseif (preg_match('/منجز|مكتمل/iu', $query))                 $foundIntent = 'count_completed_tasks';
    elseif (preg_match('/معلق/iu', $query))                       $foundIntent = 'count_pending_tasks';
    elseif (preg_match('/قيد.*تنفيذ|جاري/iu', $query))           $foundIntent = 'count_in_progress_tasks';
    elseif (preg_match('/ملغي|ملغاه|ملغاة/iu', $query))          $foundIntent = 'count_cancelled_tasks';
    elseif (preg_match('/حرج|critical/iu', $query))               $foundIntent = 'count_critical_tasks';
    elseif (preg_match('/أولوية/iu', $query))                     $foundIntent = 'count_tasks_by_priority';
    else                                                            $foundIntent = 'count_work_orders';
}

// ── المندوبون ─────────────────────────────────────────
if (!$foundIntent && preg_match('/مندوب/iu', $query)) {
    if (preg_match('/عدد|كم/iu', $query)) {
        if (preg_match('/نشط/iu', $query)) $foundIntent = 'count_active_agents';
        else                                $foundIntent = 'count_agents';
    }
    elseif (preg_match('/أفضل|أكثر|ترتيب|نشاط/iu', $query))     $foundIntent = 'show_top_agents';
    elseif (preg_match('/توزيع|تعيين|تكليف|زيارة/iu', $query))  $foundIntent = 'show_agent_assignments';
    elseif (preg_match('/فرع/iu', $query))                        $foundIntent = 'show_agents_by_branch';
    elseif (preg_match('/قائمه|قائمة|عرض|جميع/iu', $query))     $foundIntent = 'show_all_agents';
    else                                                            $foundIntent = 'count_agents';
}

// ── الفروع ───────────────────────────────────────────
if (!$foundIntent && preg_match('/فرع|فروع/iu', $query)) {
    if      (preg_match('/عدد|كم/iu', $query))                    $foundIntent = 'count_branches';
    elseif  (preg_match('/معلومات|بيانات|تفاصيل/iu', $query))    $foundIntent = 'show_branch_details';
    else                                                            $foundIntent = 'show_branches';
}

// ── المناطق ──────────────────────────────────────────
if (!$foundIntent && preg_match('/منطقه|منطقة|مناطق/iu', $query)) {
    if (preg_match('/عدد|كم/iu', $query)) $foundIntent = 'count_regions';
    else                                   $foundIntent = 'show_regions';
}

// ── الأقسام ──────────────────────────────────────────
if (!$foundIntent && preg_match('/قسم|أقسام/iu', $query)) {
    if (preg_match('/عدد|كم/iu', $query)) $foundIntent = 'count_departments';
    else                                   $foundIntent = 'show_departments_by_region';
}

// ── المستندات ─────────────────────────────────────────
if (!$foundIntent && preg_match('/مستند|مستندات|ملف|وثيقه|وثيقة/iu', $query)) {
    if      (preg_match('/عدد|كم/iu', $query)) {
        if (preg_match('/معتمد/iu', $query)) $foundIntent = 'count_approved_docs';
        elseif (preg_match('/حاله|حالة/iu', $query)) $foundIntent = 'count_documents_by_status';
        else   $foundIntent = 'count_documents';
    }
    elseif  (preg_match('/أحدث|آخر/iu', $query))                  $foundIntent = 'show_latest_documents';
    elseif  (preg_match('/مسوده|مسودة/iu', $query))               $foundIntent = 'show_draft_documents';
    elseif  (preg_match('/معتمد/iu', $query))                     $foundIntent = 'count_approved_docs';
    elseif  (preg_match('/حاله|حالة/iu', $query))                 $foundIntent = 'count_documents_by_status';
    else                                                            $foundIntent = 'count_documents';
}

// ── الموظفون ─────────────────────────────────────────
if (!$foundIntent && preg_match('/موظف/iu', $query) && !preg_match('/نظام/iu', $query)) {
    if      (preg_match('/توقيع|وقّع|يوقع|موقع/iu', $query))     $foundIntent = 'count_signing_employees';
    elseif  (preg_match('/عدد|كم/iu', $query))                    $foundIntent = 'count_employees';
    else                                                            $foundIntent = 'count_employees';
}

// ── الرسائل ──────────────────────────────────────────
if (!$foundIntent && preg_match('/رساله|رسالة|بريد/iu', $query)) {
    if (preg_match('/غير.*مقروء|جديد|واردة/iu', $query))         $foundIntent = 'count_unread_messages';
}

// ── الإشعارات ─────────────────────────────────────────
if (!$foundIntent && preg_match('/إشعار|اشعار/iu', $query)) {
    if (preg_match('/غير.*مقروء|جديد/iu', $query))               $foundIntent = 'count_unread_notifications';
}

// ── فئات المشكلات ─────────────────────────────────────
if (!$foundIntent && preg_match('/فئات|فئه|فئة|تصنيفات|مشكلات|مشكله|مشكلة|أعطال/iu', $query)) {
    $foundIntent = 'show_issue_categories';
}

// ── إحصاءات SLA (بدون كلمة "بلاغات") ───────────────
if (!$foundIntent && preg_match('/sla/iu', $query)) {
    if (preg_match('/إحصاء|تقرير|أداء|معدل|نسبه|نسبة/iu', $query)) $foundIntent = 'show_sla_stats';
    else                                                              $foundIntent = 'count_sla_breached';
}

/* ──────────────────────────────────────────────────────
   4. Fallback → البحث في جدول ai_questions
   ────────────────────────────────────────────────────── */
if (empty($foundIntent)) {
    $stmtFb = $pdo->prepare(
        "SELECT intent FROM ai_questions
         WHERE ? LIKE CONCAT('%', question, '%')
            OR question LIKE ?
         ORDER BY LENGTH(question) DESC
         LIMIT 1"
    );
    $stmtFb->execute([$cleanQuery, "%$cleanQuery%"]);
    $foundIntent = $stmtFb->fetchColumn();
}

/* ──────────────────────────────────────────────────────
   5. تنفيذ الاستعلام وبناء الرد
   ────────────────────────────────────────────────────── */
if ($foundIntent) {
    $stmtBot = $pdo->prepare(
        "SELECT * FROM ai_training WHERE intent = ? AND is_active = 1 LIMIT 1"
    );
    $stmtBot->execute([$foundIntent]);
    $bot = $stmtBot->fetch(PDO::FETCH_ASSOC);

    if ($bot && !empty($bot['sql_query'])) {
        $finalSQL = $bot['sql_query'];

        // استبدال المتغيرات
        foreach ($entities as $key => $val) {
            if ($key === '{current_user_id}') continue;
            $finalSQL = str_replace($key, $val ?: "''", $finalSQL);
        }
        $finalSQL = str_replace('{current_user_id}', $current_user_id, $finalSQL);

        try {
            $stmtData = $pdo->query($finalSQL);
            $results  = $stmtData->fetchAll(PDO::FETCH_ASSOC);

            if (!$results) {
                die("لم أجد بيانات حالياً بخصوص طلبك.");
            }

            $response = str_replace(
                array_keys($entities),
                array_map(fn($v) => $v ?: '', $entities),
                $bot['expected_response']
            ) . "\n\n";

            foreach ($results as $row) {
                foreach ($row as $k => $v) {
                    $response .= "  $k : $v\n";
                }
                $response .= "--------------------\n";
            }
            die(trim($response));
        } catch (Exception $e) {
            error_log('[AI] SQL error for intent=' . $foundIntent . ': ' . $e->getMessage());
            die("خطأ تقني في تنفيذ الاستعلام. يُرجى التواصل مع الدعم التقني.");
        }
    }
}

/* ──────────────────────────────────────────────────────
   6. الرد الافتراضي
   ────────────────────────────────────────────────────── */
die("عذراً، لم أفهم طلبك تماماً. جرب أحد هذه الأمثلة:\n\n" .
    "• 'كم عدد البلاغات؟'\n" .
    "• 'البلاغات العاجلة'\n" .
    "• 'بلاغات اليوم'\n" .
    "• 'مهامي'\n" .
    "• 'ملخص النظام'\n" .
    "• 'عدد العملاء النشطين'\n" .
    "• 'إحصاءات SLA'\n" .
    "• 'عرض المندوبين'\n" .
    "• 'أحدث البلاغات'");
