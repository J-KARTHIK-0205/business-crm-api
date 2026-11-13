
<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/response.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

try {
    // Verify Firebase authentication.
    $firebaseUid = requireFirebaseUser();

    // Find the corresponding MySQL user.
    $userStmt = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );

    $userStmt->bind_param("s", $firebaseUid);
    $userStmt->execute();

    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        sendResponse(false, "User account not found.", null, 404);
    }

    $userId = (int)$user["id"];
    $method = $_SERVER["REQUEST_METHOD"];

    $input = json_decode(file_get_contents("php://input"), true);

    if (!is_array($input)) {
        $input = [];
    }

    // GET: Fetch organizations belonging to the logged-in user.
    if ($method === "GET") {

        $stmt = $conn->prepare(
            "SELECT
                id,
                organization_name,
                organization_type,
                industry,
                website,
                email,
                phone,
                address,
                contact_person,
                notes,
                created_at,
                updated_at
             FROM organizations
             WHERE user_id = ?
             ORDER BY created_at DESC"
        );

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $organizations = [];

        while ($row = $result->fetch_assoc()) {
            $row["id"] = (int)$row["id"];
            $organizations[] = $row;
        }

        $stmt->close();

        sendResponse(
            true,
            "Organizations fetched successfully.",
            $organizations
        );
    }

    // POST: Add a new organization.
    if ($method === "POST") {

        $name = trim($input["organization_name"] ?? "");

        if ($name === "") {
            sendResponse(
                false,
                "Organization name is required.",
                null,
                422
            );
        }

        $type = trim($input["organization_type"] ?? "");
        $industry = trim($input["industry"] ?? "");
        $website = trim($input["website"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $address = trim($input["address"] ?? "");
        $contactPerson = trim($input["contact_person"] ?? "");
        $notes = trim($input["notes"] ?? "");

        $stmt = $conn->prepare(
            "INSERT INTO organizations
            (
                user_id,
                organization_name,
                organization_type,
                industry,
                website,
                email,
                phone,
                address,
                contact_person,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "isssssssss",
            $userId,
            $name,
            $type,
            $industry,
            $website,
            $email,
            $phone,
            $address,
            $contactPerson,
            $notes
        );

        $stmt->execute();

        $newId = $conn->insert_id;

        $stmt->close();

        sendResponse(
            true,
            "Organization added successfully.",
            ["id" => $newId],
            201
        );
    }

    // PUT: Update an existing organization.
    if ($method === "PUT") {

        $id = filter_var(
            $input["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $name = trim($input["organization_name"] ?? "");

        if (!$id || $id < 1 || $name === "") {
            sendResponse(
                false,
                "Valid organization ID and name are required.",
                null,
                422
            );
        }

        $type = trim($input["organization_type"] ?? "");
        $industry = trim($input["industry"] ?? "");
        $website = trim($input["website"] ?? "");
        $email = trim($input["email"] ?? "");
        $phone = trim($input["phone"] ?? "");
        $address = trim($input["address"] ?? "");
        $contactPerson = trim($input["contact_person"] ?? "");
        $notes = trim($input["notes"] ?? "");

        $stmt = $conn->prepare(
            "UPDATE organizations
             SET
                organization_name = ?,
                organization_type = ?,
                industry = ?,
                website = ?,
                email = ?,
                phone = ?,
                address = ?,
                contact_person = ?,
                notes = ?
             WHERE id = ? AND user_id = ?"
        );

        $stmt->bind_param(
            "sssssssssii",
            $name,
            $type,
            $industry,
            $website,
            $email,
            $phone,
            $address,
            $contactPerson,
            $notes,
            $id,
            $userId
        );

        $stmt->execute();
        $stmt->close();

        // Confirm that the organization exists and belongs to this user.
        $check = $conn->prepare(
            "SELECT id FROM organizations
             WHERE id = ? AND user_id = ? LIMIT 1"
        );

        $check->bind_param("ii", $id, $userId);
        $check->execute();

        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$exists) {
            sendResponse(
                false,
                "Organization not found or access denied.",
                null,
                404
            );
        }

        sendResponse(true, "Organization updated successfully.");
    }

    // DELETE: Delete an organization.
    if ($method === "DELETE") {

        $id = filter_var(
            $_GET["id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$id || $id < 1) {
            sendResponse(
                false,
                "Valid organization ID is required.",
                null,
                422
            );
        }

        $stmt = $conn->prepare(
            "DELETE FROM organizations
             WHERE id = ? AND user_id = ?"
        );

        $stmt->bind_param("ii", $id, $userId);
        $stmt->execute();

        $deleted = $stmt->affected_rows;

        $stmt->close();

        if ($deleted === 0) {
            sendResponse(
                false,
                "Organization not found or access denied.",
                null,
                404
            );
        }

        sendResponse(true, "Organization deleted successfully.");
    }

    header("Allow: GET, POST, PUT, DELETE, OPTIONS");

    sendResponse(false, "Method not allowed.", null, 405);

} catch (Throwable $error) {

    error_log("Organizations API error: " . $error->getMessage());

    sendResponse(
        false,
        "A server error occurred.",
        null,
        500
    );
}
