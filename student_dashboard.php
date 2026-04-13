<?php

// في بداية student_dashboard.php (بعد session_start)
if (isset($_SESSION['user_role_name']) && $_SESSION['user_role_name']==='student') {
  require_once 'db.php'; // أو كود الاتصال
  $uid = (int)$_SESSION['user_id'];
  $st = $conn->prepare("SELECT profile_completed FROM users WHERE user_id=?");
  $st->bind_param("i",$uid); 
  $st->execute(); 
  $st->bind_result($done); 
  $st->fetch(); 
  $st->close();

  if ((int)$done !== 1) {
    header("Location: student_profile_form.php");
    exit;
  }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <title>لوحة تحكم الطالب</title>
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
      transform: translateY(-1px);     }

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
    <h1>لوحة تحكم الطالب</h1>
    <ul>
      <li><a href="student_dashboard.php"><i class="fas fa-home"></i> الرئيسية</a></li>

      <li><a href="student_profile_form.php"><i class="fas fa-id-card"></i> استمارة ملئ المعلومات</a></li>
      <li><a href="week_table_view.php"><i class="fas fa-calendar-alt"></i> الجدول الاسبوعي</a></li>
      <li><a href="exams.php"><i class="fas fa-file-alt"></i> جدول الامتحانات</a></li>
      <li><a href="student_attendance.php"><i class="fas fa-user-check"></i> الحضور</a></li>
      <li><a href="profile.php"><i class="fas fa-user"></i> الملف الشخصي</a></li>
      <li><a href="register.php"><i class="fas fa-sign-out-alt"></i> خروج</a></li>
    </ul>
  </div>

  <!-- Header -->
  <div class="header">
    <h2>مرحبًا بك في لوحة الطالب</h2>
    <p>تابع جدولك الدراسي، الامتحانات، والحضور بكل سهولة</p>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="card">
      <i class="fas fa-calendar-week"></i>
      <h3>جدولي الدراسي</h3>
      <p>5 مواد هذا الفصل</p>
    </div>
    <div class="card">
      <i class="fas fa-file-signature"></i>
      <h3>الامتحانات القادمة</h3>
      <p>2 امتحان خلال الأسبوع</p>
    </div>
    <div class="card">
      <i class="fas fa-user-check"></i>
      <h3>نسبة الحضور</h3>
      <p>92% حضور حتى الآن</p>
    </div>
    <div class="card">
      <i class="fas fa-user"></i>
      <h3>الملف الشخصي</h3>
      <p>عرض وتحديث بياناتك</p>
    </div>
  </div>

</body>
</html>
