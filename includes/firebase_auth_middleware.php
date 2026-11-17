
<?php

require_once __DIR__ . "/../config/firebase.php";
require_once __DIR__ . "/response.php";

use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

function requireFirebaseUser(): string
{
    global $firebaseAuth;

    // Read Authorization header.
    $headers = function_exists("getallheaders")
        ? getallheaders()
        : [];

    $authorization = "";

    foreach ($headers as $key => $value) {
        if (strcasecmp($key, "Authorization") === 0) {
            $authorization = $value;
            break;
        }
    }

    // Fallback for Apache and other server configurations.
    if ($authorization === "") {
        $authorization = $_SERVER["HTTP_AUTHORIZATION"]
            ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"]
            ?? "";
    }

    // Extract Bearer token.
    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            trim($authorization),
            $matches
        )
    ) {
        sendResponse(
            false,
            "Authorization token is missing.",
            null,
            401
        );
    }

    $idToken = trim($matches[1]);

    try {
        // Verify Firebase ID token.
        $verifiedToken = $firebaseAuth->verifyIdToken($idToken);

        // Get Firebase UID.
        $uid = $verifiedToken->claims()->get("sub");

        if (!is_string($uid) || $uid === "") {
            sendResponse(
                false,
                "Invalid user identity.",
                null,
                401
            );
        }

        return $uid;

    } catch (FailedToVerifyToken | InvalidArgumentException $e) {

        error_log("Firebase token verification failed: " . $e->getMessage());

        sendResponse(
            false,
            "Invalid or expired Firebase token.",
            null,
            401
        );

    } catch (Throwable $e) {

        error_log(
            "Firebase authentication error: " . $e->getMessage()
        );

        sendResponse(
            false,
            "Authentication service temporarily unavailable.",
            null,
            500
        );
    }
}
