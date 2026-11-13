
<?php

require_once __DIR__ . "/includes/response.php";

sendResponse(
    true,
    "Business CRM API is running successfully",
    [
        "version" => "1.0",
        "database" => "Connected",
        "backend" => "PHP"
    ]
);

?>
    