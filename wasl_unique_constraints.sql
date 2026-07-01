-- =============================================================
-- إضافة قيود UNIQUE لمنع تكرار البيانات في الهيكل الإداري
-- =============================================================

-- 1. الفروع: منع تكرار اسم الفرع
ALTER TABLE branches ADD UNIQUE INDEX uq_branch_name (branch_name);

-- 2. المناطق: منع تكرار اسم المنطقة داخل نفس الفرع
ALTER TABLE regions ADD UNIQUE INDEX uq_region_branch (region_name, branch_id);

-- 3. الأقسام: منع تكرار اسم القسم داخل نفس المنطقة
ALTER TABLE departments ADD UNIQUE INDEX uq_dept_region (department_name, region_id);

-- 4. تصنيفات المشاكل: منع تكرار اسم التصنيف
ALTER TABLE issue_categories ADD UNIQUE INDEX uq_category_name (category_name);

-- 5. المسميات الوظيفية: منع تكرار المسمى الوظيفي داخل نفس القسم
ALTER TABLE job_positions ADD UNIQUE INDEX uq_job_dept (department_id, job_title);
