
<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json");

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
    // Verify Firebase login token.
    $firebaseUid = requireFirebaseUser();

    // Find the corresponding MySQL user.
    $userQuery = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );

    $userQuery->bind_param("s", $firebaseUid);
    $userQuery->execute();

    $userResult = $userQuery->get_result();
    $user = $userResult->fetch_assoc();

    if (!$user) {
        customerResponse(false, "User not found.", null, 404);
    }

    $userId = (int) $user["id"];
    $method = $_SERVER["REQUEST_METHOD"];

    // GET: Fetch customers belonging to this user.
    if ($method === "GET") {
        $query = $conn->prepare(
            "SELECT id, customer_name, email, phone, company,
                    address, notes, organization_id, created_at, updated_at
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

        customerResponse(true, "Customers fetched successfully.", $customers);
    }

    // Read JSON for POST and PUT.
    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        customerResponse(false, "Invalid JSON request.", null, 400);
    }

    // POST: Add a customer.
    if ($method === "POST") {
        $name = trim($input["name"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $company = trim($input["company"] ?? "");

        if ($name === "") {
            customerResponse(false, "Customer name is required.", null, 400);
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

        customerResponse(true, "Customer added successfully.", [
            "id" => $conn->insert_id
        ], 201);
    }

    // PUT: Update a customer.
    if ($method === "PUT") {
        $customerId = (int) ($input["id"] ?? 0);
        $name = trim($input["name"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $company = trim($input["company"] ?? "");

        if ($customerId <= 0 || $name === "") {
            customerResponse(false, "Valid customer ID and name are required.", null, 400);
        }

        $query = $conn->prepare(
            "UPDATE customers
             SET customer_name = ?, email = ?, phone = ?,
                 company = ?, updated_at = CURRENT_TIMESTAMP
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

        if ($query->affected_rows === 0) {
            $check = $conn->prepare(
                "SELECT id FROM customers WHERE id = ? AND user_id = ?"
            );
            $check->bind_param("ii", $customerId, $userId);
            $check->execute();

            if (!$check->get_result()->fetch_assoc()) {
                customerResponse(false, "Customer not found.", null, 404);
            }
        }

        customerResponse(true, "Customer updated successfully.");
    }

    // DELETE: Delete only the logged-in user's customer.
    if ($method === "DELETE") {
        $customerId = (int) ($_GET["id"] ?? 0);

        if ($customerId <= 0) {
            customerResponse(false, "Customer ID is required.", null, 400);
        }

        $query = $conn->prepare(
            "DELETE FROM customers WHERE id = ? AND user_id = ?"
        );

        $query->bind_param("ii", $customerId, $userId);
        $query->execute();

        if ($query->affected_rows === 0) {
            customerResponse(false, "Customer not found.", null, 404);
        }

        customerResponse(true, "Customer deleted successfully.");
    }

    customerResponse(false, "Method not allowed.", null, 405);

} catch (Throwable $error) {
    error_log("Customer API Error: " . $error->getMessage());

    customerResponse(false, "Server error. Please try again.", null, 500);
}
