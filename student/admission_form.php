<?php
// Database configuration
$host = 'localhost';
$dbname = 'ruchi_classes';
$username = 'root';
$password = '';

// Create connection
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $first_name       = trim($_POST['first_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');
    $father_name      = trim($_POST['father_name'] ?? '');
    $mother_name      = trim($_POST['mother_name'] ?? '');
    $dob              = trim($_POST['dob'] ?? '');
    $gender           = trim($_POST['gender'] ?? '');
    $class            = trim($_POST['class'] ?? '');
    $medium           = trim($_POST['medium'] ?? '');
    $board            = trim($_POST['board'] ?? '');
    $school           = trim($_POST['school'] ?? '');
    $previous_marks   = trim($_POST['previous_marks'] ?? '');
    $parent_mobile    = trim($_POST['parent_mobile'] ?? '');
    $personal_mobile  = trim($_POST['personal_mobile'] ?? '');
    $whatsapp         = trim($_POST['whatsapp'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $city             = trim($_POST['city'] ?? '');
    $state            = trim($_POST['state'] ?? '');
    $pincode          = trim($_POST['pincode'] ?? '');
    $address          = trim($_POST['address'] ?? '');
    $reference        = trim($_POST['reference'] ?? '');

    // Validation
    $errors = [];
    if (empty($first_name)) $errors[] = "First name is required.";
    if (empty($last_name)) $errors[] = "Last name is required.";
    if (empty($email)) $errors[] = "Email address is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email address.";
    if (empty($medium)) $errors[] = "Medium is required.";
    if (!preg_match('/^[0-9]{6}$/', $pincode)) $errors[] = "Invalid pincode (must be 6 digits).";

    // ---- PHOTO UPLOAD ----
    $photo_path = '';
    if (!empty($_FILES['photo']['name'])) {
        $upload_dir = "uploads/";
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];

        if (!in_array($ext, $allowed)) {
            $errors[] = "Only JPG, JPEG, PNG, GIF, WEBP allowed.";
        } else {
            $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($_FILES['photo']['name'], PATHINFO_FILENAME));
            $file_name = time() . '_' . $safeName . '.' . $ext;
            $target_file = $upload_dir . $file_name;

            if (getimagesize($_FILES['photo']['tmp_name']) !== false) {
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                    $photo_path = $target_file;
                } else {
                    $errors[] = "Error uploading the file.";
                }
            } else {
                $errors[] = "File is not a valid image.";
            }
        }
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'errors'=>$errors]);
        exit;
    }

    // Determine medium table
    $table = ($medium === 'English') ? 'student_english' : 'student_hindi';

    try {
        // Check duplicate
        $stmt = $conn->prepare("SELECT id FROM $table WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            http_response_code(409);
            echo json_encode([
                'success'=>false,
                'message'=>"This email already exists in $medium students."
            ]);
            exit;
        }

        // Insert query with new fields
        $stmt = $conn->prepare("INSERT INTO $table 
            (first_name,last_name,father_name,mother_name,dob,gender,class,medium,board,school,
             previous_marks,parent_mobile,personal_mobile,whatsapp,email,city,state,pincode,address,
             reference,photo) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $stmt->execute([
            $first_name, $last_name, $father_name, $mother_name, $dob, $gender,
            $class, $medium, $board, $school, $previous_marks, $parent_mobile,
            $personal_mobile, $whatsapp, $email, $city, $state, $pincode,
            $address, $reference, $photo_path
        ]);

        echo json_encode(['success'=>true,'message'=>'Admission submitted successfully!']);

    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
}
