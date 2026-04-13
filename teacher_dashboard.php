<?php
session_start();

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$conn->query("
  CREATE TABLE IF NOT EXISTS teachers (
    teacher_id INT PRIMARY KEY,
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

try {
    $conn->query("CREATE UNIQUE INDEX ux_teachers_email ON teachers(email)");
} catch (mysqli_sql_exception $e) {
}

/* ===================== جلب أو إنشاء سجل الأستاذ ===================== */

$stmt = $conn->prepare("
    SELECT full_name, email, specialization, job_grade, academic_year,
           phone, address, gender, marital_status
    FROM teachers
    WHERE teacher_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res     = $stmt->get_result();
$teacher = $res->fetch_assoc();
$stmt->close();

if (!$teacher) {
    $getU = $conn->prepare("
        SELECT username AS full_name, email, specialization, job_grade, academic_year
        FROM users
        WHERE user_id = ?
    ");
    $getU->bind_param("i", $user_id);
    $getU->execute();
    $ru = $getU->get_result()->fetch_assoc();
    $getU->close();

    if ($ru) {
        $spec = trim($ru['specialization'] ?? '') ?: 'غير محدد';
        $job  = trim($ru['job_grade'] ?? '') ?: 'غير محدد';
        $acad = trim($ru['academic_year'] ?? '') ?: date('Y');

        $insT = $conn->prepare("
          INSERT INTO teachers (teacher_id, full_name, email, specialization, job_grade, academic_year, created_at)
          VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $insT->bind_param(
          "isssss",
          $user_id,
          $ru['full_name'],
          $ru['email'],
          $spec,
          $job,
          $acad
        );
        $insT->execute();
        $insT->close();
    }
}

/* ===================== حفظ بيانات الملف الشخصي ===================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $phone          = trim($_POST['phone'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $gender         = trim($_POST['gender'] ?? '');
    $marital_status = trim($_POST['marital_status'] ?? '');

    $save = $conn->prepare("
        INSERT INTO teachers (teacher_id, phone, address, gender, marital_status)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            phone = VALUES(phone),
            address = VALUES(address),
            gender = VALUES(gender),
            marital_status = VALUES(marital_status)
    ");
    $save->bind_param("issss", $user_id, $phone, $address, $gender, $marital_status);
    $save->execute();
    $save->close();
}

/* ===================== إعادة الجلب بعد الحفظ ===================== */

$stmt = $conn->prepare("
    SELECT full_name, email, specialization, job_grade, academic_year,
           phone, address, gender, marital_status
    FROM teachers
    WHERE teacher_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res     = $stmt->get_result();
$teacher = $res->fetch_assoc();
$stmt->close();

/* ===================== فحص النقص ===================== */

$missing_info = false;
if (
    empty($teacher['phone']) ||
    empty($teacher['address']) ||
    empty($teacher['gender']) ||
    empty($teacher['marital_status'])
) {
    $missing_info = true;
}

/* ===================== الإحصائيات ===================== */

$stats_lists        = 0;
$stats_students     = 0;
$stats_attendance   = 0;
$stats_reservations = 0;

try {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT a.list_id)
        FROM attendance a
        WHERE a.teacher_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($stats_lists);
    $stmt->fetch();
    $stmt->close();
} catch (Throwable $e) {}

try {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT ls.student_id)
        FROM list_students ls
        INNER JOIN (
            SELECT DISTINCT list_id
            FROM attendance
            WHERE teacher_id = ?
        ) al ON al.list_id = ls.list_id
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($stats_students);
    $stmt->fetch();
    $stmt->close();
} catch (Throwable $e) {}

try {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM attendance
        WHERE teacher_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($stats_attendance);
    $stmt->fetch();
    $stmt->close();
} catch (Throwable $e) {}

$teacher_name_for_res = $teacher['full_name'] ?? '';
if ($teacher_name_for_res !== '') {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM reservations
            WHERE reserved_by = ?
        ");
        $stmt->bind_param("s", $teacher_name_for_res);
        $stmt->execute();
        $stmt->bind_result($stats_reservations);
        $stmt->fetch();
        $stmt->close();
    } catch (Throwable $e) {}
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>لوحة تحكم الأستاذ</title>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body {
      font-family: 'Cairo', sans-serif;
      margin: 0;
      background: #f0f2f5;
      direction: rtl;
      animation: fadeIn 1s ease-in;
    }
    @keyframes fadeIn { from {opacity: 0;} to {opacity: 1;} }

    .navbar {
      background: linear-gradient(to left, #444b51ff, #485b6eff);
      color: white;
      padding: 14px 26px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .navbar h1 {
      margin: 0;
      font-size: 22px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .navbar h1 i {
      font-size: 22px;
      color: #ffffffff;
    }
    .navbar ul {
      list-style: none;
      display: flex;
      gap: 18px;
      margin: 0;
      padding: 0;
    }
    .navbar ul li a {
      color: white;
      text-decoration: none;
      font-size: 14px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      transition: background 0.2s ease, color 0.2s ease, transform 0.1s ease;
    }
    .navbar ul li a i {
      font-size: 14px;
    }
    .navbar ul li a:hover {
      background: rgba(255,255,255,0.08);
      color: #ffffffff;
      transform: translateY(-1px);
    }

    .header {
    background: linear-gradient(to right, #5c6bc0, #512da8);
      color: white;
      padding: 35px 30px 32px;
      text-align: center;
      border-bottom: 4px solid #512da8;
    }
    .header h2 {
      font-size: 26px;
      margin-bottom: 6px;
    }
    .header p {
      font-size: 15px;
      opacity: 0.92;
      margin: 0;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      padding: 26px 30px 30px;
    }
    .card {
      background: linear-gradient(to bottom left, #ffffff, #f9fafb);
      border-radius: 14px;
      box-shadow: 0 6px 14px rgba(15,23,42,0.10);
      padding: 20px 18px;
      text-align: center;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      position: relative;
      overflow: hidden;
    }
    .card::before {
      content: "";
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at top right, rgba(26,188,156,0.15), transparent 50%);
      opacity: 0.8;
      pointer-events: none;
    }
    .card-inner {
      position: relative;
      z-index: 1;
    }
    .card:hover {
      transform: translateY(-4px);
      box-shadow: 0 10px 22px rgba(15,23,42,0.16);
    }
    .card-icon {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: rgba(26,188,156,0.10);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }
    .card-icon i {
      font-size: 20px;
      color: #634a9cff;
    }
    .card h3 {
      margin: 0 0 4px;
      color: #1f2933;
      font-size: 16px;
    }
    .card .value {
      font-size: 22px;
      font-weight: 800;
      color: #111827;
      margin-bottom: 4px;
    }
    .card .sub {
      font-size: 12px;
      color: #6b7280;
    }

    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.55);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
    }
    .profile-modal {
      background: #fff;
      border-radius: 12px;
      padding: 22px 20px 20px;
      width: 400px;
      max-width: 95%;
      box-shadow: 0 10px 25px rgba(0,0,0,0.2);
      animation: popIn 0.4s ease;
      box-sizing: border-box;
    }
    @keyframes popIn { from {transform: scale(0.9); opacity:0;} to {transform: scale(1); opacity:1;} }
    .profile-modal h3 {
      margin-top: 0;
      text-align: center;
      color: #6140afff;
      margin-bottom: 8px;
      font-size: 18px;
    }
    .profile-modal p {
      font-size: 13px;
      color: #4b5563;
      text-align: center;
      margin: 0 0 10px;
    }
    .profile-modal label {
      display: block;
      margin-top: 10px;
      color: #111827;
      font-weight: 600;
      font-size: 13px;
    }
    .profile-modal input,
    .profile-modal select {
      width: 100%;
      padding: 8px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      margin-top: 4px;
      font-family: 'Cairo', sans-serif;
      font-size: 13px;
      box-sizing: border-box;
    }
    .profile-modal button {
      margin-top: 16px;
      width: 100%;
      padding: 9px;
      border: none;
    background: linear-gradient(to right, #5c6bc0, #512da8);
      color: white;
      font-size: 15px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 700;
    }
    .profile-modal button:hover { opacity: 0.95; }
  </style>
</head>
<body>

  <div class="navbar">
    <h1>
      <i class="fa-solid fa-chalkboard-user"></i>
      لوحة تحكم الأستاذ
    </h1>
    <ul>
      <li>
        <a href="teacher_dashboard.php">
          <i class="fa-solid fa-house"></i>
          <span>الرئيسية</span>
        </a>
      </li>
      <li>
        <a href="week_table_view.php">
          <i class="fa-solid fa-calendar-week"></i>
          <span>الجدول الأسبوعي</span>
        </a>
      </li>
      <li>
        <a href="attendance.php">
          <i class="fa-solid fa-user-check"></i>
          <span>إدارة الحضور</span>
        </a>
      </li>
      <li>
        <a href="attendance_view.php">
          <i class="fa-solid fa-list-check"></i>
          <span>قوائم الحضور</span>
        </a>
      </li>
      <li>
        <a href="reservations.php">
          <i class="fa-solid fa-door-open"></i>
          <span>حجز القاعات</span>
        </a>
      </li>
      <li>
        <a href="exams.php">
          <i class="fa-solid fa-file-pen"></i>
          <span>جداول الامتحانات</span>
        </a>
      </li>
      <li>
        <a href="profile.php">
          <i class="fa-solid fa-user"></i>
          <span>الملف الشخصي</span>
        </a>
      </li>
      <li>
        <a href="register.php">
          <i class="fa-solid fa-right-from-bracket"></i>
          <span>خروج</span>
        </a>
      </li>
    </ul>
  </div>

  <div class="header">
    <h2>مرحبًا بك <?= htmlspecialchars($teacher['full_name'] ?? 'أستاذ', ENT_QUOTES, 'UTF-8') ?></h2>
    <p>من هذه اللوحة يمكنك إدارة قوائم طلابك، سجلات الحضور، حجوزات القاعات وجداول الامتحانات بسهولة.</p>
  </div>

  <div class="stats">
    <div class="card">
      <div class="card-inner">
        <div class="card-icon">
          <i class="fa-solid fa-rectangle-list"></i>
        </div>
        <h3>قوائم الحضور</h3>
        <div class="value"><?= (int)$stats_lists ?></div>
        <div class="sub">عدد القوائم التي تم تسجيل حضور عليها باسمك.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-inner">
        <div class="card-icon">
          <i class="fa-solid fa-users"></i>
        </div>
        <h3>طلابك المسجلون</h3>
        <div class="value"><?= (int)$stats_students ?></div>
        <div class="sub">عدد طلاب قوائم الحضور المرتبطة بك (كل طالب يُحسب مرة واحدة).</div>
      </div>
    </div>

    <div class="card">
      <div class="card-inner">
        <div class="card-icon">
          <i class="fa-solid fa-user-check"></i>
        </div>
        <h3>سجلات الحضور</h3>
        <div class="value"><?= (int)$stats_attendance ?></div>
        <div class="sub">عدد مرات تسجيل الحضور أو الغياب لطلابك.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-inner">
        <div class="card-icon">
          <i class="fa-solid fa-door-open"></i>
        </div>
        <h3>حجوزات القاعات</h3>
        <div class="value"><?= (int)$stats_reservations ?></div>
        <div class="sub">إجمالي حجوزات القاعات التي قمت بها.</div>
      </div>
    </div>
  </div>

  <?php if ($missing_info): ?>
    <div class="overlay">
      <form method="POST" class="profile-modal">
        <h3>أكمل معلوماتك الشخصية</h3>
        <p>للاستفادة الكاملة من النظام، يرجى تعبئة بياناتك الأساسية بشكل صحيح.</p>

        <label for="phone">رقم الهاتف</label>
        <input
          type="text"
          id="phone"
          name="phone"
          value="<?= htmlspecialchars($teacher['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          required
        >

        <label for="address">العنوان</label>
        <input
          type="text"
          id="address"
          name="address"
          value="<?= htmlspecialchars($teacher['address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          required
        >

        <label for="gender">الجنس</label>
        <select id="gender" name="gender" required>
          <option value="">اختر</option>
          <option value="ذكر"   <?= (($teacher['gender'] ?? '') === 'ذكر')   ? 'selected' : '' ?>>ذكر</option>
          <option value="أنثى" <?= (($teacher['gender'] ?? '') === 'أنثى') ? 'selected' : '' ?>>أنثى</option>
        </select>

        <label for="marital_status">الحالة الاجتماعية</label>
        <select id="marital_status" name="marital_status" required>
          <option value="">اختر</option>
          <option value="أعزب"   <?= (($teacher['marital_status'] ?? '') === 'أعزب')   ? 'selected' : '' ?>>أعزب</option>
          <option value="متزوج" <?= (($teacher['marital_status'] ?? '') === 'متزوج') ? 'selected' : '' ?>>متزوج</option>
          <option value="مطلق"  <?= (($teacher['marital_status'] ?? '') === 'مطلق')  ? 'selected' : '' ?>>مطلق</option>
          <option value="أرمل"  <?= (($teacher['marital_status'] ?? '') === 'أرمل')  ? 'selected' : '' ?>>أرمل</option>
        </select>

        <button type="submit" name="update_profile">حفظ المعلومات</button>
      </form>
    </div>
  <?php endif; ?>

</body>
</html>
<?php
$conn->close();
?>
