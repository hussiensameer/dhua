<?php
session_start();

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS roles (
  role_id INT AUTO_INCREMENT PRIMARY KEY,
  role_name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(200)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS users (
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
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(role_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS academic_year VARCHAR(50) NULL");
$conn->query("CREATE UNIQUE INDEX IF NOT EXISTS ux_users_email ON users(email)");

$conn->query("
  CREATE TABLE IF NOT EXISTS teachers (
    teacher_id INT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    specialization VARCHAR(150) NULL,
    job_grade VARCHAR(100) NULL,
    academic_year VARCHAR(50) NULL,
    phone VARCHAR(30) NULL,
    address TEXT NULL,
    gender ENUM('ذكر','أنثى') NULL,
    marital_status ENUM('أعزب','متزوج','مطلق','أرمل') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_teachers_user
      FOREIGN KEY (teacher_id) REFERENCES users(user_id)
      ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY ux_teachers_email (email)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$conn->query("ALTER TABLE teachers ADD COLUMN IF NOT EXISTS user_id INT NOT NULL AFTER teacher_id");

$conn->query("
  CREATE TABLE IF NOT EXISTS students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    age TINYINT UNSIGNED NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address TEXT NULL,
    university_number VARCHAR(50) NOT NULL,
    birth_date DATE NULL,
    study_type ENUM('صباحي','مسائي') NOT NULL,
    academic_year VARCHAR(50) NOT NULL,
    stage ENUM('الأولى','الثانية','الثالثة','الرابعة') NOT NULL,
    gender ENUM('ذكر','أنثى') NULL,
    marital_status ENUM('أعزب','متزوج','مطلق','أرمل') NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_user (user_id),
    CONSTRAINT fk_students_user
      FOREIGN KEY (user_id) REFERENCES users(user_id)
      ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("ALTER TABLE students MODIFY age TINYINT UNSIGNED NULL");
$conn->query("ALTER TABLE students MODIFY phone VARCHAR(30) NULL");
$conn->query("ALTER TABLE students MODIFY address TEXT NULL");
$conn->query("ALTER TABLE students MODIFY birth_date DATE NULL");
$conn->query("ALTER TABLE students MODIFY gender ENUM('ذكر','أنثى') NULL");
$conn->query("ALTER TABLE students MODIFY marital_status ENUM('أعزب','متزوج','مطلق','أرمل') NULL");

if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$message = "";
$message_type = "";

function get_role_id(mysqli $conn, string $role_name): ?int {
  $sql = "SELECT role_id FROM roles WHERE role_name = ?";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("s", $role_name);
    $st->execute();
    $st->bind_result($rid);
    if ($st->fetch()) { $st->close(); return (int)$rid; }
    $st->close();
  }
  if ($ins = $conn->prepare("INSERT INTO roles (role_name, description) VALUES (?, ?)")) {
    $desc = ($role_name === 'student' ? 'طالب' : ($role_name === 'instructor' ? 'أستاذ' : ($role_name === 'admin' ? 'مدير' : '')));
    $ins->bind_param("ss", $role_name, $desc);
    if ($ins->execute()) {
      $newId = $conn->insert_id;
      $ins->close();
      return (int)$newId;
    }
    $ins->close();
  }
  return null;
}

$form_type = $_POST['form_type'] ?? '';

/* ================= LOGIN ================= */
if ($form_type === 'login') {

    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        $message = "يرجى إدخال البريد وكلمة المرور.";
        $message_type = "error";
    } else {

        $sql = "
            SELECT u.user_id, u.username, u.password_hash, r.role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            WHERE u.email = ?
            LIMIT 1
        ";

        $st = $conn->prepare($sql);
        $st->bind_param("s", $email);
        $st->execute();
        $res = $st->get_result();

        if ($row = $res->fetch_assoc()) {

            if (password_verify($pass, $row['password_hash'])) {

                $_SESSION['user_id']   = $row['user_id'];
                $_SESSION['username']  = $row['username'];
                $_SESSION['role_name'] = $row['role_name'];

                if ($row['role_name'] === 'student') {
                    header("Location: student_dashboard.php");
                    exit;
                } elseif ($row['role_name'] === 'instructor') {
                    header("Location: teacher_dashboard.php");
                    exit;
                } elseif ($row['role_name'] === 'admin') {
                    header("Location: admin_dashboard.php");
                    exit;
                } else {
                    header("Location: index.php");
                    exit;
                }

            } else {
                $message = "كلمة المرور غير صحيحة.";
                $message_type = "error";
            }

        } else {
            $message = "البريد الإلكتروني غير موجود.";
            $message_type = "error";
        }

        $st->close();
    }
}
/* ================= END LOGIN ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = $_POST['csrf'] ?? '';
  if (!hash_equals($_SESSION['csrf'], $csrf)) {
    $message = "طلب غير صالح. أعد المحاولة.";
    $message_type = "error";
  } else {
    $role_name = $_POST['role'] ?? 'student';
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $pass      = $_POST['password'] ?? '';
    $pass2     = $_POST['password_confirm'] ?? '';

    $stage             = trim($_POST['stage'] ?? '');
    $university_number = trim($_POST['university_number'] ?? '');
    $study_type        = trim($_POST['study_type'] ?? '');
    $job_grade         = trim($_POST['job_grade'] ?? '');
    $specialization    = trim($_POST['specialization'] ?? '');
    $academic_year_student    = trim($_POST['academic_year_student'] ?? '');
    $academic_year_instructor = trim($_POST['academic_year_instructor'] ?? '');

    if ($name === '' || $email === '' || $pass === '' || $pass2 === '' || $role_name === '') {
      $message = "يرجى ملء جميع الحقول المطلوبة.";
      $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $message = "صيغة البريد الإلكتروني غير صحيحة.";
      $message_type = "error";
    } elseif ($pass !== $pass2) {
      $message = "كلمتا المرور غير متطابقتين.";
      $message_type = "error";
    } elseif (strlen($pass) < 4) {
      $message = "كلمة المرور يجب ألا تقل عن 4 أحرف/رموز.";
      $message_type = "error";
    } else {
      if ($role_name === 'student') {
        if ($stage === '' || $university_number === '' || $study_type === '' || $academic_year_student === '') {
          $message = "المرحلة والرقم الجامعي ونوع الدراسة والسنة الدراسية مطلوبة للطالب.";
          $message_type = "error";
        }
      } elseif ($role_name === 'instructor') {
        if ($job_grade === '' || $specialization === '' || $academic_year_instructor === '') {
          $message = "الدرجة الوظيفية والتخصص العلمي والسنة الدراسية مطلوبة للأستاذ.";
          $message_type = "error";
        }
      }
    }

    if ($message_type !== "error") {
      $exists_users = 0; $exists_teachers = 0; $exists_students = 0;

      $chkU = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
      $chkU->bind_param("s", $email);
      $chkU->execute();
      $chkU->store_result();
      $exists_users = $chkU->num_rows;
      $chkU->close();

      $chkT = $conn->prepare("SELECT 1 FROM teachers WHERE email = ? LIMIT 1");
      $chkT->bind_param("s", $email);
      $chkT->execute();
      $chkT->store_result();
      $exists_teachers = $chkT->num_rows;
      $chkT->close();

      $chkS = $conn->prepare("SELECT 1 FROM students WHERE email = ? LIMIT 1");
      $chkS->bind_param("s", $email);
      $chkS->execute();
      $chkS->store_result();
      $exists_students = $chkS->num_rows;
      $chkS->close();

      if ($exists_users > 0 || $exists_teachers > 0 || $exists_students > 0) {
        if ($exists_users > 0) {
          $message = "هذا البريد مسجّل مسبقًا في (المستخدمين).";
        } elseif ($exists_teachers > 0) {
          $message = "هذا البريد مسجّل مسبقًا في (الأساتذة).";
        } else {
          $message = "هذا البريد مسجّل مسبقًا في (الطلاب).";
        }
        $message_type = "error";
      }
    }

    if ($message_type !== "error") {
      $role_id = get_role_id($conn, $role_name);
      if ($role_id === null) {
        $message = "تعذر تحديد معرف الدور.";
        $message_type = "error";
      } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stage_val = null; $uni_val = null; $study_val = null;
        $job_val = null; $spec_val = null; $acad_val = null;

        if ($role_name === 'student') {
          $stage_val = $stage;
          $uni_val   = $university_number;
          $study_val = $study_type;
          $acad_val  = $academic_year_student;
        } elseif ($role_name === 'instructor') {
          $job_val   = $job_grade;
          $spec_val  = $specialization;
          $acad_val  = $academic_year_instructor;
        }

        try {
          $conn->begin_transaction();

          $sql_ins_user = "
            INSERT INTO users
              (username, email, password_hash, role_id, stage, university_number, study_type, job_grade, specialization, academic_year)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
          ";
          $ins = $conn->prepare($sql_ins_user);
          $ins->bind_param("sssissssss",
            $name, $email, $hash, $role_id, $stage_val, $uni_val, $study_val, $job_val, $spec_val, $acad_val
          );
          $ins->execute();
          $new_user_id = (int)$conn->insert_id;
          $ins->close();

          if ($role_name === 'instructor') {
            $sql_teacher = "
              INSERT INTO teachers (teacher_id, user_id, full_name, email, specialization, job_grade, academic_year, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            $st = $conn->prepare($sql_teacher);
            $st->bind_param("iisssss", $new_user_id, $new_user_id, $name, $email, $spec_val, $job_val, $acad_val);
            $st->execute();
            $st->close();
          }

          if ($role_name === 'student') {
            $sql_student = "
              INSERT INTO students (user_id, full_name, email, university_number, study_type, academic_year, stage, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ";
            $st2 = $conn->prepare($sql_student);
            $st2->bind_param("issssss", $new_user_id, $name, $email, $uni_val, $study_val, $acad_val, $stage_val);
            $st2->execute();
            $st2->close();
          }

          $conn->commit();

          $message = "تم حفظ بياناتك بنجاح";
          $message_type = "success";
          header("Refresh: 2; URL=login.php");
        } catch (Throwable $e) {
          $conn->rollback();
          $message = "تعذر حفظ البيانات حالياً. أعد المحاولة.";
          $message_type = "error";
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ar">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap');

*{
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Montserrat', sans-serif;
}

body{
    background-color: #c9d6ff;
    background: linear-gradient(to right, #e2e2e2, #c9d6ff);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    height: 100vh;
}

.container{
    background-color: #fff;
    border-radius: 30px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.35);
    position: relative;
    overflow: hidden;
    width: 768px;
    max-width: 100%;
    min-height: 480px;
}

.container p{
    font-size: 14px;
    line-height: 20px;
    letter-spacing: 0.3px;
    margin: 20px 0;
}

.container span{
    font-size: 12px;
}

.container a{
    color: #333;
    font-size: 13px;
    text-decoration: none;
    margin: 15px 0 10px;
}

.container button{
    background-color: #512da8;
    color: #fff;
    font-size: 12px;
    padding: 10px 45px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-top: 10px;
    cursor: pointer;
}

.container button.hidden{
    background-color: transparent;
    border-color: #fff;
}

.container form{
    background-color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 0 40px;
    height: 100%;
    gap:6px;

}
.container input,
.container select{
    background-color: #eee;
    border: none;
    margin: 8px 0;
    padding: 10px 15px;
    font-size: 13px;
    border-radius: 8px;
    width: 100%;
    outline: none;
}


.form-container{
    position: absolute;
    top: 0;
    height: 100%;
    transition: all 0.6s ease-in-out;
}

form{
    direction: rtl;
}

.sign-in{
    left: 0;
    width: 50%;
    z-index: 2;
}

.container.active .sign-in{
    transform: translateX(100%);
}

.sign-up{
    left: 0;
    width: 50%;
    opacity: 0;
    z-index: 1;
}

.container.active .sign-up{
    transform: translateX(100%);
    opacity: 1;
    z-index: 5;
    animation: move 0.6s;
}

@keyframes move{
    0%, 49.99%{
        opacity: 0;
        z-index: 1;
    }
    50%, 100%{
        opacity: 1;
        z-index: 5;
    }
}

.social-icons{
    margin: 20px 0;
}

.social-icons a{
    border: 1px solid #ccc;
    border-radius: 20%;
    display: inline-flex;
    justify-content: center;
    align-items: center;
    margin: 0 3px;
    width: 40px;
    height: 40px;
}

.toggle-container{
    position: absolute;
    top: 0;
    left: 50%;
    width: 50%;
    height: 100%;
    overflow: hidden;
    transition: all 0.6s ease-in-out;
    border-radius: 150px 0 0 100px;
    z-index: 1000;
}

.container.active .toggle-container{
    transform: translateX(-100%);
    border-radius: 0 150px 100px 0;
}

.toggle{
    background-color: #512da8;
    height: 100%;
    background: linear-gradient(to right, #5c6bc0, #512da8);
    color: #fff;
    position: relative;
    left: -100%;
    height: 100%;
    width: 200%;
    transform: translateX(0);
    transition: all 0.6s ease-in-out;
}

.container.active .toggle{
    transform: translateX(50%);
}

.toggle-panel{
    position: absolute;
    width: 50%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 0 30px;
    text-align: center;
    top: 0;
    transform: translateX(0);
    transition: all 0.6s ease-in-out;
}

.toggle-left{
    transform: translateX(-200%);
}

.container.active .toggle-left{
    transform: translateX(0);
}

.toggle-right{
    right: 0;
    transform: translateX(0);
}

.container.active .toggle-right{
    transform: translateX(200%);
}

.row{
    display: flex;
    flex-direction: row-reverse;
    gap: 10px;
    width: 100%;
}


.row > *{
    flex:1;
}

.hidden-section{
    display:none;
    width:100%;
}

.container select{
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    cursor: pointer;
}

    </style>
    <title>صفحة تسجيل الدخول</title>
</head>

<body>

    <div class="container" id="container">
        <div class="form-container sign-up">
            <form method="POST">
               <h2>إنشاء حساب</h2>

<select name="role" id="roleSelect" required>
<option value="">اختر الدور</option>
<option value="student">طالب</option>
<option value="instructor">أستاذ</option>
<option value="admin">مدير</option>
</select>

<div class="row">
<input name="email" type="email" placeholder="البريد الإلكتروني" required>
<input name="name" placeholder="الاسم الكامل" required>

</div>

<div class="row">
  <input type="password" name="password_confirm" placeholder="تأكيد كلمة المرور" required>
<input type="password" name="password" placeholder="كلمة المرور" required>
</div>

<div id="studentFields" class="hidden-section">

<div class="row">
<select name="stage" required>
            <option value="">اختر المرحلة الدراسية</option>
            <option value="الأولى">الأولى</option>
<option value="الثانية">الثانية</option>
<option value="الثالثة">الثالثة</option>
<option value="الرابعة">الرابعة</option>

        </select><input name="university_number" placeholder="الرقم الجامعي">
</div>

<div class="row">
<select name="academic_year_student"></select>

<select name="study_type" required>
            <option value="">اختر نوع الدراسة</option>
            <option value="صباحي">صباحي</option>
            <option value="مسائي">مسائي</option>
        </select></div>

</div>

<div id="teacherFields" class="hidden-section">

<div class="row">
<input name="job_grade" placeholder="الدرجة الوظيفية">
<input name="specialization" placeholder="التخصص العلمي">
</div>

<select name="academic_year_instructor"></select>


</div>

<input type="hidden" name="csrf" value="<?=$_SESSION['csrf']?>">
<input type="hidden" name="form_type" value="register">

<button type="submit">إنشاء حساب</button>

</form>
</div>
        <div class="form-container sign-in">
            <form method="POST">
                <h1>تسجيل الدخول</h1>
                <div class="social-icons">
                    <a href="#" class="icon"><i class="fa-brands fa-google-plus-g"></i></a>
                    <a href="#" class="icon"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" class="icon"><i class="fa-brands fa-github"></i></a>
                    <a href="#" class="icon"><i class="fa-brands fa-linkedin-in"></i></a>
                </div>
                <input type="email" name="email" placeholder="البريد الإلكتروني" required>
                <input type="password" name="password" placeholder="كلمة المرور" required>
                <input type="hidden" name="form_type" value="login">
                <button>تسجيل الدخول</button>
            </form>
        </div>
        <div class="toggle-container">
            <div class="toggle">
                <div class="toggle-panel toggle-left">
                    <h1>مرحبا بعودتك!</h1>
                    <p>لتسجيل الدخول إلى حسابك أدخل بياناتك الشخصية</p>
                    <button class="ghost" id="signIn">تسجيل الدخول</button>
                </div>
                <div class="toggle-panel toggle-right">
                    <h1>مرحباً!</h1>
                    <p>قم بإنشاء حساب جديد وابدأ التجربة معنا</p>
                    <button class="ghost" id="signUp">إنشاء حساب</button>
                </div>
            </div>
        </div>
    </div>

<script>
const container = document.getElementById('container');
document.getElementById('signUp').addEventListener('click', () => container.classList.add("active"));
document.getElementById('signIn').addEventListener('click', () => container.classList.remove("active"));

const roleSelect = document.getElementById("roleSelect");
const studentFields = document.getElementById("studentFields");
const teacherFields = document.getElementById("teacherFields");

roleSelect.addEventListener("change", function () {

    studentFields.style.display = "none";
    teacherFields.style.display = "none";

    studentFields.querySelectorAll("input,select").forEach(el => el.required = false);
    teacherFields.querySelectorAll("input,select").forEach(el => el.required = false);

    if (this.value === "student") {
        studentFields.style.display = "block";
        studentFields.querySelectorAll("input,select").forEach(el => el.required = true);
    }

    if (this.value === "instructor") {
        teacherFields.style.display = "block";
        teacherFields.querySelectorAll("input,select").forEach(el => el.required = true);
    }

});


function populateAcademicYears(datalistId, startYear, endYear) {
    const datalist = document.getElementById(datalistId);
    if (!datalist) return;
    datalist.innerHTML = ''; // تنظيف القائمة

    for (let y = startYear; y <= endYear; y++) {
        const option = document.createElement('option');
        option.value = `${y}-${y+1}`;
        datalist.appendChild(option);
    }
}

// تطبيقها على قائمة السنة الدراسية للطالب
populateAcademicYearsForStudent('academic_year_student', 1990, new Date().getFullYear());

function populateAcademicYearsForStudent(selectName, startYear, endYear) {
    const select = document.getElementsByName(selectName)[0];
    if (!select) return;
    select.innerHTML = '<option value="">اختر السنة الدراسية</option>'; // خيار افتراضي
    for (let y = startYear; y <= endYear; y++) {
        const option = document.createElement('option');
        option.value = `${y}-${y+1}`;
        option.textContent = `${y}-${y+1}`;
        select.appendChild(option);
    }
}

function populateAcademicYearsForInstructor(selectName, startYear, endYear) {
    const select = document.getElementsByName(selectName)[0];
    if (!select) return;
    select.innerHTML = '<option value="">اختر السنة الدراسية</option>'; // خيار افتراضي
    for (let y = startYear; y <= endYear; y++) {
        const option = document.createElement('option');
        option.value = `${y}-${y+1}`;
        option.textContent = `${y}-${y+1}`;
        select.appendChild(option);
    }
}

// تطبيقها على السنة الدراسية للأستاذ
populateAcademicYearsForInstructor('academic_year_instructor', 1990, new Date().getFullYear());

</script>

</body>
</html>
