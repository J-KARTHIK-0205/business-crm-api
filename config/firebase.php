
<?php

require_once __DIR__ . "/../vendor/autoload.php";

use Kreait\Firebase\Factory;

$serviceAccountPath = getenv('BUSINESS_CRM_FIREBASE_CREDENTIALS')
    ?: "C:/Secure/business-crm/firebase-service-account.json";

if (!file_exists($serviceAccountPath)) {
    throw new RuntimeException(
        "Firebase service account file not found."
    );
}

$factory = (new Factory)
    ->withServiceAccount($serviceAccountPath);

$firebaseAuth = $factory->createAuth();

?>
