<?php
header('Content-Type: text/plain; charset=utf-8');

$host   = '127.0.0.1';
$user   = 'root';
$pass   = '';
$dbname = 'Shatt_al_Arab';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
  die("فشل الاتصال بقاعدة البيانات: " . $conn->connect_error . PHP_EOL);
}
$conn->set_charset("utf8mb4");

try {
  $conn->query("SET SESSION sql_mode = CONCAT_WS(',', @@sql_mode, 'STRICT_TRANS_TABLES','NO_ZERO_DATE','NO_ZERO_IN_DATE')");
  echo "تم تفعيل وضع STRICT/NO_ZERO_DATE للجلسة." . PHP_EOL;
} catch (Throwable $e) {
  echo "(تنبيه) تعذر ضبط sql_mode للجلسة: {$e->getMessage()}" . PHP_EOL;
}

if ($conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
  echo "تم إنشاء/اختيار قاعدة البيانات ($dbname)" . PHP_EOL;
} else {
  die("خطأ في إنشاء القاعدة: {$conn->error}" . PHP_EOL);
}
$conn->select_db($dbname);

function alter_silent(mysqli $conn, string $sql, string $ok_msg) {
  try {
    if ($conn->query($sql)) {
      echo "$ok_msg" . PHP_EOL;
    } else {
      echo "تعذر تنفيذ: $ok_msg — {$conn->error}" . PHP_EOL;
    }
  } catch (mysqli_sql_exception $e) {
    $msg = $e->getMessage();
    if (stripos($msg,'exists') !== false ||
        stripos($msg,'duplicate') !== false ||
        stripos($msg,'check constraint') !== false ||
        stripos($msg,'unknown constraint') !== false ||
        stripos($msg,'errno: 3812') !== false ||
        stripos($msg,'errno: 1091') !== false) {
      echo "(تخطي) $ok_msg — {$msg}" . PHP_EOL;
    } else {
      echo "تعذر تنفيذ: $ok_msg — {$msg}" . PHP_EOL;
    }
  }
}

function drop_unique_on_column_if_exists(mysqli $conn, string $table, string $column) {
  $idx = $conn->query("SHOW INDEX FROM `$table` WHERE Column_name = '$column' AND Non_unique = 0");
  if ($idx && $idx->num_rows > 0) {
    while ($row = $idx->fetch_assoc()) {
      $key = $row['Key_name'];
      if (strtolower($key) !== 'primary') {
        alter_silent($conn, "ALTER TABLE `$table` DROP INDEX `$key`", "حذف UNIQUE '$key' عن $column");
      }
    }
  }
  if ($idx) $idx->close();
}

function drop_stage_checks_if_any(mysqli $conn) {
  $candidates = ['chk_stage_valid', 'users_chk_1', 'users_chk_stage', 'chk_1', 'stage_chk'];
  foreach ($candidates as $c) {
    alter_silent($conn, "ALTER TABLE `users` DROP CHECK `$c`", "محاولة حذف CHECK `$c`");
    alter_silent($conn, "ALTER TABLE `users` DROP CONSTRAINT `$c`", "محاولة حذف CONSTRAINT `$c`");
  }

  try {
    $sql = "
      SELECT tc.CONSTRAINT_NAME
      FROM information_schema.TABLE_CONSTRAINTS tc
      WHERE tc.TABLE_SCHEMA = DATABASE()
        AND tc.TABLE_NAME = 'users'
        AND tc.CONSTRAINT_TYPE = 'CHECK'
    ";
    if ($res = $conn->query($sql)) {
      while ($row = $res->fetch_assoc()) {
        $name = $row['CONSTRAINT_NAME'];
        alter_silent($conn, "ALTER TABLE `users` DROP CHECK `$name`", "حذف CHECK مكتشف: `$name`");
        alter_silent($conn, "ALTER TABLE `users` DROP CONSTRAINT `$name`", "حذف CONSTRAINT مكتشف: `$name`");
      }
      $res->close();
    }
  } catch (mysqli_sql_exception $e) {
    echo "(تخطي) فحص information_schema CHECKs — {$e->getMessage()}" . PHP_EOL;
  }
}

function table_exists(mysqli $conn, string $table): bool {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $stmt->bind_param("s", $table);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($res['c'] ?? 0) > 0;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
  $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->bind_param("ss", $table, $col);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($res['c'] ?? 0) > 0;
}

function drop_all_fks_on_table(mysqli $conn, string $table): void {
  try {
    $stmt = $conn->prepare("
      SELECT CONSTRAINT_NAME
      FROM information_schema.TABLE_CONSTRAINTS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
      $fk = $row['CONSTRAINT_NAME'];
      alter_silent($conn, "ALTER TABLE `$table` DROP FOREIGN KEY `$fk`", "حذف FK `$fk` من `$table`");
    }
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    echo "(تنبيه) تعذر فحص/حذف مفاتيح FK من $table — {$e->getMessage()}" . PHP_EOL;
  }
}

function drop_all_unique_indexes_on_table(mysqli $conn, string $table): void {
  try {
    $res = $conn->query("SHOW INDEX FROM `$table` WHERE Non_unique = 0");
    if ($res) {
      $seen = [];
      while ($row = $res->fetch_assoc()) {
        $key = $row['Key_name'];
        if (strtolower($key) === 'primary') continue;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        alter_silent($conn, "ALTER TABLE `$table` DROP INDEX `$key`", "حذف UNIQUE INDEX `$key` من `$table`");
      }
      $res->close();
    }
  } catch (mysqli_sql_exception $e) {
    echo "(تنبيه) تعذر فحص/حذف UNIQUE INDEX من $table — {$e->getMessage()}" . PHP_EOL;
  }
}

$sql_roles = "
CREATE TABLE IF NOT EXISTS roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_roles)) {
  echo "تم التأكد من وجود جدول الأدوار (roles)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول roles: {$conn->error}" . PHP_EOL;
}

$seed_roles = [
  ['student','طالب'],
  ['instructor','أستاذ'],
  ['admin','مدير']
];
$insRole = $conn->prepare("INSERT IGNORE INTO roles (role_name, description) VALUES (?, ?)");
if ($insRole) {
  foreach ($seed_roles as $r) {
    $insRole->bind_param("ss", $r[0], $r[1]);
    $insRole->execute();
  }
  $insRole->close();
}

$sql_users_create = "
CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50)  NOT NULL,
  email    VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT NULL,
  stage VARCHAR(20) NULL,
  university_number VARCHAR(50) NULL,
  study_type ENUM('صباحي','مسائي') NULL,
  job_grade VARCHAR(100) NULL,
  specialization VARCHAR(150) NULL,
  academic_year VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role
    FOREIGN KEY (role_id) REFERENCES roles(role_id)
    ON DELETE SET NULL
    ON UPDATE CASCADE,
  CONSTRAINT chk_stage_valid
    CHECK (stage IS NULL OR stage IN ('الأولى','الثانية','الثالثة','الرابعة'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_users_create)) {
  echo "تم التأكد من وجود جدول المستخدمين (users)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول users: {$conn->error}" . PHP_EOL;
}

echo "بدء ترقية مخطط users..." . PHP_EOL;
alter_silent($conn, "ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز users إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `users` MODIFY `stage` VARCHAR(20) NULL", "تعديل stage إلى VARCHAR(20)");
drop_stage_checks_if_any($conn);
alter_silent(
  $conn,
  "ALTER TABLE `users`
     ADD CONSTRAINT `chk_stage_valid`
     CHECK (`stage` IS NULL OR `stage` IN ('الأولى','الثانية','الثالثة','الرابعة'))",
  "إضافة CHECK عربي على stage"
);
alter_silent($conn, "
  UPDATE `users` SET `stage` = CASE TRIM(`stage`)
    WHEN '1' THEN 'الأولى' WHEN '2' THEN 'الثانية'
    WHEN '3' THEN 'الثالثة' WHEN '4' THEN 'الرابعة'
    WHEN  1  THEN 'الأولى' WHEN  2  THEN 'الثانية'
    WHEN  3  THEN 'الثالثة' WHEN  4  THEN 'الرابعة'
    ELSE `stage` END
  WHERE `stage` REGEXP '^[1-4]$' OR `stage` IN (1,2,3,4)
", "ترحيل قيم stage الرقمية إلى مسميات عربية");

alter_silent($conn, "UPDATE `users` SET `study_type`='صباحي' WHERE `study_type`='morning'", "تحويل study_type=morning إلى صباحي");
alter_silent($conn, "UPDATE `users` SET `study_type`='مسائي' WHERE `study_type`='evening'", "تحويل study_type=evening إلى مسائي");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `study_type` VARCHAR(20) NULL", "تأكيد عمود study_type");
alter_silent($conn, "ALTER TABLE `users` MODIFY `study_type` ENUM('صباحي','مسائي') NULL", "تعديل نوع study_type إلى ENUM عربي");

alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NOT NULL", "تأكيد username");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) NOT NULL", "تأكيد email");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `university_number` VARCHAR(50) NULL", "تأكيد university_number");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `job_grade` VARCHAR(100) NULL", "تأكيد job_grade");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `specialization` VARCHAR(150) NULL", "تأكيد specialization");
alter_silent($conn, "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `academic_year` VARCHAR(50) NULL", "تأكيد academic_year");

drop_unique_on_column_if_exists($conn, 'users', 'username');

alter_silent($conn, "ALTER TABLE `users` ADD UNIQUE INDEX IF NOT EXISTS `ux_users_email` (`email`)", "UNIQUE للبريد ux_users_email");
alter_silent($conn, "ALTER TABLE `users` ADD UNIQUE INDEX IF NOT EXISTS `ux_users_university_number` (`university_number`)", "UNIQUE للرقم الجامعي ux_users_university_number");

$need_fk = true;
try {
  $res = $conn->query("
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND REFERENCED_TABLE_NAME = 'roles'
      AND REFERENCED_COLUMN_NAME = 'role_id'
  ");
  if ($res && $res->num_rows > 0) { $need_fk = false; }
  if ($res) $res->close();
} catch (mysqli_sql_exception $e) {
  echo "(تنبيه) تعذر فحص FK users→roles: {$e->getMessage()}" . PHP_EOL;
}
if ($need_fk) {
  alter_silent($conn, "ALTER TABLE `users` DROP FOREIGN KEY `fk_users_role`", "إزالة FK قديم fk_users_role (إن وجد)");
  alter_silent(
    $conn,
    "ALTER TABLE `users`
     ADD CONSTRAINT `fk_users_role`
     FOREIGN KEY (`role_id`) REFERENCES `roles`(`role_id`)
     ON DELETE SET NULL ON UPDATE CASCADE",
    "إضافة المفتاح الأجنبي fk_users_role"
  );
}
echo "✓ تمت مزامنة مخطط (users)." . PHP_EOL;

$sql_students_create = "
CREATE TABLE IF NOT EXISTS students (
  student_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  age TINYINT UNSIGNED NOT NULL,
  email VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  address TEXT NOT NULL,
  university_number VARCHAR(50) NOT NULL,
  birth_date DATE NOT NULL,
  study_type ENUM('صباحي','مسائي') NOT NULL,
  academic_year VARCHAR(50) NOT NULL,
  stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL,
  gender ENUM('ذكر','أنثى') NOT NULL,
  marital_status ENUM('أعزب','متزوج','مطلق','أرمل') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `uq_students_user` UNIQUE (`user_id`),
  CONSTRAINT `fk_students_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_birthdate_valid`
    CHECK (birth_date >= '1900-01-01')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_students_create)) {
  echo "تم التأكد من وجود جدول الطلاب (students)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول students: {$conn->error}" . PHP_EOL;
}

$sql_teachers="
CREATE TABLE IF NOT EXISTS teachers(
  teacher_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL,
  specialization VARCHAR(200) NOT NULL,
  job_grade VARCHAR(100) NOT NULL,
  academic_year VARCHAR(50) NOT NULL,
  phone VARCHAR(30) NULL,
  address TEXT NULL,
  gender ENUM('ذكر','أنثى') NULL,
  marital_status ENUM('أعزب','متزوج','مطلق','أرمل') NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(user_id),
  CONSTRAINT fk_teachers_user FOREIGN KEY(user_id)
    REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if($conn->query($sql_teachers)){
  echo "تم إنشاء/تأكيد جدول الأساتذة (teachers) بنجاح" . PHP_EOL;
}else{
  echo "خطأ إنشاء teachers: {$conn->error}" . PHP_EOL;
}

alter_silent($conn, "ALTER TABLE `teachers` DROP COLUMN IF EXISTS `academic_rank`", "حذف العمود academic_rank (إن وجد)");
alter_silent($conn, "ALTER TABLE `teachers` DROP COLUMN IF EXISTS `hire_date`", "حذف العمود hire_date (إن وجد)");
echo "✓ تمت مزامنة مخطط (teachers)." . PHP_EOL;

$sql_schedules_create = "
CREATE TABLE IF NOT EXISTS schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course VARCHAR(150) NOT NULL,
  classroom VARCHAR(100) NOT NULL,
  teacher VARCHAR(150) NOT NULL,
  section VARCHAR(50) NOT NULL,
  level ENUM('المرحلة الأولى','المرحلة الثانية','المرحلة الثالثة','المرحلة الرابعة') NOT NULL,
  study_type ENUM('صباحي','مسائي') NOT NULL,
  day ENUM('الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس') NOT NULL,
  start_time TIME NOT NULL,
  end_time   TIME NOT NULL,
  date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_schedules_basic (teacher, section, level, study_type, day, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_schedules_create)) {
  echo "تم التأكد من وجود جدول الجداول الدراسية (schedules)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول schedules: {$conn->error}" . PHP_EOL;
}

alter_silent($conn, "ALTER TABLE `schedules` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز schedules إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `schedules` DROP COLUMN IF EXISTS `department`", "حذف عمود department من schedules (إن وجد)");

$sql_classrooms_create = "
CREATE TABLE IF NOT EXISTS classrooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  capacity INT NOT NULL,
  location VARCHAR(100) NOT NULL,
  type VARCHAR(100) NOT NULL,
  stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL,
  equipment VARCHAR(255) NULL,
  status ENUM('متاحة','غير متاحة') NOT NULL DEFAULT 'متاحة',
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_classrooms_basic (name, location, type, stage, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_classrooms_create)) {
  echo "تم التأكد من وجود جدول القاعات (classrooms)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول classrooms: {$conn->error}" . PHP_EOL;
}

alter_silent($conn, "ALTER TABLE `classrooms` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز classrooms إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `classrooms` DROP COLUMN IF EXISTS `department`", "حذف عمود department من classrooms (إن وجد)");
alter_silent(
  $conn,
  "ALTER TABLE `classrooms` ADD COLUMN IF NOT EXISTS `stage` ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL DEFAULT 'الأولى' AFTER `type`",
  "تأكيد عمود stage في classrooms (إضافة إن لم يكن موجودًا)"
);
alter_silent(
  $conn,
  "ALTER TABLE `classrooms` MODIFY `stage` ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL",
  "تعديل نوع stage في classrooms إلى ENUM عربي ثابت"
);

echo "بدء ترقية مخطط students..." . PHP_EOL;
alter_silent($conn, "ALTER TABLE `students` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز students إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL", "تأكيد students.user_id");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(150) NOT NULL", "تأكيد students.full_name");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `age` TINYINT UNSIGNED NOT NULL", "تأكيد students.age");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NOT NULL", "تأكيد students.email");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(30) NOT NULL", "تأكيد students.phone");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `address` TEXT NOT NULL", "تأكيد students.address");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `university_number` VARCHAR(50) NOT NULL", "تأكيد students.university_number");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `birth_date` DATE NULL", "تأكيد students.birth_date");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `study_type` VARCHAR(20) NOT NULL", "تأكيد students.study_type");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `academic_year` VARCHAR(50) NOT NULL", "تأكيد students.academic_year");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `stage` VARCHAR(20) NOT NULL", "تأكيد students.stage");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `gender` VARCHAR(10) NOT NULL", "تأكيد students.gender");
alter_silent($conn, "ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `marital_status` VARCHAR(10) NOT NULL", "تأكيد students.marital_status");

alter_silent($conn, "UPDATE `students` SET `birth_date` = NULL WHERE `birth_date` = '0000-00-00'", "تنظيف تواريخ صفرية سابقة في birth_date");
alter_silent($conn, "ALTER TABLE `students` MODIFY `birth_date` DATE NULL", "تثبيت birth_date مؤقتًا NULL");
alter_silent($conn, "ALTER TABLE `students` DROP CHECK `chk_birthdate_valid`", "إزالة CHECK قديم (إن وجد)");
alter_silent($conn, "ALTER TABLE `students` ADD CONSTRAINT `chk_birthdate_valid` CHECK (`birth_date` IS NULL OR `birth_date` >= '1900-01-01')", "إضافة CHECK صالح على birth_date");
alter_silent($conn, "UPDATE `students` SET `birth_date`='2000-01-01' WHERE `birth_date` IS NULL", "تعيين قيمة افتراضية للسجلات القديمة 2000-01-01");
alter_silent($conn, "ALTER TABLE `students` MODIFY `birth_date` DATE NOT NULL", "تثبيت birth_date كـ NOT NULL");

alter_silent($conn, "UPDATE `students` SET `study_type`='صباحي' WHERE `study_type` IN ('morning','Morning')", "تحويل study_type (students) إلى صباحي");
alter_silent($conn, "UPDATE `students` SET `study_type`='مسائي' WHERE `study_type` IN ('evening','Evening')", "تحويل study_type (students) إلى مسائي");
alter_silent($conn, "ALTER TABLE `students` MODIFY `study_type` ENUM('صباحي','مسائي') NOT NULL", "تثبيت ENUM study_type (students)");

alter_silent($conn, "ALTER TABLE `students` MODIFY `gender` ENUM('ذكر','أنثى') NOT NULL", "تثبيت ENUM gender (students)");
alter_silent($conn, "ALTER TABLE `students` MODIFY `marital_status` ENUM('أعزب','متزوج','مطلق','أرمل') NOT NULL", "تثبيت ENUM marital_status (students)");

alter_silent($conn, "
  UPDATE `students` SET `stage` = CASE TRIM(`stage`)
    WHEN '1' THEN 'الأولى' WHEN '2' THEN 'الثانية'
    WHEN '3' THEN 'الثالثة' WHEN '4' THEN 'الرابعة'
    WHEN  1  THEN 'الأولى' WHEN  2  THEN 'الثانية'
    WHEN  3  THEN 'الثالثة' WHEN  4  THEN 'الرابعة'
    ELSE `stage` END
  WHERE `stage` REGEXP '^[1-4]$' OR `stage` IN (1,2,3,4)
", "ترحيل stage الرقمية (students) إلى عربية");
alter_silent($conn, "ALTER TABLE `students` MODIFY `stage` ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL", "تثبيت ENUM stage (students)");

alter_silent($conn, "ALTER TABLE `students` ADD UNIQUE INDEX IF NOT EXISTS `uq_students_user` (`user_id`)", "UNIQUE على students.user_id");

$need_fk_stu = true;
try {
  $res = $conn->query("
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'students'
      AND REFERENCED_TABLE_NAME = 'users'
      AND REFERENCED_COLUMN_NAME = 'user_id'
  ");
  if ($res && $res->num_rows > 0) { $need_fk_stu = false; }
  if ($res) $res->close();
} catch (mysqli_sql_exception $e) {
  echo "(تنبيه) تعذر فحص FK students→users: {$e->getMessage()}" . PHP_EOL;
}
if ($need_fk_stu) {
  alter_silent($conn, "ALTER TABLE `students` DROP FOREIGN KEY `fk_students_user`", "إزالة FK قديم fk_students_user (إن وجد)");
  alter_silent(
    $conn,
    "ALTER TABLE `students`
     ADD CONSTRAINT `fk_students_user`
     FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
     ON DELETE CASCADE ON UPDATE CASCADE",
    "إضافة المفتاح الأجنبي fk_students_user"
  );
}
echo "✓ تمت مزامنة مخطط (students)." . PHP_EOL;

if (table_exists($conn, 'lists')) {
  drop_all_fks_on_table($conn, 'lists');
  drop_all_unique_indexes_on_table($conn, 'lists');

  if (column_exists($conn, 'lists', 'teacher_name')) {
    alter_silent($conn, "ALTER TABLE `lists` DROP COLUMN `teacher_name`", "حذف العمود teacher_name من lists");
  }

  if (column_exists($conn, 'lists', 'teacher_id') && !column_exists($conn, 'lists', 'user_id')) {
    alter_silent($conn, "ALTER TABLE `lists` CHANGE `teacher_id` `user_id` INT NOT NULL", "تبديل teacher_id إلى user_id في lists");
  }

  if (!column_exists($conn, 'lists', 'user_id') && column_exists($conn, 'lists', 'teacher_id')) {
    alter_silent($conn, "ALTER TABLE `lists` CHANGE `teacher_id` `user_id` INT NOT NULL", "تبديل teacher_id إلى user_id في lists");
  }
}

$sql_lists_create = "
CREATE TABLE IF NOT EXISTS lists (
  list_id INT AUTO_INCREMENT PRIMARY KEY,
  division VARCHAR(50) NOT NULL,
  stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL,
  study ENUM('صباحي','مسائي') NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lists_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uq_lists_unique UNIQUE (division, stage, study, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_lists_create)) {
  echo "تم التأكد من وجود جدول قوائم الطلاب (lists) بصيغة user_id وبدون teacher_name" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول lists: {$conn->error}" . PHP_EOL;
}

alter_silent($conn, "ALTER TABLE `lists` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز lists إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `lists` ADD COLUMN IF NOT EXISTS `user_id` INT NOT NULL", "تأكيد عمود user_id في lists");

$need_fk_lists = true;
try {
  $res = $conn->query("
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'lists'
      AND REFERENCED_TABLE_NAME = 'users'
      AND REFERENCED_COLUMN_NAME = 'user_id'
      AND COLUMN_NAME = 'user_id'
  ");
  if ($res && $res->num_rows > 0) { $need_fk_lists = false; }
  if ($res) $res->close();
} catch (mysqli_sql_exception $e) {
  echo "(تنبيه) تعذر فحص FK lists→users: {$e->getMessage()}" . PHP_EOL;
}
if ($need_fk_lists) {
  drop_all_fks_on_table($conn, 'lists');
  alter_silent(
    $conn,
    "ALTER TABLE `lists`
     ADD CONSTRAINT `fk_lists_user`
     FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`)
     ON DELETE CASCADE ON UPDATE CASCADE",
    "إضافة FK fk_lists_user"
  );
}

drop_all_unique_indexes_on_table($conn, 'lists');
alter_silent($conn, "ALTER TABLE `lists` ADD UNIQUE INDEX `uq_lists_unique` (`division`,`stage`,`study`,`user_id`)", "إضافة UNIQUE uq_lists_unique على (division,stage,study,user_id)");

$sql_list_students_create = "
CREATE TABLE IF NOT EXISTS list_students (
  list_student_id INT AUTO_INCREMENT PRIMARY KEY,
  list_id INT NOT NULL,
  student_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_list_students_list
    FOREIGN KEY (list_id) REFERENCES lists(list_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_list_students_student
    FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uq_list_student UNIQUE (list_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_list_students_create)) {
  echo "تم التأكد من وجود جدول ربط الطلاب بالقوائم (list_students)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول list_students: {$conn->error}" . PHP_EOL;
}

$sql_attendance_create = "
CREATE TABLE IF NOT EXISTS attendance (
  attendance_id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id INT NOT NULL,
  list_id INT NOT NULL,
  student_id INT NOT NULL,
  attendance_date DATE NOT NULL DEFAULT (CURRENT_DATE),
  status TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendance_teacher
    FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_list
    FOREIGN KEY (list_id) REFERENCES lists(list_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attendance_student
    FOREIGN KEY (student_id) REFERENCES students(student_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uq_attendance_unique_day UNIQUE (list_id, student_id, attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_attendance_create)) {
  echo "تم التأكد من وجود جدول حضور الطلاب (attendance)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول attendance: {$conn->error}" . PHP_EOL;
}

alter_silent($conn, "ALTER TABLE `attendance` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", "تحويل ترميز attendance إلى utf8mb4");
alter_silent($conn, "ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `teacher_id` INT NULL AFTER `attendance_id`", "إضافة teacher_id إلى attendance (إن لم يكن موجودًا)");

alter_silent($conn, "
  UPDATE attendance a
  INNER JOIN lists l ON l.list_id = a.list_id
  INNER JOIN teachers t ON t.user_id = l.user_id
  SET a.teacher_id = t.teacher_id
  WHERE a.teacher_id IS NULL OR a.teacher_id = 0
", "ترحيل teacher_id في attendance من خلال lists→teachers");

alter_silent($conn, "ALTER TABLE `attendance` MODIFY `teacher_id` INT NOT NULL", "تثبيت teacher_id كـ NOT NULL في attendance");

$need_fk_att_t = true;
try {
  $res = $conn->query("
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'attendance'
      AND REFERENCED_TABLE_NAME = 'teachers'
      AND REFERENCED_COLUMN_NAME = 'teacher_id'
      AND COLUMN_NAME = 'teacher_id'
  ");
  if ($res && $res->num_rows > 0) { $need_fk_att_t = false; }
  if ($res) $res->close();
} catch (mysqli_sql_exception $e) {
  echo "(تنبيه) تعذر فحص FK attendance→teachers: {$e->getMessage()}" . PHP_EOL;
}
if ($need_fk_att_t) {
  alter_silent($conn, "ALTER TABLE `attendance` DROP FOREIGN KEY `fk_attendance_teacher`", "إزالة FK قديم fk_attendance_teacher (إن وجد)");
  alter_silent(
    $conn,
    "ALTER TABLE `attendance`
     ADD CONSTRAINT `fk_attendance_teacher`
     FOREIGN KEY (`teacher_id`) REFERENCES `teachers`(`teacher_id`)
     ON DELETE CASCADE ON UPDATE CASCADE",
    "إضافة FK fk_attendance_teacher"
  );
}

alter_silent($conn, "ALTER TABLE `attendance` ADD INDEX IF NOT EXISTS `idx_attendance_teacher_date` (`teacher_id`,`attendance_date`)", "إضافة INDEX (teacher_id, attendance_date) في attendance");

$sql_reservations_create = "
CREATE TABLE IF NOT EXISTS reservations (
  reservation_id INT AUTO_INCREMENT PRIMARY KEY,
  classroom_id INT NOT NULL,
  reservation_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  reservation_type VARCHAR(100) NOT NULL,
  reserved_by VARCHAR(150) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservations_classroom
    FOREIGN KEY (classroom_id) REFERENCES classrooms(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_reservations_create)) {
  echo "تم التأكد من وجود جدول حجوزات القاعات (reservations)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول reservations: {$conn->error}" . PHP_EOL;
}

$sql_exam_schedules_create = "
CREATE TABLE IF NOT EXISTS exam_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL,
  course VARCHAR(100) NOT NULL,
  subject VARCHAR(150) NOT NULL,
  exam_date DATE NOT NULL,
  day ENUM('الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس') NOT NULL,
  exam_time_from TIME NOT NULL,
  exam_time_to TIME NOT NULL,
  academic_year VARCHAR(50) NOT NULL,
  study_type ENUM('صباحي','مسائي') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exam_basic (stage, study_type, academic_year, exam_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
if ($conn->query($sql_exam_schedules_create)) {
  echo "تم التأكد من وجود جدول جداول الامتحانات النهائية (exam_schedules)" . PHP_EOL;
} else {
  echo "خطأ في إنشاء جدول exam_schedules: {$conn->error}" . PHP_EOL;
}

echo "انتهى إعداد/ترقية القاعدة بنجاح." . PHP_EOL;

$conn->close();
