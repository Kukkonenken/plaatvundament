<?php
/**
 * Zone virtuaalserverisse üleslaaditav näidis (kui ei kasuta contact.php).
 * Vormi väljad: name, email, message (vt index.html).
 */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method Not Allowed");
}

$to = "info@plaatvundament.com";
$name = isset($_POST["name"]) ? trim((string) $_POST["name"]) : "";
$email = isset($_POST["email"]) ? trim((string) $_POST["email"]) : "";
$message = isset($_POST["message"]) ? trim((string) $_POST["message"]) : "";

$subject = "Uus päring: " . ($name !== "" ? $name : "koduleht");
$body = "Nimi: $name\nE-post: $email\n\nSõnum:\n$message";

$headers = "From: no-reply@plaatvundament.com\r\n";
$headers .= "Reply-To: " . ($email !== "" ? $email : "no-reply@plaatvundament.com") . "\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

if (mail($to, $subject, $body, $headers)) {
    header("Location: https://plaatvundament.com/aitah.html");
    exit;
}

http_response_code(500);
echo "Viga saatmisel.";
