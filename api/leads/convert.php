<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/response.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

// Only POST requests are allowed.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendResponse(
        false,
        "Only POST requests are allowed.",
        null,
        405
    );
    exit;
}

try {

    // Get the logged-in Firebase user.
    $firebaseUid = requireFirebaseUser();

    // Find the corresponding MySQL user.
    $userStmt = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );

    $userStmt->bind_param("s", $firebaseUid);
    $userStmt->execute();
    $userStmt->bind_result($userId);

    if (!$userStmt->fetch()) {
        $userStmt->close();

        sendResponse(
            false,
            "User account was not found.",
            null,
            404
        );
        exit;
    }

    $userStmt->close();

    // Read the request body.
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    // Validate lead ID.
    $leadId = isset($input["lead_id"])
        ? filter_var($input["lead_id"], FILTER_VALIDATE_INT)
        : false;

    if (!$leadId || $leadId <= 0) {
        sendResponse(
            false,
            "A valid lead_id is required.",
            null,
            400
        );
        exit;
    }

    // Start transaction.
    $conn->begin_transaction();

    // Find and lock the lead belonging to this user.
    $leadStmt = $conn->prepare(
        "SELECT
            id,
            user_id,
            customer_id,
            organization_id,
            lead_name,
            email,
            phone,
            status
         FROM leads
         WHERE id = ? AND user_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $leadStmt->bind_param("ii", $leadId, $userId);
    $leadStmt->execute();

    $leadStmt->bind_result(
        $foundLeadId,
        $leadUserId,
        $existingCustomerId,
        $organizationId,
        $leadName,
        $email,
        $phone,
        $status
    );

    if (!$leadStmt->fetch()) {
        $leadStmt->close();
        $conn->rollback();

        sendResponse(
            false,
            "Lead not found or access denied.",
            null,
            404
        );
        exit;
    }

    $leadStmt->close();

    // Prevent duplicate conversion.
    if ($existingCustomerId !== null) {
        $conn->rollback();

        sendResponse(
            false,
            "This lead is already linked to a customer.",
            null,
            409
        );
        exit;
    }

    // Only Qualified leads can be converted.
    if (strtolower(trim($status)) !== "qualified") {
        $conn->rollback();

        sendResponse(
            false,
            "Only Qualified leads can be converted to customers.",
            null,
            400
        );
        exit;
    }

    // Validate lead name.
    $customerName = trim($leadName);

    if ($customerName === "") {
        $conn->rollback();

        sendResponse(
            false,
            "Lead name cannot be empty.",
            null,
            400
        );
        exit;
    }

    // Create a customer using the lead details.
    $customerStmt = $conn->prepare(
        "INSERT INTO customers
            (
                user_id,
                organization_id,
                customer_name,
                email,
                phone
            )
         VALUES (?, ?, ?, ?, ?)"
    );

    $customerStmt->bind_param(
        "iisss",
        $userId,
        $organizationId,
        $customerName,
        $email,
        $phone
    );

    $customerStmt->execute();

    $customerId = $conn->insert_id;

    $customerStmt->close();

    // Link the new customer and update the lead status.
    $updateStmt = $conn->prepare(
        "UPDATE leads
         SET customer_id = ?, status = 'Converted'
         WHERE id = ? AND user_id = ?"
    );

    $updateStmt->bind_param(
        "iii",
        $customerId,
        $leadId,
        $userId
    );

    $updateStmt->execute();

    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new RuntimeException(
            "Lead could not be updated."
        );
    }

    $updateStmt->close();

    // Save both database changes.
    $conn->commit();

    sendResponse(
        true,
        "Lead successfully converted to customer.",
        [
            "lead_id" => $leadId,
            "customer_id" => $customerId,
            "customer_name" => $customerName,
            "status" => "Converted"
        ],
        200
    );
    exit;

} catch (Throwable $e) {

    // Undo incomplete database changes.
    if (isset($conn) && $conn->connect_errno === 0) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log(
                "Conversion rollback failed: " .
                $rollbackError->getMessage()
            );
        }
    }

    error_log(
        "Lead conversion error: " . $e->getMessage()
    );

    sendResponse(
        false,
        "Unable to convert lead to customer. Please try again.",
        null,
        500
    );
    exit;
}