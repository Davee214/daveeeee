<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/db.php';
session_start();

$db = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

function json_body(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function rows(mysqli_result $result): array {
    return array_map(fn($row) => array_change_key_case($row, CASE_LOWER), $result->fetch_all(MYSQLI_ASSOC));
}

function exec_stmt(mysqli $db, string $sql, string $types = '', array $params = []): void {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    if ($params) {
        $arguments = [$types];
        foreach ($params as $key => $value) {
            $arguments[] = &$params[$key];
        }
        $stmt->bind_param(...$arguments);
    }
    if (!$stmt->execute()) throw new RuntimeException($stmt->error);
}

function sql_value(mysqli $db, mixed $value): string {
    return "'" . $db->real_escape_string((string)$value) . "'";
}

try {
    if ($method === 'GET' && $action === 'bootstrap') {
        $books = $db->query('SELECT * FROM books ORDER BY title')->fetch_all(MYSQLI_ASSOC);
        $patrons = $db->query('SELECT * FROM patrons ORDER BY name')->fetch_all(MYSQLI_ASSOC);
        $loans = $db->query('SELECT id, patron_id AS patronId, book_id AS bookId, checked_out AS checkedOut, due_date AS due, renewals FROM loans WHERE status = "Active" ORDER BY due_date')->fetch_all(MYSQLI_ASSOC);
        $history = $db->query('SELECT id, patron_id AS patronId, book_id AS bookId, checked_out AS checkedOut, due_date AS due, status, returned_at AS returnedAt FROM loans ORDER BY checked_out DESC, id DESC LIMIT 500')->fetch_all(MYSQLI_ASSOC);
        $reservations = $db->query('SELECT id, patron_id AS patronId, book_id AS bookId, reserved_at AS reservedAt, expires_at AS expiresAt, status FROM reservations ORDER BY reserved_at DESC')->fetch_all(MYSQLI_ASSOC);
        $audit = $db->query('SELECT id, action, detail, user_name AS user, DATE_FORMAT(created_at, "%b %e, %Y %h:%i %p") AS time FROM audit_log ORDER BY created_at DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
        foreach ($patrons as &$p) $p['fines'] = (float)$p['fines'];
        echo json_encode(['success' => true, 'books' => $books, 'patrons' => $patrons, 'loans' => $loans, 'history' => $history, 'reservations' => $reservations, 'audit' => $audit]);
        exit;
    }

    if ($method === 'POST') {
        $body = json_body();
        if ($action === 'login') {
            $email = strtolower(trim((string)($body['email'] ?? '')));
            $password = (string)($body['password'] ?? '');
            $user = $db->query('SELECT id,patron_id,name,email,password_hash,role FROM users WHERE email=' . sql_value($db,$email) . ' LIMIT 1')->fetch_assoc();
            if (!$user || !password_verify($password, $user['password_hash'])) throw new RuntimeException('Invalid email or password');
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>$user['id'],'patronId'=>$user['patron_id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
            $patron = null;
            if ($user['patron_id']) {
                $patron = $db->query("SELECT id,name,email,type,card,fines,joined FROM patrons WHERE id=" . sql_value($db,$user['patron_id']))->fetch_assoc();
                if ($patron) $patron['fines'] = (float)$patron['fines'];
            }
            echo json_encode(['success'=>true,'user'=>['id'=>$user['id'],'patronId'=>$user['patron_id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']],'patron'=>$patron]);
            exit;
        }
        if ($action === 'logout') {
            session_destroy();
            echo json_encode(['success'=>true]);
            exit;
        }
        if ($action === 'signup') {
            $name = trim((string)($body['name'] ?? ''));
            $email = strtolower(trim((string)($body['email'] ?? '')));
            $password = (string)($body['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) throw new RuntimeException('Enter your name, a valid email, and a password with at least 6 characters');
            $exists = $db->query('SELECT id FROM users WHERE email=' . sql_value($db,$email))->fetch_assoc();
            if ($exists) throw new RuntimeException('An account already exists for this email');
            $db->begin_transaction();
            try {
                $patronId = 'PT-' . random_int(100000,999999);
                exec_stmt($db,'INSERT INTO patrons (id,name,email,type,card,fines,joined) VALUES (?, ?, ?, "Student", "Active", 0, CURDATE())','sss',[$patronId,$name,$email]);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                exec_stmt($db,'INSERT INTO users (id,patron_id,name,email,password_hash,role) VALUES (?, ?, ?, ?, ?, "Student")','sssss',['USR-'.$patronId,$patronId,$name,$email,$hash]);
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }
            echo json_encode(['success'=>true,'user'=>['id'=>'USR-'.$patronId,'patronId'=>$patronId,'name'=>$name,'email'=>$email,'role'=>'Student'],'patron'=>['id'=>$patronId,'name'=>$name,'email'=>$email,'type'=>'Student','card'=>'Active','fines'=>0,'joined'=>date('Y-m-d')]]);
            exit;
        }
        if ($action === 'reservation') {
            $patronId = trim((string)$body['patronId']);
            $bookId = trim((string)$body['bookId']);
            $exists = $db->query("SELECT COUNT(*) FROM reservations WHERE patron_id=" . sql_value($db,$patronId) . " AND book_id=" . sql_value($db,$bookId) . " AND status='Pending'")->fetch_row()[0];
            if ((int)$exists > 0) throw new RuntimeException('This title is already reserved');
            $id = 'RS-' . random_int(100000,999999);
            exec_stmt($db,'INSERT INTO reservations (id,patron_id,book_id,status) VALUES (?, ?, ?, "Pending")','sss',[$id,$patronId,$bookId]);
            echo json_encode(['success'=>true,'reservation'=>['id'=>$id,'patronId'=>$patronId,'bookId'=>$bookId,'status'=>'Pending']]);
            exit;
        }
        if ($action === 'cancel-reservation') {
            exec_stmt($db,'UPDATE reservations SET status="Cancelled" WHERE id=? AND status="Pending"','s',[trim((string)$body['reservationId'])]);
            echo json_encode(['success'=>true]);
            exit;
        }
        if ($action === 'book') {
            $id = 'BK-' . random_int(100000, 999999);
            $copies = max(1, (int)$body['copies']);
            exec_stmt($db, 'INSERT INTO books (id,title,author,isbn,category,copies,available,cover) VALUES (?,?,?,?,?,?,?,?)', 'sssssiss',
                [$id, trim($body['title']), trim($body['author']), trim($body['isbn']), trim($body['category']), $copies, $copies, strtoupper(substr(trim($body['title']), 0, 1))]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'patron') {
            $id = 'PT-' . random_int(100000, 999999);
            exec_stmt($db, 'INSERT INTO patrons (id,name,email,type,card,fines,joined) VALUES (?,?,?,?,?,?,CURDATE())', 'sssssd',
                [$id, trim($body['name']), trim($body['email']), trim($body['type']), 'Active', 0]);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }
        if ($action === 'checkout') {
            $bookId = trim($body['bookId']);
            $patronId = trim($body['patronId']);
            $db->begin_transaction();
            $book = $db->query("SELECT copies,available FROM books WHERE id=" . sql_value($db, $bookId) . " FOR UPDATE")->fetch_assoc();
            $patron = $db->query("SELECT card,fines,type FROM patrons WHERE id=" . sql_value($db, $patronId) . " FOR UPDATE")->fetch_assoc();
            $active = (int)$db->query("SELECT COUNT(*) FROM loans WHERE patron_id=" . sql_value($db, $patronId) . ' AND status="Active"')->fetch_row()[0];
            if (!$book || !$patron) throw new RuntimeException('Invalid patron or book');
            if ((int)$book['available'] < 1) throw new RuntimeException('No copies are available');
            if ($patron['card'] !== 'Active') throw new RuntimeException('Patron card is not active');
            if ((float)$patron['fines'] >= 5) throw new RuntimeException('Patron fine balance is $5.00 or higher');
            if ($active >= 5) throw new RuntimeException('Patron has reached the five-book limit');

            $days = $patron['type'] === 'Faculty' ? 30 : 14;
            $id = 'LN-' . random_int(100000, 999999);
            $checked = date('Y-m-d');
            $due = date('Y-m-d', strtotime("+$days days"));
            exec_stmt($db, 'INSERT INTO loans (id,patron_id,book_id,checked_out,due_date,renewals,status) VALUES (?,?,?,?,?,0,"Active")', 'sssss',
                [$id, $patronId, $bookId, $checked, $due]);
            exec_stmt($db, 'UPDATE books SET available=available-1 WHERE id=?', 's', [$bookId]);
            exec_stmt($db, 'INSERT INTO audit_log (id,action,detail,user_name) VALUES (?,?,?,?)', 'ssss',
                ['AU-'.random_int(100000,999999), 'Borrowing', 'Book issued to patron ' . $patronId, 'Librarian']);
            $db->commit();
            echo json_encode(['success'=>true,'loan'=>['id'=>$id,'patronId'=>$patronId,'bookId'=>$bookId,'checkedOut'=>$checked,'due'=>$due,'renewals'=>0]]);
            exit;
        }
        if ($action === 'renew') {
            $loanId = trim($body['loanId']);
            $loan = $db->query("SELECT renewals,due_date FROM loans WHERE id=" . sql_value($db, $loanId) . " AND status='Active'")->fetch_assoc();
            if (!$loan) throw new RuntimeException('Active loan not found');
            if ((int)$loan['renewals'] >= 2) throw new RuntimeException('Renewal limit reached');
            exec_stmt($db, 'UPDATE loans SET renewals=renewals+1,due_date=DATE_ADD(due_date,INTERVAL 14 DAY) WHERE id=?', 's', [$loanId]);
            exec_stmt($db, 'INSERT INTO audit_log (id,action,detail,user_name) VALUES (?,?,?,?)', 'ssss', ['AU-'.random_int(100000,999999),'Renewal','Loan '.$loanId.' renewed','Librarian']);
            echo json_encode(['success'=>true]);
            exit;
        }
        if ($action === 'return') {
            $loanId = trim($body['loanId']);
            $loan = $db->query("SELECT patron_id,book_id,due_date FROM loans WHERE id=" . sql_value($db, $loanId) . " AND status='Active'")->fetch_assoc();
            if (!$loan) throw new RuntimeException('Active loan not found');
            $overdue = max(0, (int)((time() - strtotime($loan['due_date'])) / 86400));
            $fine = round($overdue * 0.50, 2);
            $db->begin_transaction();
            exec_stmt($db, 'UPDATE loans SET status="Returned", returned_at=NOW() WHERE id=?', 's', [$loanId]);
            exec_stmt($db, 'UPDATE books SET available=available+1 WHERE id=?', 's', [$loan['book_id']]);
            if ($fine > 0) exec_stmt($db, 'UPDATE patrons SET fines=fines+?, card=IF(fines+? >= 5,"Suspended",card) WHERE id=?', 'dds', [$fine,$fine,$loan['patron_id']]);
            exec_stmt($db, 'INSERT INTO audit_log (id,action,detail,user_name) VALUES (?,?,?,?)', 'ssss', ['AU-'.random_int(100000,999999),'Return','Loan '.$loanId.' returned','Librarian']);
            $db->commit();
            echo json_encode(['success'=>true,'fine'=>$fine]);
            exit;
        }
        if ($action === 'pay-fine') {
            $patronId = trim($body['patronId']);
            exec_stmt($db, 'UPDATE patrons SET fines=0,card="Active" WHERE id=?', 's', [$patronId]);
            exec_stmt($db, 'INSERT INTO audit_log (id,action,detail,user_name) VALUES (?,?,?,?)', 'ssss', ['AU-'.random_int(100000,999999),'Fine Settlement','Fine balance paid for '.$patronId,'Librarian']);
            echo json_encode(['success'=>true]);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['success'=>false,'error'=>'Unknown API action']);
} catch (Throwable $e) {
    if ($db->errno) $db->rollback();
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
