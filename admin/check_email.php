<?php
session_start();
header('Content-Type: application/json');

// Database connection
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "ruchi_classes";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    echo json_encode(['error' => true, 'message' => 'Database connection failed']);
    exit;
}

function normalizeTeacherEmail($email) {
    $email = mb_strtolower(trim((string) $email));
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);

    if ($email === '') {
        return [
            'valid' => false,
            'email' => '',
            'auto_corrected' => false,
            'correction_message' => '',
            'message' => 'Email is required'
        ];
    }

    if (substr_count($email, '@') !== 1) {
        return [
            'valid' => false,
            'email' => $email,
            'auto_corrected' => false,
            'correction_message' => '',
            'message' => 'Please enter one valid email address'
        ];
    }

    list($localPart, $domainPart) = explode('@', $email, 2);
    $localPart = trim($localPart, ". \t\n\r\0\x0B");
    $domainPart = trim($domainPart);

    $domainCorrections = [
        'gmail' => 'gmail.com',
        'gmai.com' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.cm' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'gmailcom' => 'gmail.com',
        'gmail,com' => 'gmail.com',
        'gmail.om' => 'gmail.com',
        'yaho.com' => 'yahoo.com',
        'yahho.com' => 'yahoo.com',
        'yahoo.con' => 'yahoo.com',
        'hotmal.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'outlok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com'
    ];

    $domainLower = mb_strtolower(preg_replace('/\.{2,}/', '.', str_replace([' ', ','], ['', '.'], $domainPart)));

    if (isset($domainCorrections[$domainLower])) {
        $domainPart = $domainCorrections[$domainLower];
    } elseif (preg_match('/^gmail\.(co|cim|comm|con)$/', $domainLower)) {
        $domainPart = 'gmail.com';
    } else {
        $domainPart = $domainLower;
    }

    $normalizedEmail = $localPart . '@' . $domainPart;
    $autoCorrected = $normalizedEmail !== $email;
    $correctionMessage = $autoCorrected
        ? "Email corrected to <strong>" . htmlspecialchars($normalizedEmail) . "</strong>"
        : '';

    if (
        $localPart === '' ||
        $domainPart === '' ||
        !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)
    ) {
        return [
            'valid' => false,
            'email' => $normalizedEmail,
            'auto_corrected' => $autoCorrected,
            'correction_message' => $correctionMessage,
            'message' => 'Please enter a correct email like teacher@gmail.com'
        ];
    }

    return [
        'valid' => true,
        'email' => $normalizedEmail,
        'auto_corrected' => $autoCorrected,
        'correction_message' => $correctionMessage,
        'message' => 'Email is available'
    ];
}

$email = $_GET['email'] ?? '';
$normalized = normalizeTeacherEmail($email);

if (!$normalized['valid']) {
    echo json_encode([
        'valid' => false,
        'message' => $normalized['message'],
        'corrected' => $normalized['auto_corrected'] ? $normalized['email'] : null,
        'auto_corrected' => $normalized['auto_corrected'],
        'correction_message' => $normalized['correction_message']
    ]);
    exit;
}
$corrected_email = $normalized['email'];

// Check if email already exists
$stmt = $conn->prepare("SELECT id, first_name, last_name, email FROM teachers WHERE LOWER(email) = LOWER(?)");
$stmt->bind_param("s", $corrected_email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $teacher = $result->fetch_assoc();
    $name = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
    if (empty($name)) {
        $name = 'Teacher';
    }
    echo json_encode([
        'exists' => true,
        'message' => 'This email is already registered! Teacher: ' . htmlspecialchars($name) . ' (ID: #' . $teacher['id'] . ')',
        'teacher_id' => $teacher['id'],
        'teacher_name' => $name
    ]);
    exit;
}

// Email is valid and not exists
echo json_encode([
    'valid' => true,
    'message' => $normalized['message'],
    'corrected' => $normalized['auto_corrected'] ? $corrected_email : null,
    'auto_corrected' => $normalized['auto_corrected'],
    'correction_message' => $normalized['correction_message']
]);

$conn->close();
?>
