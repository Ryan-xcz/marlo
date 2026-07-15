<?php
session_start();
include 'database.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || !isset($data['action'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid request."
    ]);
    exit();
}

$action = $data['action'];


// =====================================================
// REGISTER
// =====================================================
if ($action == "register") {

    $fullname = mysqli_real_escape_string($conn, trim($data['fullname']));
    $email = mysqli_real_escape_string($conn, trim($data['email']));
    $password = trim($data['password']);

    if (empty($fullname) || empty($email) || empty($password)) {
        echo json_encode([
            "status" => "error",
            "message" => "All fields are required."
        ]);
        exit();
    }

    $check = mysqli_query($conn, "SELECT * FROM users WHERE email='$email'");

    if (mysqli_num_rows($check) > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "Email already exists."
        ]);
        exit();
    }

    // HASH PASSWORD
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $insert = mysqli_query($conn, "
        INSERT INTO users(fullname, email, password)
        VALUES('$fullname', '$email', '$hashed_password')
    ");

    if ($insert) {

        $_SESSION['user_name'] = $fullname;
        $_SESSION['email'] = $email;

        echo json_encode([
            "status" => "success",
            "message" => "Registration successful."
        ]);

    } else {

        echo json_encode([
            "status" => "error",
            "message" => "Registration failed."
        ]);
    }

    exit();
}



// =====================================================
// LOGIN
// =====================================================
if ($action == "login") {

    $email = mysqli_real_escape_string($conn, trim($data['email']));
    $password = trim($data['password']);

    $query = mysqli_query($conn, "
        SELECT * FROM users 
        WHERE email='$email'
        LIMIT 1
    ");

    if (mysqli_num_rows($query) == 0) {

        echo json_encode([
            "status" => "error",
            "message" => "Email not found."
        ]);

        exit();
    }

    $user = mysqli_fetch_assoc($query);

    // FIX FOR OLD PLAIN PASSWORDS
    $stored_password = $user['password'];

    $valid = false;

    // HASHED PASSWORD
    if (password_verify($password, $stored_password)) {
        $valid = true;
    }

    // PLAIN TEXT PASSWORD SUPPORT
    if ($password === $stored_password) {
        $valid = true;

        // AUTO CONVERT TO HASH
        $new_hash = password_hash($password, PASSWORD_DEFAULT);

        mysqli_query($conn, "
            UPDATE users
            SET password='$new_hash'
            WHERE id='{$user['id']}'
        ");
    }

    if (!$valid) {

        echo json_encode([
            "status" => "error",
            "message" => "Incorrect password!"
        ]);

        exit();
    }

    $_SESSION['user_name'] = $user['fullname'];
    $_SESSION['email'] = $user['email'];

    echo json_encode([
        "status" => "success",
        "message" => "Login successful."
    ]);

    exit();
}



// =====================================================
// VERIFY EMAIL
// =====================================================
if ($action == "verify_email") {

    $email = mysqli_real_escape_string($conn, trim($data['email']));

    $check = mysqli_query($conn, "
        SELECT * FROM users 
        WHERE email='$email'
        LIMIT 1
    ");

    if (mysqli_num_rows($check) == 0) {

        echo json_encode([
            "status" => "error",
            "message" => "Email not found."
        ]);

        exit();
    }

    $_SESSION['reset_email'] = $email;

    echo json_encode([
        "status" => "success",
        "message" => "Email verified. You may now reset your password."
    ]);

    exit();
}



// =====================================================
// UPDATE PASSWORD
// =====================================================
if ($action == "update_password") {

    if (!isset($_SESSION['reset_email'])) {

        echo json_encode([
            "status" => "error",
            "message" => "Session expired."
        ]);

        exit();
    }

    $new_password = trim($data['new_password']);
    $confirm_password = trim($data['confirm_password']);

    if ($new_password != $confirm_password) {

        echo json_encode([
            "status" => "error",
            "message" => "Passwords do not match."
        ]);

        exit();
    }

    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $email = $_SESSION['reset_email'];

    mysqli_query($conn, "
        UPDATE users
        SET password='$hashed_password'
        WHERE email='$email'
    ");

    unset($_SESSION['reset_email']);

    echo json_encode([
        "status" => "success",
        "message" => "Password updated successfully."
    ]);

    exit();
}



echo json_encode([
    "status" => "error",
    "message" => "Invalid action."
]);
?>
