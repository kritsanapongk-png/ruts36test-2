<?php
declare(strict_types=1);
ini_set('session.save_path', __DIR__ . '/storage/sessions');
session_start();
require __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('กรุณาเข้าสู่ระบบในฐานะนักศึกษา');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT sp.*, u.login_id, approver.login_id AS approver_name FROM student_profiles sp JOIN users u ON u.id=sp.user_id LEFT JOIN users approver ON approver.id=sp.final_approved_by WHERE sp.user_id=?');
$stmt->execute([(int) $_SESSION['user_id']]);
$profile = $stmt->fetch();
$stmt = $pdo->prepare("SELECT * FROM activities WHERE student_id=? AND status='approved' ORDER BY activity_date, start_time");
$stmt->execute([(int) $_SESSION['user_id']]);
$activities = $stmt->fetchAll();
$total = array_sum(array_map(static fn(array $a): float => (float) $a['hours'], $activities));
function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>แบบบันทึกกิจกรรมจิตอาสา - <?= h($profile['student_code']) ?></title>
<style>
@page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:"Noto Sans Thai",Tahoma,sans-serif;color:#111;margin:0;font-size:13px}.toolbar{display:flex;justify-content:flex-end;gap:8px;margin-bottom:18px}.toolbar button{border:0;border-radius:8px;padding:10px 16px;background:#002b6c;color:#fff;font:inherit;cursor:pointer}.header{text-align:center;margin-bottom:18px}.header h1{font-size:23px;margin:0 0 6px}.header h2{font-size:17px;margin:0;font-weight:600}.student{border:1px solid #333;padding:12px 16px;display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;margin-bottom:14px}.student div:first-child{grid-column:span 3}table{border-collapse:collapse;width:100%;table-layout:fixed}th,td{border:1px solid #333;padding:7px;vertical-align:top;overflow-wrap:anywhere}th{text-align:center;background:#f0f3f8}td.center{text-align:center}.signature{max-width:110px;max-height:45px;display:block;margin:2px auto}.summary{display:flex;justify-content:space-between;align-items:flex-start;margin-top:14px}.total{font-size:17px;font-weight:700}.approval{text-align:center;min-width:260px;border-top:1px solid #333;padding-top:7px;margin-top:35px}.muted{color:#555}@media(max-width:700px){body{font-size:11px}.student{grid-template-columns:1fr}.student div:first-child{grid-column:auto}.report-wrap{overflow-x:auto}table{min-width:1050px}}@media print{.toolbar{display:none}body{font-size:11px}th,td{padding:5px}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button></div>
<header class="header"><h1>มหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย</h1><h2>บันทึกการเข้าร่วมโครงการ/กิจกรรมที่ทำประโยชน์ต่อสังคมหรือสาธารณะ</h2></header>
<section class="student">
  <div><b>ชื่อ:</b> <?= h($profile['first_name'].' '.$profile['last_name']) ?> &nbsp; <b>รหัสนักศึกษา:</b> <?= h($profile['student_code']) ?></div>
  <div><b>คณะ:</b> <?= h($profile['faculty']) ?></div><div><b>สาขา:</b> <?= h($profile['major']) ?></div><div><b>ชั้นปี:</b> <?= h($profile['study_year']) ?></div>
  <div><b>ภาคเรียนที่:</b> <?= h($profile['semester']) ?></div><div><b>ปีการศึกษา:</b> <?= h($profile['academic_year']) ?></div>
</section>
<div class="report-wrap"><table>
<thead><tr><th style="width:17%">ชื่อโครงการ/กิจกรรม</th><th style="width:13%">สถานที่</th><th style="width:10%">วัน/เดือน/ปี</th><th style="width:10%">เวลา</th><th style="width:8%">ชั่วโมง</th><th style="width:20%">ลักษณะกิจกรรม</th><th style="width:12%">ผู้รับรอง</th><th style="width:10%">ลายมือชื่อ</th></tr></thead>
<tbody>
<?php if (!$activities): ?><tr><td colspan="8" class="center muted">ยังไม่มีรายการกิจกรรม</td></tr><?php endif; ?>
<?php foreach ($activities as $activity): ?><tr>
<td><?= h($activity['activity_name']) ?></td><td><?= h($activity['organization']) ?></td><td class="center"><?= h(date('d/m/Y', strtotime($activity['activity_date']))) ?></td><td class="center"><?= h(substr($activity['start_time'],0,5).' - '.substr($activity['end_time'],0,5)) ?></td><td class="center"><?= h(rtrim(rtrim((string)$activity['hours'],'0'),'.')) ?></td><td><?= nl2br(h($activity['description'])) ?></td><td><?= h($activity['certifier_name']) ?><br><span class="muted"><?= h($activity['certifier_position']) ?><br><?= h($activity['certifier_org']) ?></span></td><td><?php if ($activity['signature']): ?><img class="signature" src="<?= h($activity['signature']) ?>" alt="ลายมือชื่อผู้รับรอง"><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
<section class="summary"><div class="total">รวม <?= h(rtrim(rtrim((string)$total,'0'),'.')) ?> / 36 ชั่วโมง</div><div class="approval"><?= $profile['final_approved'] ? 'รับรองโดยเจ้าหน้าที่ '.h($profile['approver_name']).'<br>'.h($profile['final_approved_at']) : 'ยังไม่ผ่านการอนุมัติครบ 36 ชั่วโมง' ?></div></section>
</body></html>
