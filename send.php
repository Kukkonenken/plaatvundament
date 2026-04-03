<?php
/**
 * Kontaktvorm → e-post (Zone: SMTP localhost:25, vt https://www.zone.ee/help/en/kb/sending-e-mail-trough-web-server-php-mail/)
 * Vormi väljad: name, email, message (index.html → action="send.php").
 */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method Not Allowed");
}

$to = "info@plaatvundament.com";
$from = "no-reply@plaatvundament.com";

$name = isset($_POST["name"]) ? trim((string) $_POST["name"]) : "";
$email = isset($_POST["email"]) ? trim((string) $_POST["email"]) : "";
$phone = isset($_POST["phone"]) ? trim((string) $_POST["phone"]) : "";
$message = isset($_POST["message"]) ? trim((string) $_POST["message"]) : "";

if ($name === "" || $email === "" || $message === "") {
    http_response_code(400);
    exit("Palun täida nõutud väljad.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit("E-posti aadress ei ole korrektne.");
}

$subject = "Uus päring: " . ($name !== "" ? $name : "koduleht");
$body = "Nimi: $name\nE-post: $email\nTelefon: $phone\n\nSõnum:\n$message";

$replyTo = $email;

/**
 * Zone virtuaalserver: saada läbi kohaliku SMTP (localhost:25, ilma TLS-ita).
 * @return bool
 */
function send_via_zone_smtp(
    string $to,
    string $from,
    string $replyTo,
    string $subject,
    string $bodyText
): bool {
    $socket = @stream_socket_client(
        "tcp://127.0.0.1:25",
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        error_log("Zone SMTP: ei saanud ühendust 127.0.0.1:25 — $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, 30);

    $read = function () use ($socket): string {
        $buf = "";
        while (($line = fgets($socket, 8192)) !== false) {
            $buf .= $line;
            if (strlen($line) >= 4 && $line[3] === " ") {
                break;
            }
        }
        return $buf;
    };

    $write = function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $read(); // 220

    $write("EHLO plaatvundament.com");
    $read();

    $write("MAIL FROM:<{$from}>");
    $r = $read();
    if (substr($r, 0, 3) !== "250") {
        error_log("Zone SMTP: MAIL FROM ebaõnnestus: " . trim($r));
        fclose($socket);
        return false;
    }

    $write("RCPT TO:<{$to}>");
    $r = $read();
    if (substr($r, 0, 3) !== "250") {
        error_log("Zone SMTP: RCPT TO ebaõnnestus: " . trim($r));
        fclose($socket);
        return false;
    }

    $write("DATA");
    $r = $read();
    if (substr($r, 0, 3) !== "354") {
        error_log("Zone SMTP: DATA ebaõnnestus: " . trim($r));
        fclose($socket);
        return false;
    }

    $subj = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $headers = "From: <{$from}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Reply-To: <{$replyTo}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Subject: {$subj}\r\n";

    $bodyNorm = str_replace(["\r\n", "\r"], "\n", $bodyText);
    $bodyNorm = preg_replace('/^\./m', '..', $bodyNorm);
    $payload = $headers . "\r\n" . $bodyNorm;
    $payload = str_replace("\n", "\r\n", $payload);

    fwrite($socket, $payload . "\r\n.\r\n");
    $r = $read();
    $ok = substr($r, 0, 3) === "250";

    $write("QUIT");
    fclose($socket);

    if (!$ok) {
        error_log("Zone SMTP: sõnumi lõpp ebaõnnestus: " . trim($r));
    }

    return $ok;
}

$sent = send_via_zone_smtp($to, $from, $replyTo, $subject, $body);

if (!$sent) {
    $headers = "From: {$from}\r\n";
    $headers .= "Reply-To: " . ($replyTo !== "" ? $replyTo : $from) . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $envelope = "-f" . $from;
    $sent = @mail($to, $subject, $body, $headers, $envelope);
}

if ($sent) {
    header("Location: aitah.html");
    exit;
}

http_response_code(500);
echo "Viga saatmisel.";
