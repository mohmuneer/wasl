-- ═══════════════════════════════════════════════════════════════
--  قاعدة المعرفة – Knowledge Base Migration
--  Run: mysql -u root wasl < wasl_kb_migration.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── تصنيفات المقالات ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kb_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    description TEXT,
    icon        VARCHAR(60)  DEFAULT 'fas fa-folder',
    color       VARCHAR(20)  DEFAULT '#1a5276',
    parent_id   INT UNSIGNED DEFAULT NULL,
    sort_order  SMALLINT     DEFAULT 0,
    is_active   TINYINT(1)   DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_parent (parent_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── المقالات ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kb_articles (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id  INT UNSIGNED NOT NULL,
    title        VARCHAR(300) NOT NULL,
    slug         VARCHAR(320) NOT NULL,
    summary      TEXT,
    content      LONGTEXT     NOT NULL,
    tags         VARCHAR(500) DEFAULT '',
    status       ENUM('draft','published') DEFAULT 'draft',
    featured     TINYINT(1)  DEFAULT 0,
    views        INT UNSIGNED DEFAULT 0,
    helpful_yes  INT UNSIGNED DEFAULT 0,
    helpful_no   INT UNSIGNED DEFAULT 0,
    created_by   INT UNSIGNED DEFAULT NULL,
    updated_by   INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_slug (slug),
    INDEX idx_category (category_id),
    INDEX idx_status   (status),
    INDEX idx_featured (featured),
    INDEX idx_created  (created_at),
    FULLTEXT idx_ft_search (title, summary, tags)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── تقييمات المستخدمين ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS kb_feedback (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    is_helpful TINYINT(1)  NOT NULL,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_user_article (user_id, article_id),
    INDEX idx_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── بيانات افتراضية: التصنيفات ───────────────────────────────────
INSERT IGNORE INTO kb_categories (id, name, description, icon, color, sort_order) VALUES
(1, 'الأسئلة الشائعة',      'الأسئلة المتكررة والإجابات عليها',          'fas fa-question-circle', '#2980b9', 1),
(2, 'دليل المستخدم',        'تعليمات الاستخدام خطوة بخطوة',               'fas fa-book-open',       '#27ae60', 2),
(3, 'استكشاف الأخطاء',      'حل المشكلات الشائعة',                        'fas fa-tools',           '#e74c3c', 3),
(4, 'السياسات والإجراءات',  'اللوائح والإجراءات الرسمية للشركة',           'fas fa-file-alt',        '#8e44ad', 4),
(5, 'التحديثات والأخبار',   'آخر التحديثات والإعلانات',                    'fas fa-bullhorn',        '#f39c12', 5);
