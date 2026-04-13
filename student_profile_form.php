<?php
session_start();

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
  header("Location: register.php");
  exit;
}

$roleName = $_SESSION['user_role_name'] ?? '';
$role     = $_SESSION['user_role']      ?? '';
$roleKey  = $_SESSION['role']           ?? '';

$allowedStudentValues = ['student', 'Student', 'طالب'];

$isStudent =
  in_array($roleName, $allowedStudentValues, true) ||
  in_array($role,     $allowedStudentValues, true) ||
  in_array($roleKey,  $allowedStudentValues, true);

if (!$isStudent) {
  header("Location: register.php");
  exit;
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli($servername, $username, $password, $dbname);
  $conn->set_charset("utf8mb4");
} catch (Exception $e) {
  http_response_code(500);
  echo "Database connection error.";
  exit;
}

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$conn->query("CREATE TABLE IF NOT EXISTS roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id INT NULL,
  stage VARCHAR(20) NULL,
  university_number VARCHAR(50) NULL UNIQUE,
  study_type ENUM('صباحي','مسائي') NULL,
  job_grade VARCHAR(100) NULL,
  specialization VARCHAR(150) NULL,
  academic_year VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS students (
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
  CONSTRAINT uq_students_user UNIQUE (user_id),
  CONSTRAINT fk_students_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try { $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS university_number VARCHAR(50) NOT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL"); } catch (Throwable $e) {}
try { $conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS birth_date DATE NOT NULL"); } catch (Throwable $e) {}

function flash(string $msg, string $type = 'success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function pick_flash(): ?array { if(!empty($_SESSION['flash'])){ $x=$_SESSION['flash']; unset($_SESSION['flash']); return $x; } return null; }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$user_id = (int)$_SESSION['user_id'];

$st = $conn->prepare("SELECT username, email, COALESCE(university_number,''), stage FROM users WHERE user_id=? LIMIT 1");
$st->bind_param("i", $user_id);
$st->execute();
$st->bind_result($u_name, $u_email, $u_uni, $u_stage);
$st->fetch();
$st->close();

$session_name   = $u_name ?? '';
$session_email  = $u_email ?? '';
$user_uni_num   = $u_uni ?? '';

$st = $conn->prepare("SELECT * FROM students WHERE user_id=? LIMIT 1");
$st->bind_param("i", $user_id);
$st->execute();
$stu_res = $st->get_result();
$student_data = $stu_res && $stu_res->num_rows ? $stu_res->fetch_assoc() : null;
$st->close();

$hasRow = ($student_data !== null);

$completed = false;
if ($hasRow) {
  $need = [
    'full_name','age','email','phone','address','university_number','birth_date',
    'study_type','academic_year','stage','gender','marital_status'
  ];
  $ok = true;
  foreach ($need as $k) {
    $v = $student_data[$k] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) { $ok = false; break; }
  }
  if ($ok) $completed = true;
}

$already = $completed;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already) {
  try {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) throw new Exception("طلب غير صالح. حدّث الصفحة وحاول مجددًا.");

    $st = $conn->prepare("SELECT email, COALESCE(university_number,'') FROM users WHERE user_id=? LIMIT 1");
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->bind_result($db_email, $db_uni);
    $st->fetch();
    $st->close();
    if (!$db_email) throw new Exception("تعذر التحقق من بريد المستخدم.");

    $full_name         = trim($_POST['full_name'] ?? '');
    $age               = trim($_POST['age'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $phone             = trim($_POST['phone'] ?? '');
    $address           = trim($_POST['address'] ?? '');
    $university_number = trim($_POST['university_number'] ?? '');
    $birth_date_raw    = trim($_POST['birth_date'] ?? '');
    $study_type        = trim($_POST['study_type'] ?? '');
    $academic_year     = trim($_POST['academic_year'] ?? '');
    $stage             = trim($_POST['stage'] ?? '');
    $gender            = trim($_POST['gender'] ?? '');
    $marital_status    = trim($_POST['marital_status'] ?? '');

    if ($full_name==='' || $age==='' || $email==='' || $phone==='' || $address==='' ||
        $university_number==='' || $birth_date_raw==='' || $study_type==='' || $academic_year==='' || $stage==='' ||
        $gender==='' || $marital_status==='') {
      throw new Exception("يرجى ملء جميع الحقول المطلوبة.");
    }
    if (!ctype_digit($age) || (int)$age < 15 || (int)$age > 80) throw new Exception("العمر غير صالح.");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("صيغة البريد الإلكتروني غير صحيحة.");
    if (mb_strlen($university_number) < 3 || mb_strlen($university_number) > 50) throw new Exception("الرقم الجامعي غير صالح.");
    if (!in_array($study_type, ['صباحي','مسائي'], true)) throw new Exception("نوع الدراسة غير صحيح.");
    if (!in_array($stage, ['الأولى','الثانية','الثالثة','الرابعة'], true)) throw new Exception("المرحلة الدراسية غير صحيحة.");
    if (!in_array($gender, ['ذكر','أنثى'], true)) throw new Exception("الجنس غير صحيح.");
    if (!in_array($marital_status, ['أعزب','متزوج','مطلق','أرمل'], true)) throw new Exception("الحالة الاجتماعية غير صحيحة.");

    $dt = DateTime::createFromFormat('Y-m-d', $birth_date_raw);
    $errors = DateTime::getLastErrors();
    if (!$dt || $errors['warning_count'] || $errors['error_count']) {
      throw new Exception("تاريخ الميلاد غير صالح. الرجاء اختيار تاريخ صحيح.");
    }
    $birth_date = $dt->format('Y-m-d');
    if ($birth_date === '0000-00-00' || $birth_date < '1900-01-01') {
      throw new Exception("تاريخ الميلاد غير صالح.");
    }

    if (strcasecmp($email, $db_email) !== 0) {
      throw new Exception("يجب أن يطابق البريد الإلكتروني بريد تسجيل الدخول: " . h($db_email));
    }
    if ($db_uni !== '' && strcasecmp($university_number, $db_uni) !== 0) {
      throw new Exception("يجب أن يطابق الرقم الجامعي المسجل في الحساب: " . h($db_uni));
    }

    $a = (int)$age;

    if ($hasRow) {
      $up = $conn->prepare("
        UPDATE students SET
          full_name=?,
          age=?,
          email=?,
          phone=?,
          address=?,
          university_number=?,
          birth_date=?,
          study_type=?,
          academic_year=?,
          stage=?,
          gender=?,
          marital_status=?
        WHERE user_id=?
        LIMIT 1
      ");
      $up->bind_param(
        "sissssssssssi",
        $full_name, $a, $email, $phone, $address, $university_number, $birth_date, $study_type, $academic_year, $stage, $gender, $marital_status, $user_id
      );
      $up->execute();
      $up->close();
    } else {
      $ins = $conn->prepare("
        INSERT INTO students
          (user_id, full_name, age, email, phone, address, university_number, birth_date, study_type, academic_year, stage, gender, marital_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      $ins->bind_param(
        "isissssssssss",
        $user_id, $full_name, $a, $email, $phone, $address, $university_number, $birth_date, $study_type, $academic_year, $stage, $gender, $marital_status
      );
      $ins->execute();
      $ins->close();
    }

    flash("تم إرسال الاستمارة بنجاح. شكرًا لك!", "success");
    header("Location: student_profile_form.php"); exit;

  } catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode() === 1062) {
      flash("يبدو أنك أرسلت الاستمارة مسبقًا.", "error");
    } else {
      flash("خطأ: ".$e->getMessage(), "error");
    }
    header("Location: student_profile_form.php"); exit;
  } catch (Exception $ex) {
    flash($ex->getMessage(), "error");
    header("Location: student_profile_form.php"); exit;
  }
}

$flash = pick_flash();

$val = function(string $k, $fallback='') use ($student_data, $hasRow) {
  if ($hasRow && isset($student_data[$k]) && trim((string)$student_data[$k]) !== '') return $student_data[$k];
  return $fallback;
};
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>استمارة الطلاب</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root{
  --bg:#f6f8fb; --surface:#ffffff; --text:#0f172a; --muted:#6b7280; --line:#e5e7eb;
  --accent:#10b981; --accent-2:#059669;
  --field-vspace: 6px;  --row-gap: 10px;  --section-gap: 10px;
  --input-h:44px; --input-fz:16px; --label-fz:15px; --label-fz-raised:13px;
  --ring: 0 0 0 3px rgba(16,185,129,.14);
  --ring-strong: 0 0 0 4px rgba(16,185,129,.18);
}
*{box-sizing:border-box}
body{ font-family:'Cairo',sans-serif; background:var(--bg); margin:0; direction:rtl; color:var(--text); }
.container{
  max-width:900px; margin:22px auto; background:var(--surface); border-radius:16px;
  box-shadow:0 12px 34px rgba(2,6,23,.08); padding:20px; position:relative; overflow:visible;
}
.container::before{
  content:""; position:absolute; inset:-1px; border-radius:18px; padding:2px;
  background:linear-gradient(90deg, var(--accent), #22c1c3, #7b8ab8, var(--accent));
  background-size:300% 100%; animation:grad 10s linear infinite;
  -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
}
@keyframes grad{0%{background-position:0% 0}100%{background-position:300% 0}}
.header{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px}
h2{ margin:0; font-weight:800; display:flex; align-items:center; gap:8px; font-size:24px; }
h2 i{ color:var(--accent) }
.smallmuted{color:var(--muted); font-size:12px}
.top-actions{ position:absolute; left:12px; top:12px; display:flex; gap:8px; }
.top-actions .btn-secondary{
  background:#fff; color:#0f172a; border:1px solid var(--line);
  padding:8px 12px; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer;
  display:inline-flex; align-items:center; gap:6px; box-shadow:0 6px 14px rgba(15,23,42,.06);
}
.top-actions .btn-secondary:hover{ filter:brightness(1.02) }
.top-back{
  position:absolute; top:-24px; right:-24px; z-index:5;
  width:48px; height:48px; display:flex; align-items:center; justify-content:center;
  border-radius:14px; background:#fff; border:1px solid var(--line);
  box-shadow:0 10px 22px rgba(15,23,42,.12);
  color:#0f172a; text-decoration:none; font-size:20px;
}
.top-back:hover{ transform:translateY(-1px); filter:brightness(1.03) }
.card{ border:1px solid var(--line); border-radius:14px; padding:12px; margin-top:8px; background:#fff; position:relative; }
.card h3{ margin:0 0 6px; font-size:16px; display:flex; align-items:center; gap:8px; }
.toast{
  position:fixed; top:16px; right:16px; z-index:1000; min-width:240px;
  background:#e8f7ee; color:#166534; border:1px solid rgba(22,101,52,.12);
  padding:10px 12px; border-radius:10px; box-shadow:0 8px 24px rgba(2,6,23,.10); display:flex; align-items:center; gap:8px;
}
.toast.error{ background:#fdecec; color:#b42318; border-color:rgba(220,38,38,.12); }
.form-row{
  display:grid; row-gap: var(--row-gap); column-gap: 10px;
  margin-top: var(--field-vspace); margin-bottom: var(--field-vspace);
}
@media (min-width:860px){
  .form-row.two{ grid-template-columns:1fr 1fr; }
  .form-row.three{ grid-template-columns:1fr 1fr 1fr; }
}
.section{ padding-top: var(--section-gap); margin-top: var(--section-gap); border-top:1px dashed var(--line); }
.form-group{ position:relative; margin-top: var(--field-vspace); margin-bottom: var(--field-vspace); }
.field-icon{ position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:18px; color:#94a3b8; pointer-events:none; }
input, select{
  width:100%; border:1px solid var(--line); background:#fafafa; color:#0f172a;
  border-radius:10px; font-size:var(--input-fz); height:var(--input-h);
  padding:0 44px 0 12px; transition: box-shadow .15s ease, border-color .15s ease, transform .06s ease;
  line-height:1.3;
}
input::placeholder{ color:transparent; }
input:focus, select:focus{ outline:none; border-color: var(--accent); box-shadow: var(--ring); }
input:focus-visible, select:focus-visible{ box-shadow: var(--ring-strong); }
input:active, select:active{ transform: translateY(0.5px); }
.floating-label{
  position:absolute; right:44px; top:50%; transform:translateY(-50%);
  padding:0; background:transparent; color:#6b7785; pointer-events:none;
  font-size:var(--label-fz); transition:all .15s ease;
}
.form-group.focused input,
.form-group.filled input,
.form-group.focused select,
.form-group.filled select{ padding-top:16px; }
.form-group.focused .floating-label,
.form-group.filled .floating-label{ top:-10px; transform:none; font-size:var(--label-fz-raised); color:#0b8d6f; }
.actions-center{ display:flex; justify-content:center; gap:8px; margin-top:12px; flex-wrap:wrap; }
.btn{
  background:linear-gradient(90deg,var(--accent),var(--accent-2)); color:#fff; border:none;
  padding:10px 14px; border-radius:12px; font-size:14px; font-weight:900; cursor:pointer; letter-spacing:.2px;
  transition:transform .08s ease, filter .2s ease; display:inline-flex; align-items:center; justify-content:center;
  min-width:220px; box-shadow:0 10px 24px rgba(16,185,129,.22); text-align:center;
}
.btn:hover{ filter:brightness(1.03) } .btn:active{ transform:translateY(1px) }
.btn:disabled{ background:#e5e7eb; color:#6b7280; cursor:not-allowed; box-shadow:none; }
.readonly input, .readonly select{ background:#f5f6f8; color:#334155; pointer-events:none; }
@media print{
  body{ background:#fff; }
  .top-back, .toast, .actions-center, .top-actions{ display:none !important; }
  .container{ box-shadow:none; margin:0; padding:0; }
  .card{ border:none; padding:0; }
  .container::before{ display:none; }
}
</style>
</head>
<body>

<div class="container">
  <a class="top-back no-print" href="student_dashboard.php" title="رجوع للوحة الطالب" aria-label="رجوع">
    <i class="ri-arrow-go-back-line"></i>
  </a>

  <div class="header">
    <h2><i class="ri-profile-line"></i>استمارة الطلاب</h2>
    <div class="smallmuted">مرحبًا، <?= h($session_name ?: 'طالب') ?></div>
  </div>

  <?php if ($flash): ?>
    <div class="toast <?= $flash['type']==='error' ? 'error' : '' ?>" id="toast">
      <i class="<?= $flash['type']==='error' ? 'ri-alert-line' : 'ri-checkbox-circle-line' ?>"></i>
      <span><?= h($flash['msg']) ?></span>
    </div>
  <?php endif; ?>

  <div class="card <?= $already ? 'readonly' : '' ?>" id="formCard">
    <div class="top-actions no-print">
      <button type="button" class="btn-secondary" onclick="window.print()">
        <i class="ri-printer-line"></i> طباعة الاستمارة
      </button>
    </div>

    <?php if ($already): ?>
      <h3><i class="ri-eye-line"></i>الاستمارة (عرض فقط)</h3>
      <div class="info" style="margin-bottom:10px;">
        تم إرسال هذه الاستمارة مسبقًا. لطلب تعديل البيانات راسل شؤون الطلبة.
      </div>
    <?php else: ?>
      <h3><i class="ri-edit-2-line"></i>يرجى ملء جميع الحقول التالية (إجباري)</h3>
      <div class="info" style="margin-bottom:10px;">
        تأكد أن البريد الإلكتروني هو نفسه المستخدم لتسجيل الدخول: <b><?= h($session_email) ?></b>
      </div>
    <?php endif; ?>

    <form method="POST" id="studentForm" novalidate <?= $already ? 'onsubmit="return false;"' : '' ?>>
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

      <div class="form-row two">
        <div class="form-group">
          <span class="field-icon ri-id-card-line"></span>
          <input type="text" name="full_name" id="full_name" placeholder=" " required
                 value="<?= h($val('full_name', $session_name)) ?>"
                 <?= $already ? 'readonly' : '' ?>>
          <label class="floating-label" for="full_name">الاسم الرباعي</label>
        </div>
        <div class="form-group">
          <span class="field-icon ri-hourglass-line"></span>
          <input type="number" name="age" id="age" placeholder=" " min="15" max="80" required
                 value="<?= h($val('age','')) ?>"
                 <?= $already ? 'readonly' : '' ?>>
          <label class="floating-label" for="age">العمر</label>
        </div>
      </div>

      <div class="form-row two">
        <div class="form-group">
          <span class="field-icon ri-at-line"></span>
          <input type="email" name="email" id="email" placeholder=" " required
                 value="<?= h($session_email) ?>" readonly>
          <label class="floating-label" for="email">البريد الإلكتروني (مطابق لحساب الدخول)</label>
        </div>
        <div class="form-group">
          <span class="field-icon ri-hashtag"></span>
          <input type="text" name="university_number" id="university_number" placeholder=" " required
                 value="<?= h($val('university_number', $user_uni_num)) ?>"
                 <?= $already ? 'readonly' : '' ?>>
          <label class="floating-label" for="university_number">الرقم الجامعي</label>
        </div>
      </div>

      <div class="form-row two">
        <div class="form-group">
          <span class="field-icon ri-phone-line"></span>
          <input type="text" name="phone" id="phone" placeholder=" " required
                 value="<?= h($val('phone','')) ?>"
                 <?= $already ? 'readonly' : '' ?>>
          <label class="floating-label" for="phone">رقم الهاتف</label>
        </div>
        <div class="form-group">
          <span class="field-icon ri-map-pin-line"></span>
          <input type="text" name="address" id="address" placeholder=" " required
                 value="<?= h($val('address','')) ?>"
                 <?= $already ? 'readonly' : '' ?>>
          <label class="floating-label" for="address">العنوان الكامل</label>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <span class="field-icon ri-calendar-2-line"></span>
          <input type="date" name="birth_date" id="birth_date" placeholder=" "
                 <?= $already ? 'readonly' : 'required' ?>
                 value="<?= h($val('birth_date','')) ?>">
          <label class="floating-label" for="birth_date">تاريخ الميلاد (يوم/شهر/سنة)</label>
        </div>
      </div>

      <div class="form-row three">
        <div class="form-group">
          <span class="field-icon ri-time-line"></span>
          <select name="study_type" id="study_type" <?= $already ? 'disabled' : 'required' ?>>
            <option value="" <?= !$already ? 'selected disabled hidden' : '' ?>></option>
            <option value="صباحي" <?= $val('study_type','')==='صباحي' ? 'selected' : '' ?>>صباحي</option>
            <option value="مسائي" <?= $val('study_type','')==='مسائي' ? 'selected' : '' ?>>مسائي</option>
          </select>
          <label class="floating-label" for="study_type">نوع الدراسة</label>
        </div>

        <div class="form-group">
          <span class="field-icon ri-calendar-line"></span>
          <input type="text" name="academic_year" id="academic_year" placeholder=" " list="academicYearsList"
                 value="<?= h($val('academic_year','')) ?>"
                 <?= $already ? 'readonly' : 'required' ?>>
          <datalist id="academicYearsList"></datalist>
          <label class="floating-label" for="academic_year">السنة الدراسية (مثال: 2024-2025)</label>
        </div>

        <div class="form-group">
          <span class="field-icon ri-graduation-cap-line"></span>
          <select name="stage" id="stage" <?= $already ? 'disabled' : 'required' ?>>
            <option value="" <?= !$already ? 'selected disabled hidden' : '' ?>></option>
            <?php
              $stages = ['الأولى','الثانية','الثالثة','الرابعة'];
              $cur_stage = $val('stage','');
              foreach ($stages as $s){
                $sel = ($cur_stage === $s) ? 'selected' : '';
                echo "<option value=\"".h($s)."\" $sel>".h($s)."</option>";
              }
            ?>
          </select>
          <label class="floating-label" for="stage">المرحلة الدراسية</label>
        </div>
      </div>

      <div class="form-row two">
        <div class="form-group">
          <span class="field-icon ri-user-line"></span>
          <select name="gender" id="gender" <?= $already ? 'disabled' : 'required' ?>>
            <option value="" <?= !$already ? 'selected disabled hidden' : '' ?>></option>
            <option value="ذكر"  <?= $val('gender','')==='ذكر'  ? 'selected' : '' ?>>ذكر</option>
            <option value="أنثى" <?= $val('gender','')==='أنثى' ? 'selected' : '' ?>>أنثى</option>
          </select>
          <label class="floating-label" for="gender">الجنس</label>
        </div>

        <div class="form-group">
          <span class="field-icon ri-heart-2-line"></span>
          <select name="marital_status" id="marital_status" <?= $already ? 'disabled' : 'required' ?>>
            <option value="" <?= !$already ? 'selected disabled hidden' : '' ?>></option>
            <option value="أعزب"  <?= $val('marital_status','')==='أعزب'  ? 'selected' : '' ?>>أعزب</option>
            <option value="متزوج" <?= $val('marital_status','')==='متزوج' ? 'selected' : '' ?>>متزوج</option>
            <option value="مطلق"  <?= $val('marital_status','')==='مطلق'  ? 'selected' : '' ?>>مطلق</option>
            <option value="أرمل"  <?= $val('marital_status','')==='أرمل'  ? 'selected' : '' ?>>أرمل</option>
          </select>
          <label class="floating-label" for="marital_status">الحالة الاجتماعية</label>
        </div>
      </div>

      <div class="actions-center no-print">
        <?php if ($already): ?>
          <button class="btn" type="button" disabled><i class="ri-check-double-line"></i> تم الإرسال</button>
        <?php else: ?>
          <button class="btn" type="submit"><i class="ri-send-plane-line"></i> إرسال الاستمارة</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
const toast=document.getElementById('toast'); if(toast){ setTimeout(()=>toast.style.display='none',2600); }

document.querySelectorAll('.form-group').forEach(g=>{
  const f=g.querySelector('input, select'); if(!f) return;
  const upd=()=>{ g.classList[f.value && f.value.toString().trim()!=='' ? 'add':'remove']('filled'); };
  f.addEventListener('focus',()=>g.classList.add('focused'));
  f.addEventListener('blur', ()=>g.classList.remove('focused'));
  f.addEventListener('input',upd); f.addEventListener('change',upd); upd();
});

function populateAcademicYears(listId, startYear, endYear) {
  const dl = document.getElementById(listId);
  if (!dl) return;
  dl.innerHTML = '';
  for (let y = startYear; y <= endYear; y++) {
    const opt = document.createElement('option');
    opt.value = `${y}-${y+1}`;
    dl.appendChild(opt);
  }
}
window.addEventListener('DOMContentLoaded', () => {
  const now = new Date().getFullYear();
  populateAcademicYears('academicYearsList', 1990, now + 40);
});

<?php if (!$already): ?>
const form = document.getElementById('studentForm');
if (form) {
  form.addEventListener('submit', (e)=>{
    const emailInput = document.getElementById('email');
    const enforcedEmail = <?= json_encode($session_email, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    if (emailInput && emailInput.value.trim() !== enforcedEmail) {
      e.preventDefault();
      alert('يجب أن يطابق البريد الإلكتروني بريد تسجيل الدخول.');
    }
  });
}
<?php endif; ?>
</script>
</body>
</html>
