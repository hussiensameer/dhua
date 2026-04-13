<?php
session_start();

// السماح فقط للمستخدمين المسجلين (أي دور)
if (
  !isset($_SESSION['user_id']) ||
  !isset($_SESSION['user_role_name']) ||
  !in_array($_SESSION['user_role_name'], ['admin','instructor','student'], true)
) {
  header("Location: register.php");
  exit;
}

// اتصال قاعدة البيانات الخاصة بالمشروع
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab"; // ← إذا اسم قاعدة مشروعك غيره عدّليه هنا

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn = new mysqli($servername, $username, $password, $dbname);
  $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
  die("خطأ في الاتصال بقاعدة البيانات: " . $e->getMessage());
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// CSRF
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// فلاش
function flash(string $msg,string $type='success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function pick_flash(): ?array {
  if(!empty($_SESSION['flash'])){
    $f=$_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
  }
  return null;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
  die("جلسة المستخدم غير صالحة.");
}

// معالجة POST (تحديث بيانات البروفايل)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
      throw new Exception("طلب غير صالح، يرجى تحديث الصفحة والمحاولة مرة أخرى.");
    }

    $name             = trim($_POST['name'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password_raw = $_POST['new_password'] ?? '';

    if ($name === '' || $email === '' || $current_password === '') {
      throw new Exception("يرجى ملء جميع الحقول المطلوبة (الاسم، البريد، كلمة المرور الحالية).");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new Exception("صيغة البريد الإلكتروني غير صحيحة.");
    }

    // جلب كلمة المرور الحالية من قاعدة البيانات
    $st = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $st->bind_param("i", $user_id);
    $st->execute();
    $st->bind_result($stored_hash);
    $ok = $st->fetch();
    $st->close();

    if (!$ok || !$stored_hash) {
      throw new Exception("لم يتم العثور على حساب المستخدم.");
    }

    if (!password_verify($current_password, $stored_hash)) {
      throw new Exception("كلمة المرور الحالية غير صحيحة.");
    }

    // تحديث الاسم + البريد (username / email)
    $st = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE user_id = ?");
    $st->bind_param("ssi", $name, $email, $user_id);
    $st->execute();
    $st->close();

    // تحديث كلمة المرور إذا تم إدخالها
    if (trim($new_password_raw) !== '') {
      $new_hash = password_hash($new_password_raw, PASSWORD_DEFAULT);
      $st = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
      $st->bind_param("si", $new_hash, $user_id);
      $st->execute();
      $st->close();
    }

    // تحديث الجلسة
    $_SESSION['user_name']  = $name;
    $_SESSION['user_email'] = $email;

    flash("تم تحديث معلومات الحساب بنجاح.","success");
    header("Location: profile.php"); // اسم الملف الحالي
    exit;

  } catch (mysqli_sql_exception $e) {
    flash("خطأ في قاعدة البيانات: ".$e->getMessage(),"error");
    header("Location: profile.php");
    exit;
  } catch (Exception $ex) {
    flash($ex->getMessage(),"error");
    header("Location: profile.php");
    exit;
  }
}

// جلب بيانات المستخدم الحالية لعرضها في النموذج
$user = ['username'=>'','email'=>''];
try {
  $st = $conn->prepare("SELECT username, email FROM users WHERE user_id = ?");
  $st->bind_param("i", $user_id);
  $st->execute();
  $res = $st->get_result();
  if ($row = $res->fetch_assoc()) {
    $user['username'] = $row['username'];
    $user['email']    = $row['email'];
  }
  $st->close();
} catch (mysqli_sql_exception $e) {
  // في حال خطأ، نترك القيم فارغة
}

$flash = pick_flash();

// لتحديد صفحة الرجوع حسب الدور (اختياري)
$role      = $_SESSION['user_role_name'] ?? '';
$back_page = 'admin_dashboard.php'; // الافتراضي
if ($role === 'student')   $back_page = 'student_dashboard.php';
if ($role === 'instructor')$back_page = 'teacher_dashboard.php';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إعدادات الحساب</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root{
  --bg:#f5f7fb; --surface:#ffffff; --text:#0f172a; --muted:#6b7280; --line:#e5e7eb;
  --accent:#1abc9c; --accent-2:#16a085;
  --input-h:46px; --input-fz:16px; --label-fz:15px; --label-fz-raised:13px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Cairo',sans-serif;
  background:var(--bg);
  color:var(--text);
  margin:0;
}

/* الحاوية الرئيسية */
.container{
  max-width:600px;
  margin:40px auto;
  background:var(--surface);
  border-radius:12px;
  box-shadow:0 6px 18px rgba(2,6,23,.08);
  padding:18px;
  position:relative;
  overflow:visible;
}
.container::before{
  content:""; position:absolute; inset:-1px; border-radius:14px; padding:2px;
  background:linear-gradient(90deg, var(--accent), #512da8, #7b8ab8, var(--accent));
  background-size:300% 100%; animation:grad 8s linear infinite;
  -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
}
@keyframes grad{0%{background-position:0% 0}100%{background-position:300% 0}}

/* زر رجوع علوي */
.top-back{
  position:absolute;
  top:-24px;
  right:-24px;
  z-index:5;
  width:52px;
  height:52px;
  display:flex;
  align-items:center;
  justify-content:center;
  border-radius:14px;
  background:#fff;
  border:1px solid var(--line);
  box-shadow:0 10px 22px rgba(15,23,42,.12);
  color:#512da8;
  text-decoration:none;
  font-size:22px;
}
.top-back:hover{
  transform:translateY(-1px);
  filter:brightness(1.03);
}

/* الهيدر */
.header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom:8px;
}
h2{
  margin:0;
  font-weight:800;
  display:flex;
  align-items:center;
  gap:8px;
  font-size:22px;
}
h2 i{ color:var(--accent); }
.smallmuted{ color:var(--muted); font-size:12px; }

/* توست */
.toast{
  position:fixed;
  top:14px;
  right:14px;
  z-index:1000;
  min-width:240px;
  background:#e8f7ee;
  color:#166534;
  border:1px solid rgba(22,101,52,.12);
  padding:10px 12px;
  border-radius:10px;
  box-shadow:0 8px 24px rgba(2,6,23,.10);
  display:flex;
  align-items:center;
  gap:8px;
  font-size:13px;
}
.toast.error{
  background:#fdecec;
  color:#b42318;
  border-color:rgba(220,38,38,.12);
}

/* بطاقة */
.card{
  border:1px solid var(--line);
  border-radius:10px;
  padding:14px;
  margin-top:8px;
  background:#fff;
}
.card h3{
  margin:0 0 8px;
  font-size:16px;
  display:flex;
  align-items:center;
  gap:6px;
}

/* نموذج */
.form-group{
  position:relative;
  margin-bottom:14px;
}
.field-icon{
  position:absolute;
  right:10px;
  top:50%;
  transform:translateY(-50%);
  font-size:18px;
  color:#94a3b8;
  pointer-events:none;
}
input{
  width:100%;
  border:1px solid var(--line);
  background:#fafafa;
  color:var(--text);
  border-radius:8px;
  font-size:var(--input-fz);
  height:var(--input-h);
  padding:0 42px 0 12px;
  transition:padding .15s ease, border-color .15s.ease, box-shadow .15s ease;
  outline:none;
}
input:focus{
  border-color:#512da8;
}
.floating-label{
  position:absolute;
  right:42px;
  top:50%;
  transform:translateY(-50%);
  padding:0;
  background:transparent;
  color:#512da8;
  pointer-events:none;
  font-size:var(--label-fz);
  transition:all .15s ease;
}

/* سلوك الليبل العائم */
.form-group.focused input,
.form-group.filled  input{
  padding-top:16px;
}
.form-group.focused .floating-label,
.form-group.filled  .floating-label{
  top:-10px;
  transform:none;
  font-size:var(--label-fz-raised);
  color:#512da8;
}

/* أزرار */
.actions-center{
  display:flex;
  justify-content:center;
  gap:10px;
  margin-top:12px;
}
.btn{
    background: linear-gradient(to right, #5c6bc0, #512da8);
  color:#fff;
  border:none;
  padding:9px 18px;
  border-radius:12px;
  font-size:14px;
  font-weight:800;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  gap:6px;
  letter-spacing:.2px;
  box-shadow:0 8px 20px rgba(26,188,156,.18);
  transition:transform .08s ease, filter .2s ease;
}
.btn:hover{ filter:brightness(1.04); }
.btn:active{ transform:translateY(1px); }

.footer{
  margin-top:10px;
  font-size:11px;
  color:var(--muted);
  text-align:center;
}
</style>
</head>
<body>

<div class="container">
  <!-- زر رجوع -->
  <a class="top-back" href="<?= h($back_page) ?>" title="رجوع للوحة التحكم" aria-label="رجوع">
    <i class="ri-arrow-go-back-line"></i>
  </a>

  <!-- الهيدر -->
  <div class="header">
    <h2><i class="ri-user-settings-line"></i>إعدادات الحساب</h2>
    <div class="smallmuted">
      مرحبًا، <?= h($_SESSION['user_name'] ?? 'مستخدم') ?> 
      <span style="opacity:.7">(
        <?= $role === 'admin' ? 'مدير' : ($role === 'instructor' ? 'أستاذ' : ($role === 'student' ? 'طالب' : 'مستخدم')) ?>
      )</span>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="toast <?= $flash['type']==='error' ? 'error' : '' ?>" id="toast">
      <i class="<?= $flash['type']==='error' ? 'ri-alert-line' : 'ri-checkbox-circle-line' ?>"></i>
      <span><?= h($flash['msg']) ?></span>
    </div>
  <?php endif; ?>

  <div class="card">
    <h3><i class="ri-shield-user-line"></i>تحديث بيانات الحساب</h3>
    <form method="POST" id="profileForm" novalidate>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

      <div class="form-group">
        <span class="field-icon ri-user-line"></span>
        <input type="text" id="name" name="name" placeholder=" " required
               value="<?= h($user['username']) ?>">
        <label class="floating-label" for="name">الاسم (اسم المستخدم)</label>
      </div>

      <div class="form-group">
        <span class="field-icon ri-at-line"></span>
        <input type="email" id="email" name="email" placeholder=" " required
               value="<?= h($user['email']) ?>">
        <label class="floating-label" for="email">البريد الإلكتروني</label>
      </div>

      <div class="form-group">
        <span class="field-icon ri-lock-password-line"></span>
        <input type="password" id="current_password" name="current_password" placeholder=" " required>
        <label class="floating-label" for="current_password">كلمة المرور الحالية</label>
      </div>

      <div class="form-group">
        <span class="field-icon ri-lock-2-line"></span>
        <input type="password" id="new_password" name="new_password" placeholder=" ">
        <label class="floating-label" for="new_password">كلمة المرور الجديدة (اختياري)</label>
      </div>

      <div class="actions-center">
        <button type="submit" class="btn">
          <i class="ri-save-3-line"></i>
          <span>تحديث المعلومات</span>
        </button>
      </div>
    </form>
  </div>

  <div class="footer">
    يمكن لكل مستخدم تعديل اسمه وبريده وكلمة مروره الخاصة به فقط.
  </div>
</div>

<script>
// إخفاء التوست تلقائيًا
const toast=document.getElementById('toast');
if(toast){
  setTimeout(()=>{ toast.style.display='none'; },2600);
}

// سلوك الليبل العائم
document.querySelectorAll('.form-group').forEach(g=>{
  const f = g.querySelector('input');
  if(!f) return;
  const upd = () => {
    g.classList[f.value && f.value.toString().trim()!=='' ? 'add':'remove']('filled');
  };
  f.addEventListener('focus', ()=>g.classList.add('focused'));
  f.addEventListener('blur',  ()=>g.classList.remove('focused'));
  f.addEventListener('input', upd);
  upd();
});
</script>
</body>
</html>

<?php
$conn->close();
?>
