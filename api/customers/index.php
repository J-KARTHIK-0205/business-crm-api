
<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json");

// Standard API response
function customerResponse($success, $message, $data = null, $status = 200) {
    http_response_code($status);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);

    exit;
}

try {

    // Handle allowed HTTP methods.
    $method = $_SERVER["REQUEST_METHOD"];

    if (!in_array($method, ["GET", "POST", "PUT", "DELETE"], true)) {
        customerResponse(false, "Method not allowed.", null, 405);
    }

    // Verify Firebase authenticated user.
    $firebaseUid = requireFirebaseUser();

    // Find the corresponding MySQL user.
    $userQuery = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );

    $userQuery->bind_param("s", $firebaseUid);
    $userQuery->execute();

    $userResult = $userQuery->get_result();
    $user = $userResult->fetch_assoc();
    $userQuery->close();

    if (!$user) {
        customerResponse(false, "User not found.", null, 404);
    }

    $userId = (int) $user["id"];

    // =================================================
    // GET: FETCH CUSTOMERS
    // =================================================

    if ($method === "GET") {

        $query = $conn->prepare(
            "SELECT
                id,
                customer_name,
                email,
                phone,
                company,
                address,
                notes,
                organization_id,
                created_at,
                updated_at
             FROM customers
             WHERE user_id = ?
             ORDER BY id DESC"
        );

        $query->bind_param("i", $userId);
        $query->execute();

        $result = $query->get_result();
        $customers = [];

        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }

        $query->close();

        customerResponse(
            true,
            "Customers fetched successfully.",
            $customers
        );
    }

    // =================================================
    // DELETE: DELETE CUSTOMER
    // =================================================
    // IMPORTANT:
    // DELETE receives the customer ID through the URL.
    // It does not require a JSON request body.

    if ($method === "DELETE") {

        $customerId = filter_var(
            $_GET["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$customerId || $customerId <= 0) {
            customerResponse(
                false,
                "A valid customer ID is required.",
                null,
                400
            );
        }

        $query = $conn->prepare(
            "DELETE FROM customers
             WHERE id = ? AND user_id = ?"
        );

        $query->bind_param(
            "ii",
            $customerId,
            $userId
        );

        $query->execute();

        $deletedRows = $query->affected_rows;
        $query->close();

        if ($deletedRows !== 1) {
            customerResponse(
                false,
                "Customer not found or access denied.",
                null,
                404
            );
        }

        customerResponse(
            true,
            "Customer deleted successfully.",
            [
                "customer_id" => $customerId
            ]
        );
    }

    // =================================================
    // READ JSON BODY FOR POST AND PUT ONLY
    // =================================================

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        customerResponse(
            false,
            "Invalid JSON request.",
            null,
            400
        );
    }

    // =================================================
    // POST: ADD CUSTOMER
    // =================================================

    if ($method === "POST") {

        $name = trim($input["name"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $company = trim($input["company"] ?? "");

        if ($name === "") {
            customerResponse(
                false,
                "Customer name is required.",
                null,
                400
            );
        }

        $query = $conn->prepare(
            "INSERT INTO customers
                (user_id, customer_name, email, phone, company)
             VALUES (?, ?, ?, ?, ?)"
        );

        $query->bind_param(
            "issss",
            $userId,
            $name,
            $email,
            $phone,
            $company
        );

        $query->execute();

        $customerId = $conn->insert_id;
        $query->close();

        customerResponse(
            true,
            "Customer added successfully.",
            [
                "id" => $customerId
            ],
            201
        );
    }

    // =================================================
    // PUT: UPDATE CUSTOMER
    // =================================================

    if ($method === "PUT") {

        $customerId = filter_var(
            $input["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $name = trim($input["name"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $company = trim($input["company"] ?? "");

        if (!$customerId || $customerId <= 0 || $name === "") {
            customerResponse(
                false,
                "Valid customer ID and name are required.",
                null,
                400
            );
        }

        $query = $conn->prepare(
            "UPDATE customers
             SET
                customer_name = ?,
                email = ?,
                phone = ?,
                company = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?"
        );

        $query->bind_param(
            "ssssii",
            $name,
            $email,
            $phone,
            $company,
            $customerId,
            $userId
        );

        $query->execute();

        $affectedRows = $query->affected_rows;
        $query->close();

        if ($affectedRows === 0) {

            // Check whether the customer exists.
            $check = $conn->prepare(
                "SELECT id
                 FROM customers
                 WHERE id = ? AND user_id = ?
                 LIMIT 1"
            );

            $check->bind_param(
                "ii",
                $customerId,
                $userId
            );

            $check->execute();

            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$exists) {
                customerResponse(
                    false,
                    "Customer not found or access denied.",
                    null,
                    404
                );
            }
        }

        customerResponse(
            true,
            "Customer updated successfully.",
            [
                "customer_id" => $customerId
            ]
        );
    }

} catch (Throwable $error) {

    error_log(
        "Customer API Error: " . $error->getMessage()
    );

    customerResponse(
        false,
        "Server error. Please try again.",
        null,
        500
    );
}