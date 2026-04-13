<?php
session_start();

// السماح فقط للأدمن
if (!isset($_SESSION['user_role_name']) || $_SESSION['user_role_name'] !== 'admin') {
  header("Location: login.php");
  exit;
}

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$conn->query("CREATE TABLE IF NOT EXISTS roles (role_id INT AUTO_INCREMENT PRIMARY KEY, role_name VARCHAR(50) NOT NULL UNIQUE, description VARCHAR(200)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$seed = [["student","طالب"],["instructor","أستاذ"],["admin","مدير"]];
$st = $conn->prepare("INSERT IGNORE INTO roles (role_name, description) VALUES (?, ?)");
foreach ($seed as $r) { $st->bind_param("ss",$r[0],$r[1]); $st->execute(); }
$st->close();

$role_ar_to_en = ['طالب'=>'student','أستاذ'=>'instructor','مدير'=>'admin', 'الكل'=>'' ];
$role_en_to_ar = ['student'=>'طالب','instructor'=>'أستاذ','admin'=>'مدير'];

function role_id_by_name(mysqli $c, string $name): ?int {
  $st=$c->prepare("SELECT role_id FROM roles WHERE role_name=?");
  $st->bind_param("s",$name); $st->execute(); $st->bind_result($rid);
  $ok=$st->fetch(); $st->close(); return $ok? (int)$rid : null;
}
function flash(string $msg,string $type='success'){ $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function pick_flash(): ?array { if(!empty($_SESSION['flash'])){ $f=$_SESSION['flash']; unset($_SESSION['flash']); return $f; } return null; }

// معالجة POST (إنشاء/تعديل/حذف)
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) throw new Exception("طلب غير صالح. حدّث الصفحة وحاول مجددًا.");

    $action = $_POST['action'] ?? '';
    if ($action==='delete') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if ($uid<=0) throw new Exception("معرّف المستخدم غير صالح.");
      if ((int)($_SESSION['user_id'] ?? 0) === $uid) throw new Exception("لا يمكنك حذف حسابك الحالي.");
      $del=$conn->prepare("DELETE FROM users WHERE user_id=?");
      $del->bind_param("i",$uid); $del->execute(); $del->close();
      flash("تم حذف المستخدم.","success");
      header("Location: users.php"); exit;
    }

    // حقول مشتركة
    $name = trim($_POST['username'] ?? '');
    $email= trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $role_ar = $_POST['role_ar'] ?? '';
    $role_en = $role_ar_to_en[$role_ar] ?? '';

    // حقول الطالب/الأستاذ
    $stage = trim($_POST['stage'] ?? '');
    $university_number = trim($_POST['university_number'] ?? '');
    $study_type = trim($_POST['study_type'] ?? ''); // (صباحي/مسائي) — عربي
    $job_grade = trim($_POST['job_grade'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $academic_year_student = trim($_POST['academic_year_student'] ?? '');
    $academic_year_instructor = trim($_POST['academic_year_instructor'] ?? '');

    if ($name==='' || $email==='' || $role_ar==='') throw new Exception("الاسم والبريد والدور مطلوبة.");
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("صيغة البريد الإلكتروني غير صحيحة.");
    if ($role_en==='') { if ($role_ar!=='الكل') throw new Exception("دور غير معروف."); else throw new Exception("يرجى اختيار دور مناسب للمستخدم."); }

    if ($role_en==='student') {
      if ($stage==='' || $university_number==='' || $study_type==='' || $academic_year_student==='') {
        throw new Exception("المرحلة والرقم الجامعي ونوع الدراسة والسنة الدراسية مطلوبة للطالب.");
      }
      if (!in_array($study_type, ['صباحي','مسائي'], true)) throw new Exception("نوع الدراسة غير صحيح (اختر صباحي أو مسائي).");
    } elseif ($role_en==='instructor') {
      if ($job_grade==='' || $specialization==='' || $academic_year_instructor==='') {
        throw new Exception("الدرجة الوظيفية والتخصص والسنة الدراسية مطلوبة للأستاذ.");
      }
    }
    if ($pass!=='' && strlen($pass)<4) throw new Exception("كلمة المرور قصيرة.");

    $role_id = role_id_by_name($conn, $role_en);
    if ($role_id===null) throw new Exception("تعذر تحديد الدور.");

    if ($action==='create') {
      // تفرد
      $st=$conn->prepare("SELECT COUNT(*) FROM users WHERE email=?");
      $st->bind_param("s",$email); $st->execute(); $st->bind_result($c1); $st->fetch(); $st->close();
      if ($c1>0) throw new Exception("البريد الإلكتروني مستخدم بالفعل.");

      if ($role_en==='student' && $university_number!=='') {
        $st=$conn->prepare("SELECT COUNT(*) FROM users WHERE university_number=?");
        $st->bind_param("s",$university_number); $st->execute(); $st->bind_result($c2); $st->fetch(); $st->close();
        if ($c2>0) throw new Exception("الرقم الجامعي مستخدم بالفعل.");
      }

      $hash = $pass!=='' ? password_hash($pass, PASSWORD_DEFAULT) : password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT);

      $stage_val = ($role_en==='student') ? ($stage !== '' ? $stage : null) : null;
      $uni_val   = ($role_en==='student') ? $university_number : null;
      $study_val = ($role_en==='student') ? $study_type : null; // عربي
      $job_val   = ($role_en==='instructor') ? $job_grade : null;
      $spec_val  = ($role_en==='instructor') ? $specialization : null;
      $acad_val  = ($role_en==='student') ? $academic_year_student : (($role_en==='instructor') ? $academic_year_instructor : null);

      $ins=$conn->prepare("
        INSERT INTO users
          (username,email,password_hash,role_id,stage,university_number,study_type,job_grade,specialization,academic_year)
        VALUES (?,?,?,?,?,?,?,?,?,?)
      ");
      $ins->bind_param("sssissssss",$name,$email,$hash,$role_id,$stage_val,$uni_val,$study_val,$job_val,$spec_val,$acad_val);
      $ins->execute(); $ins->close();

      flash("تم إضافة المستخدم بنجاح.","success");
    }
    elseif ($action==='update') {
      $uid = (int)($_POST['user_id'] ?? 0);
      if ($uid<=0) throw new Exception("معرّف المستخدم غير صالح.");

      $st=$conn->prepare("SELECT COUNT(*) FROM users WHERE email=? AND user_id<>?");
      $st->bind_param("si",$email,$uid); $st->execute(); $st->bind_result($c1); $st->fetch(); $st->close();
      if ($c1>0) throw new Exception("البريد الإلكتروني مستخدم بالفعل.");

      if ($role_en==='student' && $university_number!=='') {
        $st=$conn->prepare("SELECT COUNT(*) FROM users WHERE university_number=? AND user_id<>?");
        $st->bind_param("si",$university_number,$uid); $st->execute(); $st->bind_result($c2); $st->fetch(); $st->close();
        if ($c2>0) throw new Exception("الرقم الجامعي مستخدم بالفعل.");
      }

      $stage_val = ($role_en==='student') ? ($stage !== '' ? $stage : null) : null;
      $uni_val   = ($role_en==='student') ? $university_number : null;
      $study_val = ($role_en==='student') ? $study_type : null;
      $job_val   = ($role_en==='instructor') ? $job_grade : null;
      $spec_val  = ($role_en==='instructor') ? $specialization : null;
      $acad_val  = ($role_en==='student') ? $academic_year_student : (($role_en==='instructor') ? $academic_year_instructor : null);

      if ($pass!=='') {
        $hash=password_hash($pass,PASSWORD_DEFAULT);
        $up=$conn->prepare("
          UPDATE users
          SET username=?,email=?,password_hash=?,role_id=?,stage=?,university_number=?,study_type=?,job_grade=?,specialization=?,academic_year=?
          WHERE user_id=?
        ");
        $up->bind_param("sssissssssi",$name,$email,$hash,$role_id,$stage_val,$uni_val,$study_val,$job_val,$spec_val,$acad_val,$uid);
      } else {
        $up=$conn->prepare("
          UPDATE users
          SET username=?,email=?,role_id=?,stage=?,university_number=?,study_type=?,job_grade=?,specialization=?,academic_year=?
          WHERE user_id=?
        ");
        $up->bind_param("ssissssssi",$name,$email,$role_id,$stage_val,$uni_val,$study_val,$job_val,$spec_val,$acad_val,$uid);
      }
      $up->execute(); $up->close();

      flash("تم تحديث بيانات المستخدم.","success");
    } else {
      throw new Exception("إجراء غير معروف.");
    }

    header("Location: users.php"); exit;

  } catch (mysqli_sql_exception $e) {
    if ((int)$e->getCode()===1062) {
      $m=$e->getMessage();
      if (stripos($m,"for key 'email'")!==false) flash("البريد الإلكتروني مستخدم بالفعل.","error");
      elseif (stripos($m,"for key 'university_number'")!==false) flash("الرقم الجامعي مستخدم بالفعل.","error");
      else flash("بيانات مكررة. تحقق من البريد/الرقم الجامعي.","error");
    } else {
      flash("خطأ: ".$e->getMessage(),"error");
    }
    header("Location: users.php"); exit;
  } catch (Exception $ex) {
    flash($ex->getMessage(),"error");
    header("Location: users.php"); exit;
  }
}

$per_page = 12;
$page     = max(1, (int)($_GET['page'] ?? 1));
$search   = trim($_GET['q'] ?? '');
$role_ar_f= trim($_GET['role_ar'] ?? '');
$role_en_f= $role_ar_to_en[$role_ar_f] ?? (($role_ar_f==='الكل') ? '' : '');

$where = " WHERE 1=1 ";
$params=[]; $types="";
if ($search!=='') {
  $where.=" AND (u.username LIKE CONCAT('%',?,'%') OR u.email LIKE CONCAT('%',?,'%') OR u.university_number LIKE CONCAT('%',?,'%'))";
  $params[]=$search; $params[]=$search; $params[]=$search; $types.="sss";
}
if ($role_en_f!=='') { $where.=" AND r.role_name=?"; $params[]=$role_en_f; $types.="s"; }

$sql_count = "SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.role_id=u.role_id $where";
$stc=$conn->prepare($sql_count); if ($types!=="") $stc->bind_param($types, ...$params); $stc->execute(); $stc->bind_result($total_rows); $stc->fetch(); $stc->close();

$pages=max(1,(int)ceil($total_rows/$per_page)); $page=min($page,$pages); $offset=($page-1)*$per_page;

$sql_list = "
  SELECT u.user_id,u.username,u.email,COALESCE(r.role_name,'') role_name,
         u.stage,u.university_number,u.study_type,u.job_grade,u.specialization,u.academic_year,
         DATE_FORMAT(u.created_at,'%Y-%m-%d %H:%i') created_at
  FROM users u LEFT JOIN roles r ON r.role_id=u.role_id
  $where
  ORDER BY u.user_id DESC
  LIMIT ? OFFSET ?
";
$stl=$conn->prepare($sql_list);
if ($types!=="") { $types_l=$types."ii"; $params_l=array_merge($params,[$per_page,$offset]); $stl->bind_param($types_l, ...$params_l); }
else { $stl->bind_param("ii",$per_page,$offset); }
$stl->execute(); $res=$stl->get_result(); $users=$res->fetch_all(MYSQLI_ASSOC); $stl->close();

$flash = pick_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إدارة المستخدمين</title>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@4.3.0/fonts/remixicon.css" rel="stylesheet">
<style>
:root{
  /* تقليل المسافات والارتفاعات */
  --bg:#f5f7fb; --surface:#ffffff; --text:#0f172a; --muted:#6b7280; --line:#e5e7eb;
  --accent:#1abc9c; --accent-2:#16a085;
  --gap:10px; --input-h:44px; --input-fz:16px; --label-fz:15px; --label-fz-raised:13px;
}
*{box-sizing:border-box}
body{ font-family:'Cairo',sans-serif; background:var(--bg); margin:0; direction:rtl; color:var(--text); }

/* الحاوية */
.container{
  max-width:1250px; margin:20px auto; background:var(--surface); border-radius:12px;
  box-shadow:0 6px 18px rgba(2,6,23,.08); padding:18px; position:relative; overflow:visible;
}
.container::before{
  content:""; position:absolute; inset:-1px; border-radius:14px; padding:2px;
  background:linear-gradient(90deg, var(--accent), #512da8, #7b8ab8, var(--accent));
  background-size:300% 100%; animation:grad 8s linear infinite;
  -webkit-mask:linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite:xor; mask-composite:exclude; pointer-events:none;
}
@keyframes grad{0%{background-position:0% 0}100%{background-position:300% 0}}
.header{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px}
h2{ margin:0; font-weight:800; letter-spacing:.2px; display:flex; align-items:center; gap:8px; font-size:24px; }
h2 i{ color:var(--accent) ; color: #512da8;
}
.smallmuted{color:var(--muted); font-size:12px}

/* زر رجوع  */
.top-back{
  position:absolute; top:-24px; right:-24px; z-index:5;
  width:52px; height:52px; display:flex; align-items:center; justify-content:center;
  border-radius:14px; background:#fff; border:1px solid var(--line);
  box-shadow:0 10px 22px rgba(15,23,42,.12);
  color:#0f172a; text-decoration:none; font-size:22px;
}
.top-back:hover{ transform:translateY(-1px); filter:brightness(1.03) }

.toast{
  position:fixed; top:14px; right:14px; z-index:1000; min-width:240px;
  background:#e8f7ee; color:#166534; border:1px solid rgba(22,101,52,.12);
  padding:10px 12px; border-radius:10px; box-shadow:0 8px 24px rgba(2,6,23,.10); display:flex; align-items:center; gap:8px;
}
.toast.error{ background:#fdecec; color:#b42318; border-color:rgba(220,38,38,.12); }

/* أزرار عامة */
.btn{
      background: linear-gradient(to right, #5c6bc0, #512da8);
 color:#fff; border:none;
  padding:8px 10px; border-radius:10px; font-size:13px; font-weight:800; cursor:pointer; letter-spacing:.2px;
  transition:transform .08s ease, filter .2s ease; display:inline-flex; align-items:center; gap:6px;
}
.btn:hover{ filter:brightness(1.03) } .btn:active{ transform:translateY(1px) }
.btn.secondary{ background:#f3f4f6; color:#111827; }
.btn.ghost{ background:transparent; color:#0f172a; border:1px solid var(--line); }
.btn.danger{ background:linear-gradient(90deg,#ef4444,#dc2626); color:#fff; }

/* البطاقة */
.card{ border:1px solid var(--line); border-radius:10px; padding:12px; margin-top:8px; background:#fff; position:relative; }
.card h3{ margin:0 0 6px; font-size:16px; display:flex; align-items:center; gap:8px; }

/* نموذج الإضافة/التعديل */
.form-row{ display:grid; gap:var(--gap); }       /* تقليل المسافة بين الحقول */
.form-row.spaced{ margin-top:10px; }
.form-row.spaced2{ margin-top:10px; }
@media (min-width:860px){ .form-row.two{ grid-template-columns:1fr 1fr; } }
.section{ padding-top:10px; margin-top:6px; border-top:1px dashed var(--line); }

/* الحقول */
.form-group{ position:relative; }
.field-icon{
  position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:18px; color:#94a3b8; pointer-events:none;
}
input, select{
  width:100%; border:1px solid var(--line); background:#fafafa; color:#0f172a;
  border-radius:8px; font-size:var(--input-fz); height:var(--input-h);
  padding:0 42px 0 12px; transition:padding .15s ease; line-height:1.2;
}
input:focus, select:focus{ outline:none; }
.floating-label{
  position:absolute; right:42px; top:50%; transform:translateY(-50%);
  padding:0; background:transparent; color:#6b7785; pointer-events:none;
  font-size:var(--label-fz); transition:all .15s ease;
}
.form-group.focused input,.form-group.filled input,.form-group.focused select,.form-group.filled select{ padding-top:16px; }
.form-group.focused .floating-label,.form-group.filled .floating-label{ top:-10px; transform:none; font-size:var(--label-fz-raised); color:#16a085; }

/* أزرار حفظ/تحديث */
.actions-center{
  display:flex; justify-content:center; gap:8px; margin-top:10px; flex-wrap:wrap;
}
.btn-large{
  min-width:180px; padding:12px 16px; border-radius:12px; font-size:15px; font-weight:900;
  box-shadow:0 8px 20px rgba(26,188,156,.18);
}
.btn-large.secondary{
  box-shadow:none; border:1px solid var(--line);
}

/* شريط البحث */
.searchbar{ display:grid; gap:10px; margin:12px 0 4px; }
@media (min-width:860px){ .searchbar{ grid-template-columns:1fr 1fr; } }

.print-btn{
  display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border:none; border-radius:999px;
    background: linear-gradient(to right, #5c6bc0, #512da8);
          color: white;
  box-shadow:0 8px 20px rgba(26,188,156,.24); cursor:pointer; transition:transform .08s ease, filter .2s ease;
}
.print-btn i{ font-size:18px; }
.print-btn:hover{ filter:brightness(1.04); }
.print-btn:active{ transform:translateY(1px); }

.table-wrap{ overflow:auto; position:relative; }

/* زر الطباعة  */
.table-actions{
  display:flex; justify-content:flex-end; gap:8px; margin-bottom:8px; direction: rtl;
}

.table{
  width:100%; border-collapse:separate; border-spacing:0; margin-top:10px; overflow:hidden; border-radius:10px; border:1px solid var(--line);
}
.table thead th{
  background:#f8fafc; font-weight:800; padding:10px; border-bottom:1px solid var(--line);
  text-align:right; font-size:13px; white-space:nowrap;
}
.table thead th .th-icon{ margin-left:6px; color:#7c8aa5; vertical-align:middle; }
.table tbody td{
  padding:8px 10px; border-bottom:1px solid var(--line); font-size:13px; word-break:break-word;
}

.actions-cell{
  white-space:nowrap;
  display:flex;
  align-items:center;
  gap:6px;
  flex-wrap:nowrap;     /* منع النزول لسطر جديد */
}
.actions-cell form{ display:inline; margin:0; }

.role-text{ font-weight:700; color:#0f172a; }

.pagination{ display:flex; gap:6px; align-items:center; justify-content:center; margin-top:8px; flex-wrap:wrap; }
.pagebtn{ padding:6px 8px; border:1px solid var(--line); background:#fff; border-radius:8px; cursor:pointer; text-decoration:none; color:#0f172a; }
.pagebtn.active{ background:linear-gradient(90deg,var(--accent),var(--accent-2)); color:#fff; border:none; }

@media print{
  *{ box-shadow:none !important; text-shadow:none !important; }
  body{ background:#fff !important; direction:rtl !important; }
  .top-back, .header, .searchbar, .card:not(#tableCard), .pagination, .footer, .toast, .print-btn { display:none !important; }
  #tableCard{ border:none !important; box-shadow:none !important; }
  .container::before{ display:none !important; }

  .table-wrap{ overflow:visible !important; }

  .table{
    width:100% !important;
    border:1px solid #000 !important;
    border-collapse:collapse !important;
    table-layout:auto !important;
    border-radius:0 !important;
  }
  .table thead{ display:table-header-group !important; }  
  .table tfoot{ display:table-footer-group !important; }

  .table thead th,
  .table tbody td{
    border:1px solid #000 !important;
    padding:6px 8px !important;
    white-space:normal !important;          /* لا تستخدم nowrap */
    word-break:break-word !important;       
    vertical-align:top !important;
    background:#fff !important;             
    color:#000 !important;
  }

  .table tr{
    page-break-inside:avoid !important;
    break-inside:avoid !important;
  }

  /* إخفاء عمود الإجراءات أثناء الطباعة لتجنب بعثرة التخطيط */
  .table thead th:last-child,
  .table tbody td:last-child{
    display:none !important;
  }

  /* إخفاء الأيقونات داخل الرؤوس في الطباعة */
  .table thead th .th-icon,
  .table thead th i{ display:none !important; }
}

/* مسافة بسيطة قبل نوع الدراسة */
.spacer { height: 8px; }
</style>
</head>
<body>

<div class="container">
  <!-- أيقونة الرجوع  -->
  <a class="top-back" href="admin_dashboard.php" title="رجوع للوحة التحكم" aria-label="رجوع">
    <i class="ri-arrow-go-back-line"></i>
  </a>

  <div class="header">
    <h2><i class="ri-team-line"></i>إدارة المستخدمين</h2>
    <div class="smallmuted">مرحبًا، <?= htmlspecialchars($_SESSION['user_name'] ?? 'مشرف', ENT_QUOTES, 'UTF-8') ?></div>
  </div>

  <?php if ($flash): ?>
    <div class="toast <?= $flash['type']==='error' ? 'error' : '' ?>" id="toast">
      <i class="<?= $flash['type']==='error' ? 'ri-alert-line' : 'ri-checkbox-circle-line' ?>"></i>
      <span><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
  <?php endif; ?>

  <!-- بطاقة: إضافة/تعديل -->
  <div class="card" id="formCard">
    <h3><i class="ri-user-add-line" id="formIcon"></i><span id="formTitle">إضافة مستخدم</span></h3>
    <form method="POST" id="userForm" novalidate>
      <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
      <input type="hidden" name="action" id="formAction" value="create">
      <input type="hidden" name="user_id" id="user_id" value="">

      <!-- الاسم الكامل + البريد -->
      <div class="form-row two">
        <div class="form-group">
          <span class="field-icon ri-id-card-line"></span>
          <input type="text" name="username" id="username" placeholder=" " required>
          <label class="floating-label" for="username">الاسم الكامل</label>
        </div>
        <div class="form-group">
          <span class="field-icon ri-at-line"></span>
          <input type="email" name="email" id="email" placeholder=" " required>
          <label class="floating-label" for="email">البريد الإلكتروني</label>
        </div>
      </div>

      <!-- كلمة المرور + الدور -->
      <div class="form-row two spaced">
        <div class="form-group">
          <span class="field-icon ri-key-2-line"></span>
          <input type="password" name="password" id="password" placeholder=" ">
          <label class="floating-label" for="password">كلمة المرور (اتركها فارغة لعدم التغيير)</label>
        </div>
        <div class="form-group">
          <span class="field-icon ri-user-settings-line"></span>
          <select name="role_ar" id="role_ar" required>
            <option value="" selected disabled hidden></option>
            <option value="طالب">طالب</option>
            <option value="أستاذ">أستاذ</option>
            <option value="مدير">مدير</option>
          </select>
          <label class="floating-label" for="role_ar">الدور</label>
        </div>
      </div>

      <!-- بيانات الطالب -->
      <div class="section" id="studentFields" style="display:none;">
        <div class="form-row two spaced2">
          <div class="form-group">
            <span class="field-icon ri-graduation-cap-line"></span>
            <select name="stage" id="stage">
              <option value="" selected disabled hidden></option>
              <option value="الأولى">الأولى</option>
              <option value="الثانية">الثانية</option>
              <option value="الثالثة">الثالثة</option>
              <option value="الرابعة">الرابعة</option>
            </select>
            <label class="floating-label" for="stage">المرحلة الدراسية</label>
          </div>
          <div class="form-group">
            <span class="field-icon ri-id-card-line"></span>
            <input type="text" name="university_number" id="university_number" placeholder=" ">
            <label class="floating-label" for="university_number">الرقم الجامعي</label>
          </div>
        </div>

        <!-- مسافة قبل نوع الدراسة -->
        <div class="spacer"></div>

        <div class="form-row two spaced2">
          <div class="form-group">
            <span class="field-icon ri-time-line"></span>
            <select name="study_type" id="study_type">
              <option value="" selected disabled hidden></option>
              <option value="صباحي">صباحي</option>
              <option value="مسائي">مسائي</option>
            </select>
            <label class="floating-label" for="study_type">نوع الدراسة</label>
          </div>

          <!-- السنة الدراسية (طالب) -->
          <div class="form-group">
            <span class="field-icon ri-calendar-line"></span>
            <input type="text" name="academic_year_student" id="academic_year_student" placeholder=" " list="academicYearsStudentList">
            <datalist id="academicYearsStudentList"></datalist>
            <label class="floating-label" for="academic_year_student">السنة الدراسية (مثال: 2024-2025)</label>
          </div>
        </div>
      </div>

      <!-- بيانات الأستاذ -->
      <div class="section" id="instructorFields" style="display:none;">
        <div class="form-row two spaced2">
          <div class="form-group">
            <span class="field-icon ri-medal-2-line"></span>
            <input type="text" name="job_grade" id="job_grade" placeholder=" ">
            <label class="floating-label" for="job_grade">الدرجة الوظيفية</label>
          </div>
          <div class="form-group">
            <span class="field-icon ri-book-2-line"></span>
            <input type="text" name="specialization" id="specialization" placeholder=" ">
            <label class="floating-label" for="specialization">التخصص العلمي</label>
          </div>
        </div>

        <!-- مسافة قبل السنة الدراسية للأستاذ -->
        <div class="spacer"></div>

        <div class="form-row">
          <div class="form-group">
            <span class="field-icon ri-calendar-line"></span>
            <input type="text" name="academic_year_instructor" id="academic_year_instructor" placeholder=" " list="academicYearsInstructorList">
            <datalist id="academicYearsInstructorList"></datalist>
            <label class="floating-label" for="academic_year_instructor">السنة الدراسية (مثال: 2024-2025)</label>
          </div>
        </div>
      </div>

      <!-- أزرار حفظ + تحديث   -->
      <div class="actions-center">
        <button class="btn btn-large" type="submit" id="saveBtn">
          <i class="ri-save-3-line"></i> حفظ
        </button>
        <button class="btn btn-large secondary" type="button" id="refreshBtn">
          <i class="ri-refresh-line"></i> تحديث
        </button>
      </div>
    </form>
  </div>

  <!-- شريط البحث  -->
  <form class="searchbar" onsubmit="return false;">
    <div class="form-group">
      <span class="field-icon ri-search-line"></span>
      <input type="text" id="liveQuery" placeholder=" ">
      <label class="floating-label" for="liveQuery">ابحث بالاسم / المرحلة / الرقم الجامعي / نوع الدراسة / السنة الدراسية</label>
    </div>
    <div class="form-group">
      <span class="field-icon ri-user-settings-line"></span>
      <select id="liveRole">
        <option value="" selected disabled hidden></option>
        <option value="الكل">كل الأدوار</option>
        <option value="طالب">طالب</option>
        <option value="أستاذ">أستاذ</option>
        <option value="مدير">مدير</option>
      </select>
      <label class="floating-label" for="liveRole">الدور</label>
    </div>
  </form>

  <!-- بطاقة الجدول -->
  <div class="card" id="tableCard">
    <div class="table-wrap">
      <div class="table-actions">
        <button class="print-btn" type="button" onclick="window.print()" title="طباعة الجدول" aria-label="طباعة">
          <i class="ri-printer-line"></i><span>طباعة</span>
        </button>
      </div>

      <table class="table" id="usersTable">
        <thead>
          <tr>
            <th><i class="ri-id-card-line th-icon"></i> الاسم</th>
            <th><i class="ri-at-line th-icon"></i> البريد</th>
            <th><i class="ri-user-settings-line th-icon"></i> الدور</th>
            <th><i class="ri-graduation-cap-line th-icon"></i> مرحلة</th>
            <th><i class="ri-id-card-2-line th-icon"></i> رقم جامعي</th>
            <th><i class="ri-time-line th-icon"></i> نوع الدراسة</th>
            <th><i class="ri-calendar-line th-icon"></i> السنة الدراسية</th>
            <th><i class="ri-medal-2-line th-icon"></i> درجة وظيفية</th>
            <th><i class="ri-book-2-line th-icon"></i> تخصص</th>
            <th><i class="ri-time-line th-icon"></i> تاريخ الإنشاء</th>
            <th><i class="ri-settings-4-line th-icon"></i> إجراءات</th>
          </tr>
        </thead>
        <tbody id="usersTbody">
          <?php if (!$users): ?>
            <tr><td colspan="11" style="text-align:center;color:#6b7280">لا توجد نتائج.</td></tr>
          <?php else: foreach ($users as $u): $role_ar_cell = $role_en_to_ar[$u['role_name']] ?? $u['role_name']; ?>
            <tr
              data-name="<?= htmlspecialchars($u['username'],ENT_QUOTES,'UTF-8') ?>"
              data-stage="<?= htmlspecialchars((string)$u['stage'],ENT_QUOTES,'UTF-8') ?>"
              data-uni="<?= htmlspecialchars((string)$u['university_number'],ENT_QUOTES,'UTF-8') ?>"
              data-study="<?= htmlspecialchars((string)$u['study_type'],ENT_QUOTES,'UTF-8') ?>"
              data-year="<?= htmlspecialchars((string)$u['academic_year'],ENT_QUOTES,'UTF-8') ?>"
              data-role="<?= htmlspecialchars($role_ar_cell,ENT_QUOTES,'UTF-8') ?>">
              <td><?= htmlspecialchars($u['username'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['email'],ENT_QUOTES,'UTF-8') ?></td>
              <td><span class="role-text"><?= htmlspecialchars($role_ar_cell,ENT_QUOTES,'UTF-8') ?></span></td>
              <td><?= htmlspecialchars((string)$u['stage'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$u['university_number'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$u['study_type'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$u['academic_year'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$u['job_grade'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars((string)$u['specialization'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($u['created_at'],ENT_QUOTES,'UTF-8') ?></td>
              <td class="actions-cell">
                <button class="btn secondary" type="button"
                  onclick='fillForEdit(<?= json_encode($u, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>)'>
                  <i class="ri-edit-2-line"></i> تعديل
                </button>
                <form method="POST" onsubmit="return confirm('تأكيد حذف المستخدم؟');">
                  <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <button class="btn danger" type="submit"><i class="ri-delete-bin-6-line"></i> حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages>1): ?>
      <div class="pagination">
        <?php
          $qs=$_GET;
          for($p=1;$p<=$pages;$p++):
            $qs['page']=$p; $url='?'.htmlspecialchars(http_build_query($qs),ENT_QUOTES,'UTF-8');
        ?>
          <a class="pagebtn <?= ($p===$page?'active':'') ?>" href="<?= $url ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
// إخفاء التوست تلقائيًا
const toast=document.getElementById('toast'); if(toast){ setTimeout(()=>toast.style.display='none',2600); }

//  الليبل العائم
document.querySelectorAll('.form-group').forEach(g=>{
  const f=g.querySelector('input, select'); if(!f) return;
  const upd=()=>{ g.classList[f.value && f.value.toString().trim()!=='' ? 'add':'remove']('filled'); };
  f.addEventListener('focus',()=>g.classList.add('focused'));
  f.addEventListener('blur', ()=>g.classList.remove('focused'));
  f.addEventListener('input',upd); f.addEventListener('change',upd); upd();
});

const roleSel=document.getElementById('role_ar');
const studentBox=document.getElementById('studentFields');
const instructorBox=document.getElementById('instructorFields');
function toggleBoxes(){
  const v=roleSel.value;
  studentBox.style.display = (v==='طالب') ? 'block':'none';
  instructorBox.style.display = (v==='أستاذ') ? 'block':'none';
}
if(roleSel){ roleSel.addEventListener('change',toggleBoxes); }

// زر التحديث بجانب زر الحفظ
document.getElementById('refreshBtn').addEventListener('click', ()=>window.location.reload());

// تعبئة للتعديل
function fillForEdit(u){
  document.getElementById('formAction').value='update';
  document.getElementById('formTitle').innerText='تعديل مستخدم';
  document.getElementById('formIcon').className='ri-edit-2-line';
  document.getElementById('user_id').value = u.user_id || '';
  document.getElementById('username').value = u.username || '';
  document.getElementById('email').value = u.email || '';
  document.getElementById('password').value = '';
  const map={'student':'طالب','instructor':'أستاذ','admin':'مدير'};
  roleSel.value = map[u.role_name] || '';
  toggleBoxes();
  document.getElementById('stage').value = u.stage || '';
  document.getElementById('university_number').value = u.university_number || '';
  document.getElementById('study_type').value = u.study_type || '';
  document.getElementById('job_grade').value = u.job_grade || '';
  document.getElementById('specialization').value = u.specialization || '';
  document.getElementById('academic_year_student').value = (map[u.role_name]==='طالب') ? (u.academic_year||'') : '';
  document.getElementById('academic_year_instructor').value = (map[u.role_name]==='أستاذ') ? (u.academic_year||'') : '';

  // تحديث حالة الليبل
  document.querySelectorAll('#userForm .form-group').forEach(g=>{
    const f=g.querySelector('input,select'); if(!f) return;
    g.classList[f.value && f.value.toString().trim()!=='' ? 'add':'remove']('filled');
  });
  document.getElementById('formCard').scrollIntoView({behavior:'smooth',block:'start'});
}

// ====== البحث الحي ======
const liveQuery = document.getElementById('liveQuery');
const liveRole  = document.getElementById('liveRole');
const tbody     = document.getElementById('usersTbody');

function normalize(s){ return (s||'').toString().toLowerCase().trim(); }
function applyLiveFilter(){
  const q = normalize(liveQuery.value);
  const r = liveRole.value || '';
  const rows = tbody.querySelectorAll('tr');
  rows.forEach(row=>{
    const name  = normalize(row.dataset.name);
    const stage = normalize(row.dataset.stage);
    const uni   = normalize(row.dataset.uni);
    const study = normalize(row.dataset.study);
    const year  = normalize(row.dataset.year);
    const role  = row.dataset.role || '';

    // المطابقة المطلوبة: الاسم + المرحلة + الرقم الجامعي + نوع الدراسة + السنة الدراسية
    const matchText = (!q) ||
      name.includes(q)  ||
      stage.includes(q) ||
      uni.includes(q)   ||
      study.includes(q) ||
      year.includes(q);

    const matchRole = (!r || r==='الكل') || (role===r);
    row.style.display = (matchText && matchRole) ? '' : 'none';
  });
}
if (liveQuery){ liveQuery.addEventListener('input', applyLiveFilter); }
if (liveRole){ liveRole.addEventListener('change', applyLiveFilter); }
applyLiveFilter();

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
  populateAcademicYears('academicYearsStudentList', 1990, now + 40);
  populateAcademicYears('academicYearsInstructorList', 1990, now + 40);
});
</script>
</body>
</html>
