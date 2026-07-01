<?php
/**
 * ثوابت أسماء الجداول – نظام وَصْل
 * استخدم هذه الثوابت في جميع استعلامات PHP بدلاً من الأسماء المباشرة
 * مثال: "SELECT * FROM " . TBL_TICKETS
 * 
 * ملاحظة: جميع الثوابت محمية بـ defined() لتجنب أخطاء التحميل المزدوج.
 */

if (!defined('TBL_ROLES')) {

// ─── جداول النظام ─────────────────────────────────────────────
define('TBL_ROLES',             'sys_roles');
define('TBL_USERS',             'sys_users');
define('TBL_SETTINGS',          'sys_settings');
define('TBL_THEME',             'sys_theme');
define('TBL_MENU',              'sys_menu');
define('TBL_PERMISSIONS',       'sys_permissions');
define('TBL_AUDIT_LOGS',        'audit_logs');
define('TBL_NOTIFICATIONS',     'notifications');

// ─── جداول التحكم بالوصول ────────────────────────────────────
define('TBL_USER_ROLES',            'user_roles');
define('TBL_USER_BRANCH_ACCESS',    'user_branch_access');
define('TBL_USER_CATEGORY_ACCESS',  'user_category_access');
define('TBL_USER_MENU_ACCESS',      'user_menu_access');

// ─── جداول الهيكل التنظيمي ───────────────────────────────────
define('TBL_BRANCHES',          'branches');
define('TBL_REGIONS',           'regions');
define('TBL_DEPARTMENTS',       'departments');
define('TBL_ISSUE_CATEGORIES',  'issue_categories');

// ─── جداول الموظفين الداخليين (مقدمو الطلبات) ──────────────────
define('TBL_CLIENTS',           'clients');        // الموظفون الداخليون مقدمو البلاغات
define('TBL_CLIENT_CONTACTS',   'client_contacts'); // جهات اتصال الموظفين
// TBL_AGENTS و TBL_AGENT_ASSIGNMENTS: محذوفة – تم استبدالها بـ sys_users

// ─── جداول التذاكر والمهام ───────────────────────────────────
define('TBL_TICKETS',               'tickets');
define('TBL_TICKET_COMMENTS',       'ticket_comments');
define('TBL_TICKET_ATTACHMENTS',    'ticket_attachments');
define('TBL_WORK_ORDERS',           'work_orders');
define('TBL_SLA_RULES',             'sla_rules');

// ─── التواصل ─────────────────────────────────────────────────
define('TBL_MESSAGES',          'messages');

// ─── الذكاء الاصطناعي ────────────────────────────────────────
define('TBL_AI_SESSIONS',       'ai_sessions');
define('TBL_AI_TRAINING',       'ai_training');
define('TBL_AI_QUESTIONS',      'ai_questions');

// ─── نظام إدارة الوثائق (DMS) ────────────────────────────────
define('TBL_DOCUMENTS',         'dms_documents');
define('TBL_DOC_TYPES',        'dms_document_types');
define('TBL_DOC_CATEGORIES',   'dms_categories');
define('TBL_SIGNATURES',       'dms_signatures');
define('TBL_EMPLOYEES',        'dms_employees');
define('TBL_DOC_VERSIONS',     'dms_versions');
define('TBL_JOB_POSITIONS',    'job_positions');
define('TBL_APPROVAL_WORKFLOWS', 'approval_workflows');
define('TBL_APPROVAL_STAGES',    'approval_stages');
define('TBL_DOC_APPROVALS',     'dms_document_approvals');

// ─── نظام تتبع الأصول والصيانة ───────────────────────────────────
define('TBL_ASSETS',                'assets');
define('TBL_ASSET_CATEGORIES',      'asset_categories');
define('TBL_MAINTENANCE_SCHEDULES', 'maintenance_schedules');
define('TBL_MAINTENANCE_LOGS',      'maintenance_logs');

// ─── ERP (لا تعديل على أسمائها) ──────────────────────────────
define('TBL_ERP_ACCOUNTS',      'GNR_ACCNT_TREE');
define('TBL_ERP_UNITS',         'GNR_FN_UNT');
define('TBL_ERP_POSTINGS',      'GNR_PST_DTL');

// ─── ثوابت الصفحات ───────────────────────────────────────────
define('ITEMS_PER_PAGE', 25);         // عدد الصفوف في كل صفحة
define('CACHE_TTL_SHORT',  120);      // ثانيتان (للوحة التحكم)
define('CACHE_TTL_MEDIUM', 600);      // 10 دقائق (للقوائم المنسدلة)
define('CACHE_TTL_LONG',  3600);      // ساعة (للإعدادات الثابتة)

}
