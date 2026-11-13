
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin()
{
    if (!isset($_SESSION["user_id"])) {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Authentication required"
        ]);

        exit;
    }

    return $_SESSION["user_id"];
}

?>
