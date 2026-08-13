<?php

declare(strict_types=1);

namespace Cms;

/**
 * Image uploads.
 *
 * Three independent things have to fail before an upload can execute:
 * the type check here, the random filename, and the RemoveHandler rules in
 * public/uploads/.htaccess. Any one of them is usually enough. Depending on
 * only one is how upload directories become web shells.
 */
final class Media
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** MIME type => extension. The MIME comes from content, never from the browser. */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private string $uploadDir,
        private Store $store,
    ) {}

    /**
     * Validate and store one uploaded file.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file A $_FILES entry.
     * @return array{ok:true,file:string}|array{ok:false,error:string}
     */
    public function accept(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            return $this->fail($this->describeUploadError((int) $error));
        }

        $tmp = $file['tmp_name'] ?? '';

        // is_uploaded_file rejects a path that did not arrive via HTTP upload,
        // which stops a local-file-inclusion bug from being turned into "upload"
        // of something like /etc/passwd.
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            return $this->fail('Not an uploaded file.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return $this->fail('File is empty.');
        }
        if ($size > self::MAX_BYTES) {
            return $this->fail('File is larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        // Sniff the real type. The browser-supplied type and the filename
        // extension are both attacker-controlled and neither is checked.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        if (!isset(self::ALLOWED[$mime])) {
            return $this->fail('Only JPEG, PNG, GIF and WebP images are allowed.');
        }

        // getimagesize confirms the bytes actually parse as the image they claim
        // to be. A file can carry a valid PNG header and PHP source after it.
        $dimensions = @getimagesize($tmp);
        if ($dimensions === false) {
            return $this->fail('File is not a readable image.');
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $dest = $this->uploadDir . '/' . $name;

        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0755, true) && !is_dir($this->uploadDir)) {
            return $this->fail('Upload directory does not exist and could not be created.');
        }

        if (!move_uploaded_file($tmp, $dest)) {
            return $this->fail('Could not save the file. Check that uploads/ is writable (755).');
        }

        // 0644, never 0755 — an uploaded file has no reason to be executable.
        @chmod($dest, 0644);

        $this->stripMetadata($dest, $mime);

        $this->store->mutate('media', static function (array $media) use ($name, $mime, $dimensions, $size): array {
            $media['files'] ??= [];
            array_unshift($media['files'], [
                'file'       => $name,
                'mime'       => $mime,
                'width'      => $dimensions[0],
                'height'     => $dimensions[1],
                'bytes'      => $size,
                'created_at' => gmdate('c'),
            ]);

            return $media;
        });

        return ['ok' => true, 'file' => $name];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->store->read('media')['files'] ?? [];
    }

    public function delete(string $name): bool
    {
        // The name reaches here from a form POST, so it is validated as a bare
        // filename before being concatenated into a path.
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/', $name)) {
            return false;
        }

        @unlink($this->uploadDir . '/' . $name);

        $this->store->mutate('media', static function (array $media) use ($name): array {
            $media['files'] = array_values(array_filter(
                $media['files'] ?? [],
                static fn (array $f): bool => ($f['file'] ?? '') !== $name
            ));

            return $media;
        });

        return true;
    }

    /**
     * Re-encode to drop EXIF.
     *
     * Phone photos carry GPS coordinates. Publishing one straight from a camera
     * roll publishes where it was taken, which for a home-run business is often
     * a home address. Re-encoding through GD keeps the pixels and loses the tags.
     *
     * Silently skipped when GD is missing — the upload is still safe, just not
     * scrubbed. Check phpinfo() for gd if metadata removal matters to you.
     */
    private function stripMetadata(string $path, string $mime): void
    {
        if (!extension_loaded('gd') || $mime === 'image/gif') {
            return;                                   // GIF: preserve animation
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default      => false,
        };

        if ($image === false) {
            return;
        }

        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        match ($mime) {
            'image/jpeg' => @imagejpeg($image, $path, 88),
            'image/png'  => @imagepng($image, $path, 6),
            'image/webp' => @imagewebp($image, $path, 88),
            default      => null,
        };

        imagedestroy($image);
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'File is larger than the server allows. Raise upload_max_filesize and post_max_size.',
            UPLOAD_ERR_PARTIAL   => 'Upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE   => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR=> 'Server has no temporary directory configured.',
            UPLOAD_ERR_CANT_WRITE=> 'Server could not write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default              => 'Upload failed.',
        };
    }

    /** @return array{ok:false,error:string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
