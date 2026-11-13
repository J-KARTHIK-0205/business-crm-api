
<?php
require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/response.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");


try {
    // Verify Firebase login and identify the MySQL user.
    $firebaseUid = requireFirebaseUser();

    $userStmt = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );
    $userStmt->bind_param("s", $firebaseUid);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        sendResponse(false, "User account not found.", null, 404);
    }

    $userId = (int)$user["id"];
    $method = $_SERVER["REQUEST_METHOD"];

    // Read JSON request body.
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        $input = [];
    }

    // Validate an optional linked customer.
    $validateCustomer = function ($customerId) use ($conn, $userId) {
        if ($customerId === null || $customerId === "") {
            return null;
        }

        $customerId = filter_var($customerId, FILTER_VALIDATE_INT);
        if ($customerId === false || $customerId < 1) {
            throw new InvalidArgumentException("Invalid customer ID.");
        }

        $stmt = $conn->prepare(
            "SELECT id FROM customers WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param("ii", $customerId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $customer = $result->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            throw new InvalidArgumentException(
                "The selected customer is not available."
            );
        }

        return (int)$customer["id"];
    };

    // GET: List leads belonging to this user.
    if ($method === "GET") {
        $stmt = $conn->prepare(
            "SELECT
                l.id,
                l.customer_id,
                l.organization_id,
                l.lead_name AS name,
                l.email,
                l.phone,
                l.source,
                l.category,
                l.priority,
                l.status,
                l.notes,
                l.created_at,
                l.updated_at,
                c.customer_name AS customerName,
                c.company AS customerCompany
             FROM leads l
             LEFT JOIN customers c ON c.id = l.customer_id
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC"
        );

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $leads = [];
        while ($row = $result->fetch_assoc()) {
            $row["id"] = (int)$row["id"];
            $row["customer_id"] = $row["customer_id"] === null
                ? null : (int)$row["customer_id"];
            $row["organization_id"] = $row["organization_id"] === null
                ? null : (int)$row["organization_id"];
            $leads[] = $row;
        }

        $stmt->close();
        sendResponse(true, "Leads fetched successfully.", $leads);
    }

    // POST: Add a lead.
    if ($method === "POST") {
        $name = trim($input["name"] ?? "");

        if ($name === "") {
            sendResponse(false, "Lead name is required.", null, 422);
        }

        $customerId = $validateCustomer($input["customerId"] ?? null);

        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $source = trim($input["source"] ?? "");
        $category = trim($input["category"] ?? "");
        $priority = trim($input["priority"] ?? "Medium");
        $status = trim($input["status"] ?? "New");
        $notes = trim($input["notes"] ?? "");

        $stmt = $conn->prepare(
            "INSERT INTO leads
                (user_id, customer_id, lead_name, email, phone,
                 source, category, priority, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "iissssssss",
            $userId,
            $customerId,
            $name,
            $email,
            $phone,
            $source,
            $category,
            $priority,
            $status,
            $notes
        );

        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();

        sendResponse(true, "Lead added successfully.", ["id" => $newId], 201);
    }

    // PUT: Update a lead belonging to this user.
    if ($method === "PUT") {
        $id = filter_var($input["id"] ?? null, FILTER_VALIDATE_INT);
        $name = trim($input["name"] ?? "");

        if (!$id || $id < 1 || $name === "") {
            sendResponse(false, "Valid lead ID and name are required.", null, 422);
        }

        $customerId = $validateCustomer($input["customerId"] ?? null);

        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $source = trim($input["source"] ?? "");
        $category = trim($input["category"] ?? "");
        $priority = trim($input["priority"] ?? "Medium");
        $status = trim($input["status"] ?? "New");
        $notes = trim($input["notes"] ?? "");

        $stmt = $conn->prepare(
            "UPDATE leads
             SET customer_id = ?,
                 lead_name = ?,
                 email = ?,
                 phone = ?,
                 source = ?,
                 category = ?,
                 priority = ?,
                 status = ?,
                 notes = ?
             WHERE id = ? AND user_id = ?"
        );

        $stmt->bind_param(
            "issssssssii",
            $customerId,
            $name,
            $email,
            $phone,
            $source,
            $category,
            $priority,
            $status,
            $notes,
            $id,
            $userId
        );

        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        // A zero affected-row count can mean unchanged data.
        $check = $conn->prepare(
            "SELECT id FROM leads WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $check->bind_param("ii", $id, $userId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            sendResponse(false, "Lead not found or access denied.", null, 404);
        }

        sendResponse(true, "Lead updated successfully.");
    }

    // DELETE: Delete only this user's lead.
    if ($method === "DELETE") {
        $id = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

        if (!$id || $id < 1) {
            sendResponse(false, "Valid lead ID is required.", null, 422);
        }

        $stmt = $conn->prepare(
            "DELETE FROM leads WHERE id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted === 0) {
            sendResponse(false, "Lead not found or access denied.", null, 404);
        }

        sendResponse(true, "Lead deleted successfully.");
    }

    header("Allow: GET, POST, PUT, DELETE, OPTIONS");
    sendResponse(false, "Method not allowed.", null, 405);

} catch (InvalidArgumentException $error) {
    sendResponse(false, $error->getMessage(), null, 422);
} catch (Throwable $error) {
    error_log("Leads API error: " . $error->getMessage());
    sendResponse(false, "A server error occurred.", null, 500);
}
