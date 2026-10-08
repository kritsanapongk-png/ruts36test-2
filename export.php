<?php
declare(strict_types=1);
ini_set('session.save_path', __DIR__ . '/storage/sessions');
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    http_response_code(403);
    exit('คุณไม่มีสิทธิ์ส่งออกรายงาน');
}

$pdo = db();
$sql = "SELECT sp.student_code, sp.first_name, sp.last_name, sp.faculty, sp.major, sp.study_year, sp.semester, sp.academic_year,
COALESCE(SUM(CASE WHEN a.status='approved' THEN a.hours ELSE 0 END),0) approved_hours,
COALESCE(SUM(CASE WHEN a.status='submitted' THEN a.hours ELSE 0 END),0) pending_hours,
COUNT(a.id) activity_count, IF(sp.final_approved=1,'อนุมัติครบ 36 ชั่วโมง','ยังไม่อนุมัติ') final_status
FROM student_profiles sp LEFT JOIN activities a ON a.student_id=sp.user_id
GROUP BY sp.user_id ORDER BY sp.faculty, sp.major, sp.student_code";
$rows = $pdo->query($sql)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ruts36-report-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['รหัสนักศึกษา','ชื่อ','นามสกุล','คณะ','สาขา','ชั้นปี','ภาคเรียน','ปีการศึกษา','ชั่วโมงผ่าน','ชั่วโมงรอตรวจ','จำนวนกิจกรรม','สถานะรวม']);
foreach ($rows as $row) fputcsv($out, array_values($row));
fclose($out);
