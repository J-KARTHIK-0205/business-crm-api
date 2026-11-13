
<?php

require_once __DIR__ . "/../../includes/cors.php";
require_once __DIR__ . "/../../includes/response.php";
require_once __DIR__ . "/../../includes/firebase_auth_middleware.php";
require_once __DIR__ . "/../../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

// Convert empty optional IDs into NULL.
function taskNullableId($value)
{
    if ($value === null || $value === "") {
        return null;
    }

    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id > 0) ? $id : false;
}

// Convert a supplied date into MySQL DATETIME format.
function taskFormatDate($value)
{
    if ($value === null || $value === "") {
        return null;
    }

    try {
        $date = new DateTime($value);
        return $date->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return false;
    }
}

// Read JSON request body.
$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    $input = [];
}

try {
    // Verify Firebase ID token.
    $firebaseUid = requireFirebaseUser();

    // Find the corresponding MySQL user.
    $userStmt = $conn->prepare(
        "SELECT id FROM users WHERE firebase_uid = ? LIMIT 1"
    );

    if (!$userStmt) {
        throw new Exception("Unable to prepare user query.");
    }

    $userStmt->bind_param("s", $firebaseUid);
    $userStmt->execute();

    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();
    $userStmt->close();

    if (!$user) {
        sendResponse(false, "User not found. Please sync your account first.", null, 404);
    }

    $userId = (int) $user["id"];
    $method = $_SERVER["REQUEST_METHOD"];

    // GET: Fetch tasks belonging to the logged-in user.
    if ($method === "GET") {

        $stmt = $conn->prepare(
            "SELECT
                id,
                customer_id,
                lead_id,
                organization_id,
                task_title,
                description,
                priority,
                status,
                due_date,
                created_at,
                updated_at
             FROM tasks
             WHERE user_id = ?
             ORDER BY
                CASE WHEN due_date IS NULL THEN 1 ELSE 0 END,
                due_date ASC,
                created_at DESC"
        );

        if (!$stmt) {
            throw new Exception("Unable to prepare tasks query.");
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $result = $stmt->get_result();
        $tasks = [];

        while ($row = $result->fetch_assoc()) {
            $row["id"] = (int) $row["id"];

            $row["customer_id"] = $row["customer_id"] !== null
                ? (int) $row["customer_id"] : null;

            $row["lead_id"] = $row["lead_id"] !== null
                ? (int) $row["lead_id"] : null;

            $row["organization_id"] = $row["organization_id"] !== null
                ? (int) $row["organization_id"] : null;

            $tasks[] = $row;
        }

        $stmt->close();

        sendResponse(true, "Tasks fetched successfully.", $tasks);
    }

    // POST: Create a new task.
    elseif ($method === "POST") {

        $taskTitle = trim($input["task_title"] ?? "");
        $description = trim($input["description"] ?? "");
        $priority = trim($input["priority"] ?? "Medium");
        $status = trim($input["status"] ?? "Pending");

        $customerId = taskNullableId($input["customer_id"] ?? null);
        $leadId = taskNullableId($input["lead_id"] ?? null);
        $organizationId = taskNullableId($input["organization_id"] ?? null);
        $dueDate = taskFormatDate($input["due_date"] ?? null);

        if ($taskTitle === "") {
            sendResponse(false, "Task title is required.", null, 400);
        }

        if (strlen($taskTitle) > 150) {
            sendResponse(false, "Task title must not exceed 150 characters.", null, 400);
        }

        if (!in_array($priority, ["Low", "Medium", "High"], true)) {
            sendResponse(false, "Invalid task priority.", null, 400);
        }

        if (!in_array($status, ["Pending", "In Progress", "Completed"], true)) {
            sendResponse(false, "Invalid task status.", null, 400);
        }

        if ($customerId === false || $leadId === false || $organizationId === false) {
            sendResponse(false, "Invalid customer, lead, or organization ID.", null, 400);
        }

        if ($dueDate === false) {
            sendResponse(false, "Invalid due date.", null, 400);
        }

        $stmt = $conn->prepare(
            "INSERT INTO tasks
                (user_id, customer_id, lead_id, organization_id,
                 task_title, description, priority, status, due_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            throw new Exception("Unable to prepare task insert.");
        }

        $stmt->bind_param(
            "iiiisssss",
            $userId,
            $customerId,
            $leadId,
            $organizationId,
            $taskTitle,
            $description,
            $priority,
            $status,
            $dueDate
        );

        $stmt->execute();
        $taskId = $conn->insert_id;
        $stmt->close();

        sendResponse(true, "Task created successfully.", [
            "id" => (int) $taskId
        ], 201);
    }

    // PUT: Update an existing task.
    elseif ($method === "PUT") {

        $taskId = filter_var($input["id"] ?? null, FILTER_VALIDATE_INT);

        if ($taskId === false || $taskId <= 0) {
            sendResponse(false, "Valid task ID is required.", null, 400);
        }

        $taskTitle = trim($input["task_title"] ?? "");
        $description = trim($input["description"] ?? "");
        $priority = trim($input["priority"] ?? "Medium");
        $status = trim($input["status"] ?? "Pending");

        $customerId = taskNullableId($input["customer_id"] ?? null);
        $leadId = taskNullableId($input["lead_id"] ?? null);
        $organizationId = taskNullableId($input["organization_id"] ?? null);
        $dueDate = taskFormatDate($input["due_date"] ?? null);

        if ($taskTitle === "" || strlen($taskTitle) > 150) {
            sendResponse(false, "A valid task title is required (maximum 150 characters).", null, 400);
        }

        if (!in_array($priority, ["Low", "Medium", "High"], true)) {
            sendResponse(false, "Invalid task priority.", null, 400);
        }

        if (!in_array($status, ["Pending", "In Progress", "Completed"], true)) {
            sendResponse(false, "Invalid task status.", null, 400);
        }

        if ($customerId === false || $leadId === false || $organizationId === false) {
            sendResponse(false, "Invalid customer, lead, or organization ID.", null, 400);
        }

        if ($dueDate === false) {
            sendResponse(false, "Invalid due date.", null, 400);
        }

        $stmt = $conn->prepare(
            "UPDATE tasks
             SET customer_id = ?,
                 lead_id = ?,
                 organization_id = ?,
                 task_title = ?,
                 description = ?,
                 priority = ?,
                 status = ?,
                 due_date = ?
             WHERE id = ? AND user_id = ?"
        );

        if (!$stmt) {
            throw new Exception("Unable to prepare task update.");
        }

        $stmt->bind_param(
            "iiisssssii",
            $customerId,
            $leadId,
            $organizationId,
            $taskTitle,
            $description,
            $priority,
            $status,
            $dueDate,
            $taskId,
            $userId
        );

        $stmt->execute();
        $stmt->close();

        // Check existence separately because unchanged data can have 0 affected rows.
        $checkStmt = $conn->prepare(
            "SELECT id FROM tasks WHERE id = ? AND user_id = ?"
        );

        $checkStmt->bind_param("ii", $taskId, $userId);
        $checkStmt->execute();

        $exists = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();

        if (!$exists) {
            sendResponse(false, "Task not found.", null, 404);
        }

        sendResponse(true, "Task updated successfully.", [
            "id" => (int) $taskId
        ]);
    }

    // DELETE: Delete a task belonging to the logged-in user.
    elseif ($method === "DELETE") {

        $taskId = filter_var($_GET["id"] ?? null, FILTER_VALIDATE_INT);

        if ($taskId === false || $taskId <= 0) {
            sendResponse(false, "Valid task ID is required.", null, 400);
        }

        $stmt = $conn->prepare(
            "DELETE FROM tasks WHERE id = ? AND user_id = ?"
        );

        if (!$stmt) {
            throw new Exception("Unable to prepare task deletion.");
        }

        $stmt->bind_param("ii", $taskId, $userId);
        $stmt->execute();

        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted === 0) {
            sendResponse(false, "Task not found.", null, 404);
        }

        sendResponse(true, "Task deleted successfully.", [
            "id" => (int) $taskId
        ]);
    }

    else {
        header("Allow: GET, POST, PUT, DELETE");
        sendResponse(false, "Method not allowed.", null, 405);
    }

} catch (Throwable $e) {

    error_log("Tasks API Error: " . $e->getMessage());

    sendResponse(
        false,
        "A server error occurred while processing tasks.",
        null,
        500
    );
}
?>
