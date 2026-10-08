<?php
declare(strict_types=1);
ini_set('display_errors', '0');

ini_set('session.save_path', __DIR__ . '/storage/sessions');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function requireLogin(?string $role = null, bool $allowMustChange = false): array
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        respond(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบ'], 401);
    }
    if ($role !== null && $_SESSION['role'] !== $role) {
        respond(['ok' => false, 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ'], 403);
    }
    return ['id' => (int) $_SESSION['user_id'], 'role' => (string) $_SESSION['role']];
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        respond(['ok' => false, 'message' => 'เซสชันหมดอายุ กรุณาลองใหม่'], 419);
    }
}

function audit(PDO $pdo, int $actorId, string $action, string $entityType, ?int $entityId = null, array $details = []): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$actorId, $action, $entityType, $entityId, json_encode($details, JSON_UNESCAPED_UNICODE), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
}

function notifyUser(PDO $pdo, int $userId, string $title, string $message, ?int $activityId = null): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, activity_id, title, message) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $activityId, $title, $message]);
}

function submissionWindow(PDO $pdo, ?string $academicYear = null): array
{
    if ($academicYear !== null) {$stmt=$pdo->prepare('SELECT academic_year AS academicYear, open_date AS openDate, close_date AS closeDate, is_enabled AS isEnabled FROM submission_windows WHERE academic_year=?');$stmt->execute([$academicYear]);$row=$stmt->fetch();}
    else {$row=$pdo->query('SELECT academic_year AS academicYear, open_date AS openDate, close_date AS closeDate, is_enabled AS isEnabled FROM submission_windows ORDER BY academic_year DESC LIMIT 1')->fetch();}
    if (!$row) return [];
    $row['isOpen']=(bool)$row['isEnabled'] && date('Y-m-d') >= $row['openDate'] && date('Y-m-d') <= $row['closeDate'];
    return $row;
}

function persistDataImage(string $value, int $userId, string $kind): string
{
    if (preg_match('#^storage/uploads/[0-9]+/[a-f0-9]+\.(?:png|jpg|webp)$#', $value)) return $value;
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $value, $matches)) throw new InvalidArgumentException('รูปภาพไม่ถูกต้อง');
    $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
    $binary = base64_decode($matches[2], true);
    $maxBytes = $kind === 'evidence' ? 12 * 1024 * 1024 : 2200000;
    if ($binary === false || strlen($binary) > $maxBytes) {
        throw new InvalidArgumentException($kind === 'evidence' ? 'รูปหลักฐานต้องมีขนาดไม่เกิน 12 MB ต่อไฟล์' : 'รูปลายมือชื่อมีขนาดไม่ถูกต้อง');
    }
    $imageInfo = @getimagesizefromstring($binary);
    $allowedMimes = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!$imageInfo || !isset($allowedMimes[$imageInfo['mime']]) || $allowedMimes[$imageInfo['mime']] !== $extension) throw new InvalidArgumentException('เนื้อหาไฟล์รูปภาพไม่ถูกต้อง');
    $relativeDir = 'storage/uploads/' . $userId;
    $absoluteDir = __DIR__ . '/' . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) throw new RuntimeException('ไม่สามารถจัดเก็บไฟล์ได้');
    $relativePath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (file_put_contents(__DIR__ . '/' . $relativePath, $binary, LOCK_EX) === false) throw new RuntimeException('ไม่สามารถจัดเก็บไฟล์ได้');
    return $relativePath;
}

function normalizePhone(mixed $value): string
{
    $phone = preg_replace('/\D+/', '', (string) $value) ?? '';
    if (strlen($phone) === 9) $phone = '0' . $phone;
    return preg_match('/^0\d{9}$/', $phone) ? $phone : '';
}

function importStudentRows(PDO $pdo, int $adminId, array $rows): array
{
    if (count($rows) > 500) respond(['ok' => false, 'message' => 'ไฟล์นำเข้าต้องมีไม่เกิน 500 รายการ'], 422);
    $created = 0; $skipped = 0;
    foreach ($rows as $row) {
        $code = trim((string) ($row['studentCode'] ?? ''));
        $firstName = trim((string) ($row['firstName'] ?? ''));
        $phone = normalizePhone($row['phone'] ?? '');
        $nationalId = preg_replace('/\D+/', '', (string) ($row['nationalId'] ?? '')) ?: null;
        if ($nationalId !== null && strlen($nationalId) !== 13) { $skipped++; continue; }
        if ($code === '' || $firstName === '' || $phone === '') { $skipped++; continue; }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO users (login_id, password_hash, role, phone, must_change_password) VALUES (?,?,'student',?,0)");
            $stmt->execute([$code, password_hash($phone, PASSWORD_DEFAULT), $phone]);
            $id = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id, first_name, last_name, student_code, national_id, faculty, major, study_year, semester, academic_year) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$id, $firstName, trim((string) ($row['lastName'] ?? '-')) ?: '-', $code, $nationalId, trim((string) ($row['faculty'] ?? '-')) ?: '-', trim((string) ($row['major'] ?? '-')) ?: '-', trim((string) ($row['year'] ?? '1')) ?: '1', trim((string) ($row['semester'] ?? '1')) ?: '1', trim((string) ($row['academicYear'] ?? ((int) date('Y') + 543))) ]);
            $pdo->commit(); $created++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') { $skipped++; continue; }
            throw $e;
        }
    }
    audit($pdo, $adminId, 'students.imported', 'user', null, ['created' => $created, 'skipped' => $skipped]);
    return ['created' => $created, 'skipped' => $skipped];
}

function parseStudentWorkbook(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('ไม่สามารถเปิดไฟล์ Excel ได้');
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);$ns = $xml->getNamespaces(true);$nodes = $xml->children($ns[''] ?? null)->si;
        foreach ($nodes as $item) { $parts=[];foreach ($item->xpath('.//*[local-name()="t"]') as $text) $parts[]=(string)$text;$shared[]=implode('', $parts); }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
    if ($sheetXml === false) throw new InvalidArgumentException('ไม่พบชีตข้อมูลนักศึกษา');
    $xml = simplexml_load_string($sheetXml);$rows=[];
    foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
        $values=[];
        foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
            preg_match('/^[A-Z]+/', (string)$cell['r'], $match);$column=$match[0]??'';
            $type=(string)$cell['t'];$raw=(string)($cell->v ?? '');$values[$column]=$type==='s'?($shared[(int)$raw]??''):$raw;
        }
        $rows[]=$values;
    }
    if (!$rows) return [];
    $header=array_shift($rows);$columnMap=[];
    foreach ($header as $column=>$label) { $label=trim($label);if($label==='รหัสนักศึกษา')$columnMap['studentCode']=$column;if($label==='ชื่อ-สกุล')$columnMap['fullName']=$column;if($label==='เบอร์โทรศัพท์')$columnMap['phone']=$column;if(in_array($label,['เลขบัตรประชาชน','เลขประจำตัวประชาชน'],true))$columnMap['nationalId']=$column; }
    if (count($columnMap)<3) throw new InvalidArgumentException('ชีตแรกต้องมีคอลัมน์ รหัสนักศึกษา ชื่อ-สกุล และเบอร์โทรศัพท์');
    $result=[];
    foreach($rows as $row){$fullName=trim((string)($row[$columnMap['fullName']]??''));$parts=preg_split('/\s+/u',$fullName)?:[];$lastName=count($parts)>1?array_pop($parts):'-';$firstName=implode(' ',$parts);$code=preg_replace('/\.0$/','',(string)($row[$columnMap['studentCode']]??''));$result[]=['studentCode'=>$code,'firstName'=>$firstName,'lastName'=>$lastName,'phone'=>$row[$columnMap['phone']]??'','nationalId'=>isset($columnMap['nationalId'])?($row[$columnMap['nationalId']]??''):''];}
    return $result;
}

function activitiesForStudent(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare('SELECT id, activity_name AS name, activity_date AS date, start_time AS startTime, end_time AS endTime, hours, organization, description, certifier_name AS certifierName, certifier_position AS certifierPosition, certifier_org AS certifierOrg, status, review_note AS reviewNote, reviewed_at AS reviewedAt, evidence_images AS images, signature FROM activities WHERE student_id = ? ORDER BY activity_date DESC, id DESC');
    $stmt->execute([$studentId]);
    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['hours'] = (float) $row['hours'];
        $row['images'] = json_decode($row['images'] ?: '[]', true) ?: [];
        return $row;
    }, $stmt->fetchAll());
}

try {
    $pdo = db();
    $action = $_GET['action'] ?? '';

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = body();
        $role = in_array(($input['role'] ?? ''), ['student', 'staff', 'admin'], true) ? $input['role'] : 'student';
        $loginId = trim((string) ($input['loginId'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $attempt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE login_id=? AND ip_address=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        $attempt->execute([$loginId, $ip]);
        if ((int) $attempt->fetchColumn() >= 5) respond(['ok' => false, 'message' => 'เข้าสู่ระบบผิดหลายครั้ง กรุณารอ 15 นาที'], 429);
        $stmt = $pdo->prepare('SELECT id, role, password_hash, must_change_password FROM users WHERE login_id = ? AND role = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$loginId, $role]);
        $user = $stmt->fetch();
        $validPassword = $user && (str_starts_with($user['password_hash'], '$2') ? password_verify($password, $user['password_hash']) : hash_equals($user['password_hash'], hash('sha256', $password)));
        if (!$validPassword) {
            $pdo->prepare('INSERT INTO login_attempts (login_id, ip_address) VALUES (?,?)')->execute([$loginId, $ip]);
            respond(['ok' => false, 'message' => 'รหัสผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 422);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE login_id=? AND ip_address=?')->execute([$loginId, $ip]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['must_change_password'] = false;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['ok' => true, 'role' => $user['role'], 'csrf' => $_SESSION['csrf'], 'mustChangePassword' => false]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }

    if ($action === 'session') {
        if (empty($_SESSION['user_id'])) respond(['ok' => true, 'loggedIn' => false, 'csrf' => $_SESSION['csrf']]);
        respond(['ok' => true, 'loggedIn' => true, 'role' => $_SESSION['role'], 'csrf' => $_SESSION['csrf'], 'mustChangePassword' => false]);
    }

    if ($action === 'data') {
        $user = requireLogin();
        $stmt = $pdo->prepare('SELECT login_id AS loginId, role, first_name AS firstName, last_name AS lastName, phone FROM users WHERE id=?');
        $stmt->execute([$user['id']]);
        $currentUser = $stmt->fetch() ?: ['loginId' => '', 'role' => $user['role']];
        if ($user['role'] === 'student') {
            $stmt = $pdo->prepare('SELECT first_name AS firstName, last_name AS lastName, student_code AS studentCode, national_id AS nationalId, faculty, major, study_year AS year, semester, academic_year AS academicYear, final_approved AS finalApproved, final_approved_at AS finalApprovedAt FROM student_profiles WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $profile = $stmt->fetch();
            if (!$profile) respond(['ok' => false, 'message' => 'ไม่พบข้อมูลนักศึกษา'], 404);
            $approval = ['approved' => (bool) $profile['finalApproved'], 'date' => $profile['finalApprovedAt'] ?: ''];
            unset($profile['finalApproved'], $profile['finalApprovedAt']);
            respond(['ok' => true, 'currentUser' => $currentUser, 'profile' => $profile, 'activities' => activitiesForStudent($pdo, $user['id']), 'finalApproval' => $approval, 'submissionWindow' => submissionWindow($pdo, $profile['academicYear'])]);
        }
        if ($user['role'] === 'admin') {
            $accounts = $pdo->query("SELECT u.id, u.login_id AS loginId, u.role, u.phone, u.must_change_password AS mustChangePassword, u.is_active AS isActive, u.created_at AS createdAt, COALESCE(sp.first_name,u.first_name) AS firstName, COALESCE(sp.last_name,u.last_name) AS lastName, sp.national_id AS nationalId, sp.faculty, sp.major FROM users u LEFT JOIN student_profiles sp ON sp.user_id=u.id WHERE u.role IN ('student','staff') ORDER BY u.role, u.login_id")->fetchAll();
            $stats = $pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE is_active=1) activeUsers, (SELECT COUNT(*) FROM student_profiles) students, (SELECT COUNT(*) FROM student_profiles WHERE final_approved=1) completedStudents, (SELECT COUNT(*) FROM activities WHERE status IN ('submitted','resubmitted')) pendingActivities")->fetch();
            respond(['ok' => true, 'currentUser' => $currentUser, 'users' => $accounts, 'stats' => $stats]);
        }
        $students = $pdo->query("SELECT sp.user_id, sp.first_name AS firstName, sp.last_name AS lastName, sp.student_code AS studentCode, sp.national_id AS nationalId, sp.faculty, sp.major, sp.study_year AS year, sp.semester, sp.academic_year AS academicYear, sp.final_approved AS finalApproved, sp.final_approved_at AS finalApprovedAt FROM student_profiles sp JOIN users u ON u.id=sp.user_id WHERE u.is_active=1 ORDER BY sp.student_code")->fetchAll();
        foreach ($students as &$student) {
            $student['activities'] = activitiesForStudent($pdo, (int) $student['user_id']);
            $student['finalApproval'] = ['approved' => (bool) $student['finalApproved'], 'date' => $student['finalApprovedAt'] ?: ''];
            unset($student['finalApproved'], $student['finalApprovedAt']);
        }
        respond(['ok' => true, 'currentUser' => $currentUser, 'students' => $students, 'submissionWindow' => submissionWindow($pdo)]);
    }

    if ($action === 'notifications') {
        $user = requireLogin();
        $stmt = $pdo->prepare('SELECT id, activity_id AS activityId, title, message, is_read AS isRead, created_at AS createdAt FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 30');
        $stmt->execute([$user['id']]);
        respond(['ok' => true, 'notifications' => $stmt->fetchAll()]);
    }

    if ($action === 'submission-window' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();$staff=requireLogin('staff');$input=body();$year=trim((string)($input['academicYear']??''));$open=(string)($input['openDate']??'');$close=(string)($input['closeDate']??'');$enabled=!empty($input['isEnabled'])?1:0;
        if(!preg_match('/^25\d{2}$/',$year)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$open)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$close)||$close<$open)respond(['ok'=>false,'message'=>'ปีการศึกษาหรือช่วงวันที่ไม่ถูกต้อง'],422);
        $stmt=$pdo->prepare('INSERT INTO submission_windows (academic_year,open_date,close_date,is_enabled,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE open_date=VALUES(open_date),close_date=VALUES(close_date),is_enabled=VALUES(is_enabled),updated_by=VALUES(updated_by)');$stmt->execute([$year,$open,$close,$enabled,$staff['id']]);
        $title=$enabled?'กำหนดช่วงเวลายื่นกิจกรรม':'ปิดรับการยื่นกิจกรรม';$message=$enabled?"ปีการศึกษา {$year} เปิดรับวันที่ {$open} ถึง {$close}":"ปีการศึกษา {$year} ปิดรับการยื่นกิจกรรม";$pdo->prepare("INSERT INTO notifications (user_id,title,message) SELECT id,?,? FROM users WHERE role='student' AND is_active=1")->execute([$title,$message]);
        audit($pdo,$staff['id'],'submission_window.updated','submission_window',null,['academicYear'=>$year,'openDate'=>$open,'closeDate'=>$close,'isEnabled'=>$enabled]);respond(['ok'=>true,'message'=>'บันทึกช่วงเวลารับกิจกรรมแล้ว']);
    }

    if ($action === 'notifications-read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $user = requireLogin();
        $input = body();
        $notificationId = (int) ($input['id'] ?? 0);
        if ($notificationId > 0) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND id=?');
            $stmt->execute([$user['id'], $notificationId]);
        } else {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?');
            $stmt->execute([$user['id']]);
        }
        respond(['ok' => true]);
    }

    if ($action === 'audit') {
        $admin = requireLogin('admin');
        $rows = $pdo->query("SELECT al.id, al.action, al.entity_type AS entityType, al.entity_id AS entityId, al.details, al.ip_address AS ipAddress, al.created_at AS createdAt, COALESCE(u.login_id,'ระบบ') AS actor FROM audit_logs al LEFT JOIN users u ON u.id=al.actor_id ORDER BY al.id DESC LIMIT 100")->fetchAll();
        respond(['ok' => true, 'logs' => $rows]);
    }

    if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        requireCsrf();
        $user = requireLogin('student');
        $input = body();
        $required = ['firstName', 'lastName', 'studentCode', 'faculty', 'major', 'year', 'semester', 'academicYear'];
        foreach ($required as $field) if (trim((string) ($input[$field] ?? '')) === '') respond(['ok' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบ'], 422);
        $nationalId = preg_replace('/\D+/', '', (string) ($input['nationalId'] ?? '')) ?: null;
        if ($nationalId !== null && strlen($nationalId) !== 13) respond(['ok' => false, 'message' => 'เลขบัตรประชาชนต้องมี 13 หลัก'], 422);
        $stmt = $pdo->prepare('UPDATE student_profiles SET first_name=?, last_name=?, student_code=?, national_id=?, faculty=?, major=?, study_year=?, semester=?, academic_year=? WHERE user_id=?');
        try {
            $stmt->execute([$input['firstName'], $input['lastName'], $input['studentCode'], $nationalId, $input['faculty'], $input['major'], $input['year'], $input['semester'], $input['academicYear'], $user['id']]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) respond(['ok' => false, 'message' => 'เลขบัตรประชาชนหรือรหัสนักศึกษานี้ถูกใช้กับบัญชีอื่นแล้ว'], 409);
            throw $e;
        }
        audit($pdo, $user['id'], 'profile.updated', 'student_profile', $user['id']);
        respond(['ok' => true, 'message' => 'บันทึกข้อมูลนักศึกษาแล้ว']);
    }

    if ($action === 'account-self' && $_SERVER['REQUEST_METHOD'] === 'PUT') {
        requireCsrf();
        $user = requireLogin('staff');
        $input = body();
        $firstName = trim((string) ($input['firstName'] ?? ''));
        $lastName = trim((string) ($input['lastName'] ?? ''));
        $phone = normalizePhone($input['phone'] ?? '');
        if ($firstName === '' || $lastName === '') respond(['ok' => false, 'message' => 'กรุณากรอกชื่อและนามสกุลให้ครบ'], 422);
        if (trim((string) ($input['phone'] ?? '')) !== '' && $phone === '') respond(['ok' => false, 'message' => 'เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก'], 422);
        $pdo->prepare('UPDATE users SET first_name=?, last_name=?, phone=? WHERE id=?')->execute([$firstName, $lastName, $phone, $user['id']]);
        audit($pdo, $user['id'], 'account.self_updated', 'user', $user['id']);
        respond(['ok' => true, 'message' => 'บันทึกข้อมูลบัญชีแล้ว']);
    }

    if ($action === 'password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $user = requireLogin(null, true);
        $input = body();
        $current = (string) ($input['currentPassword'] ?? '');
        $new = (string) ($input['newPassword'] ?? '');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');$stmt->execute([$user['id']]);$hash = (string) $stmt->fetchColumn();
        $valid = str_starts_with($hash, '$2') ? password_verify($current, $hash) : hash_equals($hash, hash('sha256', $current));
        if (!$valid || strlen($new) < 8) respond(['ok' => false, 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง หรือรหัสผ่านใหม่สั้นกว่า 8 ตัวอักษร'], 422);
        $pdo->prepare('UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['must_change_password'] = false;
        audit($pdo, $user['id'], 'password.changed', 'user', $user['id']);
        respond(['ok' => true, 'message' => 'เปลี่ยนรหัสผ่านแล้ว']);
    }

    if ($action === 'activity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $user = requireLogin('student');
        $input = body();
        $stmt=$pdo->prepare('SELECT academic_year FROM student_profiles WHERE user_id=?');$stmt->execute([$user['id']]);$window=submissionWindow($pdo,(string)$stmt->fetchColumn());if(!$window||empty($window['isOpen']))respond(['ok'=>false,'message'=>'ขณะนี้อยู่นอกช่วงเวลารับยื่นกิจกรรม'],403);
        $saveStatus = ($input['saveStatus'] ?? '') === 'draft' ? 'draft' : 'submitted';
        $hours = (float) ($input['hours'] ?? 0);
        if (empty($input['name'])) respond(['ok' => false, 'message' => 'กรุณาระบุชื่อกิจกรรม'], 422);
        if ($saveStatus !== 'draft') {
            if ($hours <= 0 || $hours > 36 || empty($input['date']) || empty($input['startTime']) || empty($input['endTime']) || empty($input['certifierName']) || empty($input['signature'])) respond(['ok' => false, 'message' => 'ข้อมูลกิจกรรมไม่ครบถ้วน'], 422);
            $start = strtotime('1970-01-01 ' . $input['startTime']);
            $end = strtotime('1970-01-01 ' . $input['endTime']);
            $calculatedHours = ($end - $start) / 3600;
            if ($start === false || $end === false || $end <= $start || abs((round($calculatedHours * 2) / 2) - $hours) > 0.01) respond(['ok' => false, 'message' => 'เวลาและจำนวนชั่วโมงไม่ถูกต้อง'], 422);
            if (!preg_match('#^(?:data:image/png;base64,|storage/uploads/[0-9]+/[a-f0-9]+\.png$)#', (string) $input['signature']) || strlen((string) $input['signature']) > 3000000) respond(['ok' => false, 'message' => 'ลายมือชื่อมีรูปแบบหรือขนาดไม่ถูกต้อง'], 422);
        }
        $existingId = (int) ($input['id'] ?? 0);
        $existingActivity = null;
        if ($existingId > 0) {
            $stmt = $pdo->prepare("SELECT id, status FROM activities WHERE id=? AND student_id=?");
            $stmt->execute([$existingId, $user['id']]);
            $existingActivity = $stmt->fetch();
            if (!$existingActivity || !in_array($existingActivity['status'], ['draft', 'revision', 'rejected'], true)) respond(['ok' => false, 'message' => 'กิจกรรมนี้ไม่สามารถแก้ไขได้'], 409);
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(hours),0) FROM activities WHERE student_id=? AND status IN ('submitted','resubmitted','approved') AND id<>?");
        $stmt->execute([$user['id'], $existingId]);
        if ($saveStatus !== 'draft' && (float) $stmt->fetchColumn() + $hours > 36) respond(['ok' => false, 'message' => 'ชั่วโมงรวมต้องไม่เกิน 36 ชั่วโมง'], 422);
        $images = array_slice(is_array($input['images'] ?? null) ? $input['images'] : [], 0, 5);
        $maxEvidenceDataLength = (int) ceil((12 * 1024 * 1024) * 4 / 3) + 100;
        foreach ($images as $image) if (!is_string($image) || (!preg_match('#^data:image/(?:png|jpe?g|webp);base64,#i', $image) && !preg_match('#^storage/uploads/[0-9]+/[a-f0-9]+\.(?:png|jpg|webp)$#', $image)) || (str_starts_with($image, 'data:') && strlen($image) > $maxEvidenceDataLength)) respond(['ok' => false, 'message' => 'รูปหลักฐานต้องเป็นไฟล์ PNG, JPG หรือ WEBP และมีขนาดไม่เกิน 12 MB ต่อไฟล์'], 422);
        try {
            $images = array_map(fn(string $image): string => persistDataImage($image, $user['id'], 'evidence'), $images);
            $storedSignature = ($input['signature'] ?? '') !== '' ? persistDataImage((string) $input['signature'], $user['id'], 'signature') : '';
        } catch (InvalidArgumentException $e) {
            respond(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        if ($existingId > 0) {
            $nextStatus = $saveStatus === 'draft' ? 'draft' : (in_array($existingActivity['status'], ['revision', 'rejected'], true) ? 'resubmitted' : 'submitted');
            $stmt = $pdo->prepare("UPDATE activities SET activity_name=?, activity_date=?, start_time=?, end_time=?, hours=?, organization=?, description=?, certifier_name=?, certifier_position=?, certifier_org=?, evidence_images=?, signature=?, status=?, review_note=NULL, reviewed_by=NULL, reviewed_at=NULL WHERE id=? AND student_id=? AND status IN ('draft','revision','rejected')");
            $stmt->execute([trim($input['name']), $input['date'] ?? date('Y-m-d'), $input['startTime'] ?? '00:00', $input['endTime'] ?? '00:00', $hours, trim((string) ($input['organization'] ?? '')), trim((string) ($input['description'] ?? '')), trim((string) ($input['certifierName'] ?? '')), trim((string) ($input['certifierPosition'] ?? '')), trim((string) ($input['certifierOrg'] ?? '')), json_encode($images, JSON_UNESCAPED_SLASHES), $storedSignature, $nextStatus, $existingId, $user['id']]);
            if ($stmt->rowCount() !== 1) respond(['ok' => false, 'message' => 'กิจกรรมนี้ไม่สามารถแก้ไขได้'], 409);
            audit($pdo, $user['id'], 'activity.updated', 'activity', $existingId, ['status' => $nextStatus]);
            respond(['ok' => true, 'message' => $saveStatus === 'draft' ? 'อัปเดตร่างกิจกรรมแล้ว' : 'ส่งกิจกรรมเพื่อตรวจสอบใหม่แล้ว']);
        }
        $stmt = $pdo->prepare('INSERT INTO activities (student_id, activity_name, activity_date, start_time, end_time, hours, organization, description, certifier_name, certifier_position, certifier_org, evidence_images, signature, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$user['id'], trim($input['name']), $input['date'] ?? date('Y-m-d'), $input['startTime'] ?? '00:00', $input['endTime'] ?? '00:00', $hours, trim((string) ($input['organization'] ?? '')), trim((string) ($input['description'] ?? '')), trim((string) ($input['certifierName'] ?? '')), trim((string) ($input['certifierPosition'] ?? '')), trim((string) ($input['certifierOrg'] ?? '')), json_encode($images, JSON_UNESCAPED_SLASHES), $storedSignature, $saveStatus]);
        $activityId = (int) $pdo->lastInsertId();
        audit($pdo, $user['id'], 'activity.created', 'activity', $activityId, ['status' => $saveStatus]);
        respond(['ok' => true, 'message' => $saveStatus === 'draft' ? 'บันทึกร่างกิจกรรมแล้ว' : 'ส่งกิจกรรมเพื่อตรวจสอบแล้ว']);
    }

    if ($action === 'decision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $staff = requireLogin('staff');
        $input = body();
        $activityId = (int) ($input['id'] ?? 0);
        $status = (string) ($input['status'] ?? '');
        $note = trim((string) ($input['note'] ?? ''));
        if (!in_array($status, ['approved', 'rejected'], true) || ($status === 'rejected' && $note === '')) respond(['ok' => false, 'message' => 'กรุณาระบุผลการตรวจและข้อความถึงนักศึกษา'], 422);
        $stmt = $pdo->prepare("SELECT student_id, activity_name, status FROM activities WHERE id=?");
        $stmt->execute([$activityId]);
        $activity = $stmt->fetch();
        if (!$activity) respond(['ok' => false, 'message' => 'ไม่พบกิจกรรม'], 404);
        if (!in_array($activity['status'], ['submitted', 'resubmitted'], true)) respond(['ok' => false, 'message' => 'กิจกรรมนี้ไม่ได้อยู่ในสถานะรอตรวจ'], 409);
        $stmt = $pdo->prepare("UPDATE activities SET status=?, review_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status IN ('submitted','resubmitted')");
        $stmt->execute([$status, $note, $staff['id'], $activityId]);
        $labels = ['approved' => 'ผ่านการตรวจสอบ', 'rejected' => 'ไม่ผ่านการตรวจสอบ'];
        notifyUser($pdo, (int) $activity['student_id'], 'ผลการตรวจสอบกิจกรรม', $activity['activity_name'] . ': ' . $labels[$status] . ($note ? ' - ' . $note : ''), $activityId);
        audit($pdo, $staff['id'], 'activity.reviewed', 'activity', $activityId, ['status' => $status, 'note' => $note]);
        respond(['ok' => true, 'message' => 'บันทึกผลการตรวจสอบแล้ว']);
    }

    if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        requireLogin('staff');
        $input = body();
        $stmt = $pdo->prepare("UPDATE activities SET status='rejected' WHERE id=?");
        $stmt->execute([(int) ($input['id'] ?? 0)]);
        respond(['ok' => true]);
    }

    if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $staff = requireLogin('staff');
        $studentId = (int) (body()['studentId'] ?? 0);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(hours),0) FROM activities WHERE student_id=? AND status='approved'");
        $stmt->execute([$studentId]);
        if ((float) $stmt->fetchColumn() !== 36.0) respond(['ok' => false, 'message' => 'อนุมัติได้เมื่อครบ 36 ชั่วโมงพอดี'], 422);
        $stmt = $pdo->prepare('UPDATE student_profiles SET final_approved=1, final_approved_at=NOW(), final_approved_by=? WHERE user_id=? AND final_approved=0');
        $stmt->execute([$staff['id'], $studentId]);
        if ($stmt->rowCount() !== 1) respond(['ok' => false, 'message' => 'นักศึกษารายนี้ได้รับการอนุมัติครบ 36 ชั่วโมงแล้ว'], 409);
        notifyUser($pdo, $studentId, 'อนุมัติครบ 36 ชั่วโมงแล้ว', 'เจ้าหน้าที่อนุมัติการสะสมกิจกรรมจิตอาสาครบ 36 ชั่วโมงของคุณเรียบร้อยแล้ว');
        audit($pdo, $staff['id'], 'student_hours.final_approved', 'student', $studentId, ['hours' => 36]);
        respond(['ok' => true, 'message' => 'อนุมัติครบ 36 ชั่วโมงแล้ว']);
    }

    if ($action === 'user-status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $admin = requireLogin('admin');
        $input = body();
        $targetId = (int) ($input['id'] ?? 0);
        if ($targetId === $admin['id']) respond(['ok' => false, 'message' => 'ไม่สามารถระงับบัญชีของตนเองได้'], 422);
        $stmt = $pdo->prepare('UPDATE users SET is_active=? WHERE id=?');
        $stmt->execute([!empty($input['isActive']) ? 1 : 0, $targetId]);
        respond(['ok' => true, 'message' => 'อัปเดตสถานะบัญชีแล้ว']);
    }

    if ($action === 'user-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();$admin=requireLogin('admin');$input=body();$targetId=(int)($input['id']??0);$loginId=trim((string)($input['loginId']??''));$firstName=trim((string)($input['firstName']??''));$lastName=trim((string)($input['lastName']??''));$phone=normalizePhone($input['phone']??'');$password=(string)($input['password']??'');$nationalId=preg_replace('/\D+/','',(string)($input['nationalId']??''))?:null;$faculty=trim((string)($input['faculty']??''));$major=trim((string)($input['major']??''));
        $stmt=$pdo->prepare("SELECT role FROM users WHERE id=? AND role IN ('student','staff')");$stmt->execute([$targetId]);$role=$stmt->fetchColumn();if(!$role)respond(['ok'=>false,'message'=>'ไม่พบบัญชีที่ต้องการแก้ไข'],404);if($loginId===''||$firstName===''||$lastName===''||($role==='student'&&$phone===''))respond(['ok'=>false,'message'=>'กรุณากรอกข้อมูลบัญชีให้ครบถ้วน'],422);if($nationalId!==null&&strlen($nationalId)!==13)respond(['ok'=>false,'message'=>'เลขบัตรประชาชนต้องมี 13 หลัก'],422);if($password!==''&&strlen($password)<8)respond(['ok'=>false,'message'=>'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'],422);
        $pdo->beginTransaction();try{$params=[$loginId,$firstName,$lastName,$phone];$sql='UPDATE users SET login_id=?,first_name=?,last_name=?,phone=?';if($password!==''){$sql.=',password_hash=?,must_change_password=0';$params[]=password_hash($password,PASSWORD_DEFAULT);}$sql.=' WHERE id=?';$params[]=$targetId;$pdo->prepare($sql)->execute($params);if($role==='student'){$pdo->prepare('UPDATE student_profiles SET first_name=?,last_name=?,student_code=?,national_id=?,faculty=?,major=? WHERE user_id=?')->execute([$firstName,$lastName,$loginId,$nationalId,$faculty?:'-',$major?:'-',$targetId]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')respond(['ok'=>false,'message'=>'รหัสเข้าสู่ระบบ รหัสนักศึกษา หรือเลขบัตรประชาชนนี้มีอยู่แล้ว'],409);throw $e;}audit($pdo,$admin['id'],'user.updated','user',$targetId,['role'=>$role]);respond(['ok'=>true,'message'=>'บันทึกข้อมูลบัญชีแล้ว']);
    }

    if ($action === 'user-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();$admin=requireLogin('admin');$targetId=(int)(body()['id']??0);if($targetId===$admin['id'])respond(['ok'=>false,'message'=>'ไม่สามารถลบบัญชีของตนเองได้'],422);$stmt=$pdo->prepare("DELETE FROM users WHERE id=? AND role IN ('student','staff')");$stmt->execute([$targetId]);if($stmt->rowCount()!==1)respond(['ok'=>false,'message'=>'ไม่พบบัญชีที่ลบได้'],404);audit($pdo,$admin['id'],'user.deleted','user',$targetId);respond(['ok'=>true,'message'=>'ลบบัญชีแล้ว']);
    }

    if ($action === 'user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        requireLogin('admin');
        $input = body();
        $loginId = trim((string) ($input['loginId'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = (string) ($input['role'] ?? '');
        $firstName = trim((string) ($input['firstName'] ?? ''));
        $lastName = trim((string) ($input['lastName'] ?? ''));
        $phone = normalizePhone($input['phone'] ?? '');
        $nationalId = preg_replace('/\D+/', '', (string) ($input['nationalId'] ?? '')) ?: null;
        if ($nationalId !== null && strlen($nationalId) !== 13) respond(['ok' => false, 'message' => 'เลขบัตรประชาชนต้องมี 13 หลัก'], 422);
        if ($loginId === '' || $firstName === '' || $lastName === '' || !in_array($role, ['student', 'staff'], true) || strlen($password) < 8 || ($role === 'student' && $phone === '')) respond(['ok' => false, 'message' => 'กรุณากรอกชื่อ นามสกุล ข้อมูลบัญชี และรหัสผ่านอย่างน้อย 8 ตัวอักษร'], 422);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO users (login_id, password_hash, role, first_name, last_name, phone, must_change_password) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$loginId, password_hash($password, PASSWORD_DEFAULT), $role, $firstName, $lastName, $phone, 0]);
            $newId = (int) $pdo->lastInsertId();
            if ($role === 'student') {
                $stmt = $pdo->prepare("INSERT INTO student_profiles (user_id, first_name, last_name, student_code, national_id, faculty, major, study_year, semester, academic_year) VALUES (?,?,?,?,?,?,'-', '1','1',YEAR(CURDATE())+543)");
                $stmt->execute([$newId, $firstName, $lastName, $loginId, $nationalId, '-']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') respond(['ok' => false, 'message' => 'รหัสเข้าสู่ระบบนี้มีอยู่แล้ว'], 409);
            throw $e;
        }
        audit($pdo, $admin['id'], 'user.created', 'user', $newId, ['role' => $role]);
        respond(['ok' => true, 'message' => 'สร้างบัญชีแล้ว']);
    }

    if ($action === 'import-students' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $admin = requireLogin('admin');
        $rows = body()['rows'] ?? [];
        if (!is_array($rows)) respond(['ok' => false, 'message' => 'ข้อมูลนำเข้าไม่ถูกต้อง'], 422);
        ['created'=>$created,'skipped'=>$skipped]=importStudentRows($pdo,$admin['id'],$rows);
        respond(['ok' => true, 'message' => "นำเข้าสำเร็จ {$created} รายการ ข้าม {$skipped} รายการ"]);
    }

    if ($action === 'import-xlsx' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();$admin=requireLogin('admin');
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || $_FILES['file']['size'] > 5*1024*1024) respond(['ok'=>false,'message'=>'กรุณาเลือกไฟล์ Excel ขนาดไม่เกิน 5 MB'],422);
        try{$rows=parseStudentWorkbook($_FILES['file']['tmp_name']);['created'=>$created,'skipped'=>$skipped]=importStudentRows($pdo,$admin['id'],$rows);}catch(InvalidArgumentException $e){respond(['ok'=>false,'message'=>$e->getMessage()],422);}
        respond(['ok'=>true,'message'=>"นำเข้าสำเร็จ {$created} รายการ ข้าม {$skipped} รายการ"]);
    }

    respond(['ok' => false, 'message' => 'ไม่พบคำสั่งที่ร้องขอ'], 404);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    $connectionErrors = [1045, 1049, 2002, 2003, 2006];
    $message = in_array($driverCode, $connectionErrors, true)
        ? 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้ โปรดตรวจสอบ MySQL และการตั้งค่า'
        : 'โครงสร้างฐานข้อมูลไม่ตรงกับระบบ โปรดเรียกไฟล์ migration ล่าสุด';
    respond(['ok' => false, 'message' => $message], 500);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(['ok' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ'], 500);
}
