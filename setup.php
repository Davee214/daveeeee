<?php
declare(strict_types=1);
header('Content-Type: application/json');
$sql = file_get_contents(__DIR__ . '/schema.sql');
$host = getenv('LIBFLOW_DB_HOST') ?: '127.0.0.1';
$user = getenv('LIBFLOW_DB_USER') ?: 'root';
$pass = getenv('LIBFLOW_DB_PASS') ?: '';
$port = (int)(getenv('LIBFLOW_DB_PORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_OFF);
$root = @new mysqli($host, $user, $pass, '', $port);
if ($root->connect_errno) {
    http_response_code(503);
    echo json_encode(['success'=>false,'error'=>$root->connect_error]);
    exit;
}
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if (!$root->query($statement)) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$root->error]);
        exit;
    }
}
$root->select_db('libflow');
$columns = $root->query("SHOW COLUMNS FROM loans LIKE 'returned_at'");
if ($columns->num_rows === 0) {
    $root->query("ALTER TABLE loans ADD COLUMN returned_at DATETIME NULL AFTER status");
}
$root->query("CREATE INDEX idx_loans_status_due ON loans(status, due_date)");
$root->query("CREATE INDEX idx_books_title ON books(title)");
$root->query("CREATE INDEX idx_books_author ON books(author)");
$root->query("CREATE INDEX idx_reservations_status ON reservations(status, reserved_at)");
$count = (int)$root->query('SELECT COUNT(*) FROM books')->fetch_row()[0];
if ($count === 0) {
    $books = [
        ['BK-1001','The Midnight Library','Matt Haig','9780525559474','Fiction',3,2,'M'],
        ['BK-1002','Atomic Habits','James Clear','9780735211292','Self Help',4,4,'A'],
        ['BK-1003','The Design of Everyday Things','Don Norman','9780465050659','Technology',2,1,'D'],
        ['BK-1004','Sapiens','Yuval Noah Harari','9780062316097','History',3,3,'S'],
        ['BK-1005','The Alchemist','Paulo Coelho','9780061122415','Fiction',2,2,'T'],
        ['BK-1006','A Brief History of Time','Stephen Hawking','9780553380163','Science',2,0,'B'],
        ['BK-1007','Clean Code','Robert C. Martin','9780132350884','Technology',3,2,'C']
    ];
    $stmt = $root->prepare('INSERT INTO books (id,title,author,isbn,category,copies,available,cover) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($books as $b) { $stmt->bind_param('sssssiss', ...$b); $stmt->execute(); }
    $patrons = [
        ['PT-001','Olivia Martin','olivia.m@student.edu','Student','Active',2.50,'2025-08-12'],
        ['PT-002','Ethan Williams','ethan.w@student.edu','Student','Active',0,'2025-08-12'],
        ['PT-003','Sophia Chen','sophia.chen@university.edu','Faculty','Active',0,'2025-08-12'],
        ['PT-004','Noah Thompson','noah.t@student.edu','Student','Suspended',6.50,'2025-08-12'],
        ['PT-005','Mia Rodriguez','mia.r@student.edu','Student','Active',1,'2025-08-12']
    ];
    $stmt = $root->prepare('INSERT INTO patrons (id,name,email,type,card,fines,joined) VALUES (?,?,?,?,?,?,?)');
    foreach ($patrons as $p) { $stmt->bind_param('sssssds', ...$p); $stmt->execute(); }
    $users = [
        ['USR-ADMIN','', 'Library Administrator', 'admin@gmail.com', 'Admin'],
        ['USR-STUDENT','PT-001', 'Olivia Martin', 'olivia.m@student.edu', 'Student']
    ];
    $passwordHash = password_hash('123456', PASSWORD_DEFAULT);
    $userStmt = $root->prepare('INSERT IGNORE INTO users (id,patron_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)');
    foreach ($users as $user) {
        $userStmt->bind_param('ssssss', $user[0], $user[1], $user[2], $user[3], $passwordHash, $user[4]);
        $userStmt->execute();
    }
    $root->query("INSERT INTO audit_log VALUES ('AU-901','Borrowing','Olivia Martin borrowed The Midnight Library','Librarian',NOW())");
    $loanCount = (int)$root->query('SELECT COUNT(*) FROM loans')->fetch_row()[0];
    if ($loanCount === 0) {
        $loans = [
            ['LN-2401','PT-001','BK-1001',date('Y-m-d', strtotime('-9 days')),date('Y-m-d', strtotime('+5 days'))],
            ['LN-2402','PT-002','BK-1003',date('Y-m-d', strtotime('-22 days')),date('Y-m-d', strtotime('-8 days'))],
            ['LN-2403','PT-003','BK-1004',date('Y-m-d', strtotime('-12 days')),date('Y-m-d', strtotime('+18 days'))],
            ['LN-2404','PT-005','BK-1006',date('Y-m-d', strtotime('-5 days')),date('Y-m-d', strtotime('+9 days'))]
        ];
        $loanStmt = $root->prepare('INSERT INTO loans (id,patron_id,book_id,checked_out,due_date,renewals,status) VALUES (?,?,?,?,?,0,"Active")');
        foreach ($loans as $loan) {
            $loanStmt->bind_param('sssss', $loan[0], $loan[1], $loan[2], $loan[3], $loan[4]);
            $loanStmt->execute();
        }
    }
}
$passwordHash = password_hash('123456', PASSWORD_DEFAULT);
$accounts = [
    ['USR-STUDENT', 'PT-001', 'Olivia Martin', 'olivia.m@student.edu', 'Student']
];
$root->query("UPDATE users SET email='admin@gmail.com' WHERE email='librarian@libflow.edu' AND role='Admin' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE email='admin@gmail.com') AS existing_admin)");
$adminHash = password_hash('Admin123', PASSWORD_DEFAULT);
$adminStmt = $root->prepare('INSERT INTO users (id,patron_id,name,email,password_hash,role) VALUES (?,NULL,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),role=VALUES(role)');
$adminId = 'USR-ADMIN';
$adminName = 'Library Administrator';
$adminEmail = 'admin@gmail.com';
$adminRole = 'Admin';
$adminStmt->bind_param('sssss', $adminId, $adminName, $adminEmail, $adminHash, $adminRole);
$adminStmt->execute();
$root->query("DELETE FROM users WHERE email='librarian@libflow.edu' AND role='Admin'");
$accountStmt = $root->prepare('INSERT IGNORE INTO users (id,patron_id,name,email,password_hash,role) VALUES (?,?,?,?,?,?)');
foreach ($accounts as $account) {
    $accountStmt->bind_param('ssssss', $account[0], $account[1], $account[2], $account[3], $passwordHash, $account[4]);
    $accountStmt->execute();
}
echo json_encode(['success'=>true,'message'=>'LibFlow database is ready']);
