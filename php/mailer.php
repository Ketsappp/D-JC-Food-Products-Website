<?php
/**
 * Sends the system's automated emails (confirm email address, reset password).
 *
 * SETUP (one time): copy  mail_config.sample.php  to
 *     C:/xampp/private/mail_config.php      (recommended - outside the web folder)
 * and fill in your SMTP details (e.g. Gmail + App Password).
 *
 * If no config file exists the system does NOT crash: the email is written to
 * php/logs/mail_outbox.log instead so you can still test locally.
 */

require_once __DIR__ . "/config_path.php";
require_once __DIR__ . "/lib/PHPMailer/Exception.php";
require_once __DIR__ . "/lib/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/lib/PHPMailer/SMTP.php";

use PHPMailer\PHPMailer\PHPMailer;

function mail_config(): ?array
{
    static $config = false;

    if ($config === false) {
        $config = null;
        foreach ([private_config_file("mail_config.php")] as $file) {
            if ($file !== null && is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded) && !empty($loaded["username"]) && !empty($loaded["password"])) {
                    $config = $loaded;
                }
                break;
            }
        }
    }

    return $config;
}

/** Wraps content in the branded HTML email layout. */
function mail_layout(string $title, string $bodyHtml, string $buttonText = "", string $buttonUrl = ""): string
{
    $button = "";
    if ($buttonText !== "" && $buttonUrl !== "") {
        $u = htmlspecialchars($buttonUrl, ENT_QUOTES, "UTF-8");
        $button = '<p style="text-align:center;margin:28px 0">'
            . '<a href="' . $u . '" style="background:#3b2418;color:#ffffff;text-decoration:none;'
            . 'padding:14px 34px;border-radius:999px;font-weight:700;display:inline-block">'
            . htmlspecialchars($buttonText, ENT_QUOTES, "UTF-8") . '</a></p>'
            . '<p style="font-size:12px;color:#7a6455;word-break:break-all">If the button does not work, copy this link into your browser:<br>'
            . '<a href="' . $u . '" style="color:#b9772f">' . $u . '</a></p>';
    }

    return '<!DOCTYPE html><html><body style="margin:0;background:#fdf4e9;font-family:Arial,Helvetica,sans-serif;color:#3b2418">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #ecd9c3">'
        . '<tr><td style="background:#3b2418;color:#ffffff;padding:22px 30px;font-size:22px;font-weight:700">D&amp;JC <span style="font-weight:400;font-size:14px;opacity:.8">Food Products</span></td></tr>'
        . '<tr><td style="padding:30px">'
        . '<h1 style="font-size:22px;margin:0 0 14px">' . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . '</h1>'
        . '<div style="font-size:15px;line-height:1.6">' . $bodyHtml . '</div>'
        . $button
        . '</td></tr>'
        . '<tr><td style="padding:18px 30px;background:#fbf6ef;font-size:12px;color:#7a6455">This is an automated message from D&amp;JC Food Products. Please do not reply to this email.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Sends one email. Returns true when it was sent (or logged in local mode).
 */
function send_system_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    $config = mail_config();

    if ($config === null) {
        $dir = __DIR__ . "/logs";
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . "/mail_outbox.log",
            "==== " . date("Y-m-d H:i:s") . " | To: $toEmail | Subject: $subject ====\n$text\n\n",
            FILE_APPEND | LOCK_EX
        );
        return true;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config["host"] ?? "smtp.gmail.com";
        $mail->Port = (int) ($config["port"] ?? 587);
        $mail->SMTPAuth = true;
        $mail->Username = $config["username"];
        $mail->Password = $config["password"];
        $mail->SMTPSecure = ($config["encryption"] ?? "tls") === "ssl"
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = "UTF-8";
        $mail->Timeout = 15;

        $mail->setFrom($config["from_email"] ?? $config["username"], $config["from_name"] ?? "D&JC Food Products");
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;

        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log("send_system_mail failed: " . $e->getMessage());
        return false;
    }
}

/** "Confirm Email Address" email. */
function send_confirm_email(string $toEmail, string $fullName, string $link, int $hours): bool
{
    $first = htmlspecialchars(explode(" ", trim($fullName))[0] ?: "there", ENT_QUOTES, "UTF-8");

    $html = mail_layout(
        "Confirm your email address",
        "<p>Hi $first,</p><p>Thanks for creating a D&amp;JC Food Products account! Please confirm that this is your email address so you can start ordering.</p>"
        . "<p style=\"color:#7a6455\">This link expires in $hours hours. If you didn't sign up, you can safely ignore this email.</p>",
        "Confirm Email Address",
        $link
    );

    $text = "Hi " . strip_tags($first) . ",\n\nThanks for creating a D&JC Food Products account! "
        . "Confirm your email address using the link below:\n\n$link\n\n"
        . "The link expires in $hours hours. If you didn't sign up, ignore this email.\n";

    return send_system_mail($toEmail, $fullName, "Confirm your email address - D&JC Food Products", $html, $text);
}

/** "Forgot Password" email. */
function send_reset_email(string $toEmail, string $fullName, string $link, int $minutes): bool
{
    $first = htmlspecialchars(explode(" ", trim($fullName))[0] ?: "there", ENT_QUOTES, "UTF-8");

    $html = mail_layout(
        "Reset your password",
        "<p>Hi $first,</p><p>We received a request to reset the password for your D&amp;JC Food Products account. Click the button below to choose a new one.</p>"
        . "<p style=\"color:#7a6455\">This link expires in $minutes minutes and can be used once. If you didn't request this, you can safely ignore this email &ndash; your password won't change.</p>",
        "Reset Password",
        $link
    );

    $text = "Hi " . strip_tags($first) . ",\n\nWe received a request to reset your password. "
        . "Open the link below to choose a new one (expires in $minutes minutes, single use):\n\n$link\n\n"
        . "If you didn't request this, ignore this email.\n";

    return send_system_mail($toEmail, $fullName, "Reset your password - D&JC Food Products", $html, $text);
}
