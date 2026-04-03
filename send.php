<?php
/**
 * Kontaktvorm → e-post (Zone: SMTP localhost:25).
 * From: info@plaatvundament.com (SPF/DKIM domeeniga kooskõlas).
 * AJAX (X-Requested-With: XMLHttpRequest): JSON vastus, ilma suunamiseta.
 */
$isAjax = isset($_SERVER["HTTP_X_REQUESTED_WITH"])
    && strtolower((string) $_SERVER["HTTP_X_REQUESTED_WITH"]) === "xmlhttprequest";

/**
 * @return never
 */
function fail_request(bool $ajax, int $code, string $message): void
{
    if ($ajax) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code($code);
        echo json_encode(["ok" => false, "error" => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code($code);
    exit($message);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fail_request($isAjax, 405, "Method Not Allowed");
}

$to = "info@plaatvundament.com";
$fromEnvelope = "info@plaatvundament.com";
$fromDisplayName = "Plaatvundament.com Koduleht";
$fromHeaderValue = '"' . str_replace(["\\", '"'], ["\\\\", '\\"'], $fromDisplayName) . '" <' . $fromEnvelope . ">";

$name = isset($_POST["name"]) ? trim((string) $_POST["name"]) : "";
$email = isset($_POST["email"]) ? trim((string) $_POST["email"]) : "";
$phone = isset($_POST["phone"]) ? trim((string) $_POST["phone"]) : "";
$message = isset($_POST["message"]) ? trim((string) $_POST["message"]) : "";

if ($name === "" || $email === "" || $message === "") {
    fail_request($isAjax, 400, "Palun täida nõutud väljad.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail_request($isAjax, 400, "E-posti aadress ei ole korrektne.");
}

/** @var array{path:string,name:string,mime:string}|null */
$attachment = null;
$maxBytes = 5 * 1024 * 1024;

if (!empty($_FILES["attachment"]["name"]) && (int) $_FILES["attachment"]["error"] !== UPLOAD_ERR_NO_FILE) {
    $err = (int) $_FILES["attachment"]["error"];
    if ($err !== UPLOAD_ERR_OK) {
        fail_request($isAjax, 400, "Faili üleslaadimine ebaõnnestus (kood " . $err . ").");
    }
    if ((int) $_FILES["attachment"]["size"] > $maxBytes) {
        fail_request($isAjax, 400, "Fail on liiga suur (maksimum 5 MB).");
    }
    $tmp = (string) $_FILES["attachment"]["tmp_name"];
    $orig = basename((string) $_FILES["attachment"]["name"]);
    $orig = preg_replace('/[^a-zA-Z0-9._\x{00c4}-\x{00fc}-]/u', "_", $orig);
    if ($orig === "") {
        $orig = "manus.bin";
    }

    $mime = "application/octet-stream";
    if (class_exists("finfo")) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $detected = $fi->file($tmp);
        if (is_string($detected)) {
            $mime = $detected;
        }
    }

    $allowed = [
        "application/pdf",
        "image/jpeg",
        "image/png",
        "image/webp",
        "image/gif",
    ];
    if (!in_array($mime, $allowed, true)) {
        fail_request($isAjax, 400, "Lubatud on ainult PDF ja pildid (JPG, PNG, WEBP, GIF).");
    }

    $attachment = ["path" => $tmp, "name" => $orig, "mime" => $mime];
}

$subject = "Uus päring kodulehelt: " . ($name !== "" ? $name : "koduleht");

$replyTo = $email;

$esc = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, "UTF-8");
};

$bodyHtml = '<!DOCTYPE html><html lang="et"><head><meta charset="UTF-8"></head><body>';
$bodyHtml .= "<p><strong>Nimi:</strong> " . $esc($name) . "</p>";
$bodyHtml .= "<p><strong>E-post:</strong> " . $esc($email) . "</p>";
$bodyHtml .= "<p><strong>Telefon:</strong> " . $esc($phone !== "" ? $phone : "—") . "</p>";
$bodyHtml .= "<p><strong>Sõnum:</strong></p><p>" . nl2br($esc($message)) . "</p>";
if ($attachment !== null) {
    $bodyHtml .= "<p><strong>Manus:</strong> " . $esc($attachment["name"]) . "</p>";
}
$bodyHtml .= "</body></html>";

/**
 * Punkti algusega read SMTP DATA osas.
 */
function smtp_dot_stuff(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if ($line !== "" && $line[0] === ".") {
            $lines[$i] = "." . $line;
        }
    }
    return implode("\r\n", $lines);
}

/**
 * Täielik SMTP DATA sisu (päised + keha).
 */
function build_smtp_data(
    string $to,
    string $fromEnvelope,
    string $fromHeaderValue,
    string $replyTo,
    string $subjectPlain,
    string $bodyHtml,
    ?array $att
): string {
    $subj = "=?UTF-8?B?" . base64_encode($subjectPlain) . "?=";

    if ($att === null) {
        $h = "From: {$fromHeaderValue}\r\n";
        $h .= "To: <{$to}>\r\n";
        $h .= "Reply-To: <{$replyTo}>\r\n";
        $h .= "MIME-Version: 1.0\r\n";
        $h .= "Content-Type: text/html; charset=UTF-8\r\n";
        $h .= "Subject: {$subj}\r\n\r\n";
        return $h . smtp_dot_stuff($bodyHtml);
    }

    $b = "bnd_" . bin2hex(random_bytes(8));
    $h = "From: {$fromHeaderValue}\r\n";
    $h .= "To: <{$to}>\r\n";
    $h .= "Reply-To: <{$replyTo}>\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= "Subject: {$subj}\r\n";
    $h .= "Content-Type: multipart/mixed; boundary=\"{$b}\"\r\n\r\n";

    $part1 = "--{$b}\r\n";
    $part1 .= "Content-Type: text/html; charset=UTF-8\r\n";
    $part1 .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $part1 .= str_replace(["\r\n", "\r"], "\n", $bodyHtml) . "\r\n";

    $raw = (string) file_get_contents($att["path"]);
    $b64 = chunk_split(base64_encode($raw), 76, "\r\n");
    $fn = $att["name"];
    $mt = $att["mime"];

    $part2 = "--{$b}\r\n";
    $part2 .= "Content-Type: {$mt}; name=\"{$fn}\"\r\n";
    $part2 .= "Content-Transfer-Encoding: base64\r\n";
    $part2 .= "Content-Disposition: attachment; filename=\"{$fn}\"\r\n\r\n";
    $part2 .= rtrim($b64) . "\r\n";
    $part2 .= "--{$b}--\r\n";

    return smtp_dot_stuff($h . $part1 . $part2);
}

function send_via_zone_smtp(string $to, string $fromEnvelope, string $dataPayload): bool
{
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

    stream_set_timeout($socket, 60);

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

    $read();

    $write("EHLO plaatvundament.com");
    $read();

    $write("MAIL FROM:<{$fromEnvelope}>");
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

    fwrite($socket, $dataPayload . "\r\n.\r\n");
    $r = $read();
    $ok = substr($r, 0, 3) === "250";

    $write("QUIT");
    fclose($socket);

    if (!$ok) {
        error_log("Zone SMTP: sõnumi lõpp ebaõnnestus: " . trim($r));
    }

    return $ok;
}

$payload = build_smtp_data(
    $to,
    $fromEnvelope,
    $fromHeaderValue,
    $replyTo,
    $subject,
    $bodyHtml,
    $attachment
);
$sent = send_via_zone_smtp($to, $fromEnvelope, $payload);

if (!$sent && $attachment === null) {
    $headers = "From: {$fromHeaderValue}\r\n";
    $headers .= "Reply-To: <{$replyTo}>\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $envelope = "-f" . $fromEnvelope;
    $sent = @mail($to, $subject, $bodyHtml, $headers, $envelope);
}

if ($sent) {
    if ($isAjax) {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["ok" => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header("Location: aitah.html");
    exit;
}

fail_request($isAjax, 500, "Viga saatmisel.");
