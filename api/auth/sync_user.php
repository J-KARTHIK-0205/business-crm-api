<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

// Allow POST requests only.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(false, "Only POST requests are allowed.", null, 405);
}

try {

    // Verify Firebase ID token and get authenticated UID.
    $firebaseUid = requireFirebaseUser();

    // Get the user details from Firebase.
    $firebaseUser = $firebaseAuth->getUser($firebaseUid);

    $email = $firebaseUser->email;
    $fullName = $firebaseUser->displayName ?? "";

    if (!$email) {
        sendResponse(false, "Firebase account email is missing.", null, 400);
    }

    // Find the user using Firebase UID.
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );
    $stmt->bind_param("s", $firebaseUid);
    $stmt->execute();

    $result = $stmt->get_result();
    $existingUser = $result->fetch_assoc();
    $stmt->close();

    if ($existingUser) {

        // Update existing user.
        $userId = (int) $existingUser["id"];

        $stmt = $conn->prepare(
            "UPDATE users
             SET full_name = ?, email = ?
             WHERE id = ?"
        );

        $stmt->bind_param("ssi", $fullName, $email, $userId);
        $stmt->execute();
        $stmt->close();

    } else {

        // Check whether this email already exists.
        $stmt = $conn->prepare(
            "SELECT id, firebase_uid FROM users WHERE email = ? LIMIT 1"
        );

        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();
        $existingEmailUser = $result->fetch_assoc();
        $stmt->close();

        if ($existingEmailUser) {

            // Link only an account that has no Firebase UID yet.
            if (!empty($existingEmailUser["firebase_uid"])) {
                sendResponse(
                    false,
                    "This email is already linked to another account.",
                    null,
                    409
                );
            }

            $userId = (int) $existingEmailUser["id"];

            $stmt = $conn->prepare(
                "UPDATE users
                 SET firebase_uid = ?, full_name = ?
                 WHERE id = ? AND firebase_uid IS NULL"
            );

            $stmt->bind_param("ssi", $firebaseUid, $fullName, $userId);
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                sendResponse(false, "Account linking failed.", null, 409);
            }

            $stmt->close();

        } else {

            // Create a new MySQL user record.
            $stmt = $conn->prepare(
                "INSERT INTO users
                 (firebase_uid, full_name, email, password_hash)
                 VALUES (?, ?, ?, NULL)"
            );

            $stmt->bind_param("sss", $firebaseUid, $fullName, $email);
            $stmt->execute();

            $userId = (int) $conn->insert_id;
            $stmt->close();
        }
    }

    sendResponse(
        true,
        "Firebase user synchronized successfully.",
        [
            "user_id" => $userId,
            "firebase_uid" => $firebaseUid,
            "email" => $email,
            "full_name" => $fullName
        ],
        200
    );

} catch (Throwable $e) {

    error_log("User synchronization error: " . $e->getMessage());

    sendResponse(
        false,
        "User synchronization failed. Please try again.",
        null,
        500
    );
}