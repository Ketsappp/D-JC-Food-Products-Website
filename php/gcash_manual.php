<?php
/**
 * Manual GCash payment (used whenever PayMongo is not available).
 *
 * The customer scans the store's GCash QR code, pays, then uploads a picture
 * of the receipt together with the GCash account name and reference number.
 * Staff see all three under GCash Orders -> View details and confirm the
 * payment by hand.
 *
 * Files live in uploads/gcash/ (blocked from direct access by .htaccess) and
 * are only handed out through gcash_qr.php and gcash_receipt_image.php.
 */

const GCASH_RECEIPT_MAX_BYTES = 5 * 1024 * 1024;
const GCASH_ALLOWED_IMAGES = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
];

function gcash_upload_dir(): string
{
    $dir = dirname(__DIR__) . "/uploads/gcash";

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/** The store's GCash QR code file, or null when none has been uploaded yet. */
function gcash_qr_file(): ?string
{
    foreach (GCASH_ALLOWED_IMAGES as $extension) {
        $file = gcash_upload_dir() . "/qr." . $extension;

        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

/** Is this upload a real JPG / PNG / WEBP picture? Returns its extension, or null. */
function gcash_image_extension(string $tmpFile): ?string
{
    if (!is_file($tmpFile) || @getimagesize($tmpFile) === false) {
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpFile);

    return GCASH_ALLOWED_IMAGES[$mime] ?? null;
}

/** Send an image file to the browser. */
function gcash_send_image(string $file): void
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: "application/octet-stream";

    header("Content-Type: " . $mime);
    header("Content-Length: " . filesize($file));
    header("Cache-Control: private, max-age=60");
    header("X-Content-Type-Options: nosniff");
    readfile($file);
    exit;
}

function gcash_json(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(array_merge(["success" => $success, "message" => $message], $extra));
    exit;
}

/** Message for a failed $_FILES upload, or null when it went through. */
function gcash_upload_error(?array $file): ?string
{
    if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return "Please choose a picture.";
    }

    switch ($file["error"]) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "That picture is too large. Please use one under 5 MB.";
        default:
            return "The picture could not be uploaded. Please try again.";
    }

    if (($file["size"] ?? 0) > GCASH_RECEIPT_MAX_BYTES) {
        return "That picture is too large. Please use one under 5 MB.";
    }

    if (!is_uploaded_file($file["tmp_name"]) || gcash_image_extension($file["tmp_name"]) === null) {
        return "Please upload a JPG, PNG or WEBP picture.";
    }

    return null;
}
