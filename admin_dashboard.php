<?php
session_start();

if (!isset($_SESSION['user_role_name']) || $_SESSION['user_role_name'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// اتصال قاعدة البيانات
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "Shatt_al_Arab";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");

function get_count(mysqli $conn, string $table): int {
    $table_escaped = $conn->real_escape_string($table);
    $check = $conn->query("SHOW TABLES LIKE '{$table_escaped}'");
    if ($check->num_rows === 0) {
        return 0; // لو ماكو جدول يرجع 0 بدون خطأ
    }
    $res = $conn->query("SELECT COUNT(*) AS c FROM `{$table_escaped}`");
    $row = $res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

// جلب الأعداد من الجداول
$users_count        = get_count($conn, 'users');               // المستخدمين
$teachers_count     = get_count($conn, 'teachers');            // الأساتذة
$students_count     = get_count($conn, 'students');            // الطلاب

$timetables_count   = get_count($conn, 'schedules');   // الجداول الأسبوعية

$exam_count         = get_count($conn, 'exam_schedules');      // جداول الامتحانات

$reservations_count = get_count($conn, 'reservations');   // الحجوزات الحالية (إجمالي الحجوزات)
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>لوحة تحكم الإدمن</title>
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

    @keyframes fadeIn {
      from {opacity: 0;}
      to {opacity: 1;}
    }

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

    .navbar ul {
      list-style: none;
      display: flex;
      gap: 20px;
      margin: 0;
      padding: 0;
    }

    .navbar ul li a {
      color: white;
      text-decoration: none;
      transition: color 0.3s;
    }

    .navbar ul li a:hover {
 background: rgba(255,255,255,0.08);
      color: #ffffffff;
      transform: translateY(-1px);    }

    .header {
    background: linear-gradient(to right, #5c6bc0, #512da8);
      color: white;
      padding: 35px 30px 32px;
      text-align: center;
      border-bottom: 4px solid #512da8;
    }

    .header h2 {
      font-size: 30px;
      margin-bottom: 10px;
    }

    .header p {
      font-size: 16px;
      opacity: 0.9;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      padding: 30px;
    }

    .card {
      background: linear-gradient(to bottom left, #ffffff, #f9f9f9);
      border-radius: 12px;
      box-shadow: 0 6px 12px rgba(0,0,0,0.1);
      padding: 25px;
      text-align: center;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      animation: slideUp 0.6s ease forwards;
    }

    @keyframes slideUp {
      from { transform: translateY(20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .card:hover {
      transform: translateY(-5px);
      box-shadow: 0 10px 20px rgba(0,0,0,0.15);
    }

    .card i {
      font-size: 30px;
      color: #634a9cff;
      margin-bottom: 10px;
    }

    .card h3 {
      margin-bottom: 10px;
      color: #2c3e50;
    }

    .card p {
      font-size: 14px;
      color: #7f8c8d;
    }

    .footer {
      text-align: center;
      padding: 15px;
      background-color: #ecf0f1;
      font-size: 13px;
      color: #7f8c8d;
    }
  </style>
</head>
<body>

  <!-- Navbar -->
  <div class="navbar">
    <h1>لوحة تحكم الإدمن</h1>
    <ul>
      <li><a href="admin_dashboard.php"><i class="fas fa-home"></i> الرئيسية</a></li>
      <li><a href="users.php"><i class="fas fa-users"></i> المستخدمين</a></li>
      <li><a href="admin_teachers.php"><i class="fas fa-chalkboard-teacher"></i> الأساتذة</a></li>
      <li><a href="students_admin.php"><i class="fas fa-user-graduate"></i> الطلاب</a></li>
      <li><a href="create_list.php"><i class="fa-solid fa-list-check"></i>قوائم الطلاب</a></li>
      <li><a href="manage_timetables.php"><i class="fas fa-calendar-alt"></i>  الجداول الاسبوعية </a></li>
      <li><a href="exam_Schedule.php"><i class="fas fa-file-alt"></i> جداول الامتحانات</a></li>
      <li><a href="rooms.php"><i class="fas fa-door-open"></i> القاعات</a></li>
      <li><a href="admin_reservations.php"><i class="fas fa-door-open"></i> حجز القاعات</a></li>
      <li><a href="profile.php"><i class="fas fa-user-cog"></i> الملف الشخصي</a></li>
      <li><a href="register.php"><i class="fas fa-sign-out-alt"></i> خروج</a></li>
    </ul>
  </div>

  <!-- Header -->
  <div class="header">
    <h2>نظام إدارة الجداول والامتحانات</h2>
    <p>لوحة تحكم متكاملة لإدارة قسم النظم بكل احترافية وسهولة</p>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="card">
      <i class="fas fa-user"></i>
      <h3>عدد المستخدمين</h3>
      <p><?php echo (int)$users_count; ?> مستخدمًا</p>
    </div>
    <div class="card">
      <i class="fas fa-chalkboard-teacher"></i>
      <h3>عدد الأساتذة</h3>
      <p><?php echo (int)$teachers_count; ?> أستاذًا</p>
    </div>
    <div class="card">
      <i class="fas fa-user-graduate"></i>
      <h3>عدد الطلاب</h3>
      <p><?php echo (int)$students_count; ?> طالبًا</p>
    </div>
    <div class="card">
      <i class="fas fa-calendar-week"></i>
      <h3>عدد الجداول</h3>
      <p><?php echo (int)$timetables_count; ?> جدولًا أسبوعيًا</p>
    </div>
    <div class="card">
      <i class="fas fa-file-signature"></i>
      <h3>الامتحانات المجدولة</h3>
      <p><?php echo (int)$exam_count; ?> امتحانًا قادمًا</p>
    </div>
    <div class="card">
      <i class="fas fa-door-closed"></i>
      <h3>الحجوزات الحالية</h3>
      <p><?php echo (int)$reservations_count; ?> قاعات محجوزة</p>
    </div>
  </div>

</body>
</html>
