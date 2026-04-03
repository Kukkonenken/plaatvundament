<?php
/**
 * Kontaktvorm → e-post (Zone: SMTP localhost:25).
 * Väljad: name, email, message; valikuline fail: attachment (PDF / pildid, max 5 MB).
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

/** @var array{path:string,name:string,mime:string}|null */
$attachment = null;
$maxBytes = 5 * 1024 * 1024;

if (!empty($_FILES["attachment"]["name"]) && (int) $_FILES["attachment"]["error"] !== UPLOAD_ERR_NO_FILE) {
    $err = (int) $_FILES["attachment"]["error"];
    if ($err !== UPLOAD_ERR_OK) {
        http_response_code(400);
        exit("Faili üleslaadimine ebaõnnestus (kood " . $err . ").");
    }
    if ((int) $_FILES["attachment"]["size"] > $maxBytes) {
        http_response_code(400);
        exit("Fail on liiga suur (maksimum 5 MB).");
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
        http_response_code(400);
        exit("Lubatud on ainult PDF ja pildid (JPG, PNG, WEBP, GIF).");
    }

    $attachment = ["path" => $tmp, "name" => $orig, "mime" => $mime];
}

$subject = "Uus päring: " . ($name !== "" ? $name : "koduleht");
$body = "Nimi: $name\nE-post: $email\nTelefon: $phone\n\nSõnum:\n$message";
if ($attachment !== null) {
    $body .= "\n\nManus: " . $attachment["name"];
}

$replyTo = $email;

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
    string $from,
    string $replyTo,
    string $subjectPlain,
    string $bodyText,
    ?array $att
): string {
    $subj = "=?UTF-8?B?" . base64_encode($subjectPlain) . "?=";

    if ($att === null) {
        $h = "From: <{$from}>\r\nTo: <{$to}>\r\nReply-To: <{$replyTo}>\r\n";
        $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $h .= "Subject: {$subj}\r\n\r\n";
        return $h . smtp_dot_stuff($bodyText);
    }

    $b = "bnd_" . bin2hex(random_bytes(8));
    $h = "From: <{$from}>\r\nTo: <{$to}>\r\nReply-To: <{$replyTo}>\r\n";
    $h .= "MIME-Version: 1.0\r\n";
    $h .= "Subject: {$subj}\r\n";
    $h .= "Content-Type: multipart/mixed; boundary=\"{$b}\"\r\n\r\n";

    $part1 = "--{$b}\r\n";
    $part1 .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $part1 .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $part1 .= str_replace(["\r\n", "\r"], "\n", $bodyText) . "\r\n";

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

function send_via_zone_smtp(string $to, string $from, string $replyTo, string $dataPayload): bool
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

$payload = build_smtp_data($to, $from, $replyTo, $subject, $body, $attachment);
$sent = send_via_zone_smtp($to, $from, $replyTo, $payload);

if (!$sent && $attachment === null) {
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
