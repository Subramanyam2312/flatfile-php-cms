<?php

declare(strict_types=1);

use Cms\Media;

/**
 * Media, minus the upload path.
 *
 * accept() calls is_uploaded_file(), which by design returns false for anything
 * that did not arrive through an HTTP upload — so it cannot be exercised from a
 * CLI test without weakening the check it exists to make. That would be testing
 * a hole rather than the code. Real uploads are covered end to end in
 * HttpTest.php, which posts an actual multipart request.
 *
 * What is unit-tested here is the deletion path, where the filename comes from
 * a form POST and is concatenated into a filesystem path.
 */

return [

    'delete rejects path traversal' => function (): void {
        $dir   = tmpDir();
        $media = new Media($dir, tmpStore());

        // A canary outside the upload directory. If validation is bypassed, the
        // unlink lands here.
        $outside = dirname($dir) . '/canary-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($outside, 'do not delete me');

        foreach ([
            '../' . basename($outside),
            '../../etc/passwd',
            './../' . basename($outside),
            '/etc/passwd',
            '..%2Fpasswd',
        ] as $attempt) {
            Assert::false($media->delete($attempt), "'{$attempt}' must be refused");
        }

        Assert::true(is_file($outside), 'the file outside the upload directory must survive');
        @unlink($outside);
    },

    'delete rejects names that are not generated filenames' => function (): void {
        $media = new Media(tmpDir(), tmpStore());

        foreach ([
            'photo.jpg',                        // not 32 hex characters
            'abc.jpg',
            str_repeat('a', 32) . '.php',       // right shape, wrong extension
            str_repeat('a', 32) . '.jpg.php',   // double extension
            str_repeat('g', 32) . '.jpg',       // not hex
            '',
        ] as $attempt) {
            Assert::false($media->delete($attempt), "'{$attempt}' does not match a generated filename");
        }
    },

    'delete accepts a generated filename and removes it' => function (): void {
        $dir   = tmpDir();
        $store = tmpStore();
        $media = new Media($dir, $store);

        $name = str_repeat('a1', 16) . '.jpg';
        file_put_contents($dir . '/' . $name, 'pretend image bytes');
        $store->write('media', ['files' => [['file' => $name], ['file' => str_repeat('b2', 16) . '.png']]]);

        Assert::true($media->delete($name), 'a well-formed filename should be accepted');
        Assert::false(is_file($dir . '/' . $name), 'the file should be gone from disk');
        Assert::same(1, count($media->all()), 'and its record removed from the index');
        Assert::same(str_repeat('b2', 16) . '.png', $media->all()[0]['file'], 'the other record is untouched');
    },

    'all returns an empty list before anything is uploaded' => function (): void {
        Assert::same([], (new Media(tmpDir(), tmpStore()))->all(), 'a fresh library is empty, not an error');
    },

    'accept refuses a file that did not arrive as an upload' => function (): void {
        // The is_uploaded_file() guard is what stops a local-file-inclusion bug
        // from being escalated into "uploading" an arbitrary server file.
        $dir  = tmpDir();
        $file = $dir . '/planted.jpg';
        file_put_contents($file, 'x');

        $result = (new Media($dir, tmpStore()))->accept([
            'name'     => 'planted.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => $file,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 1,
        ]);

        Assert::false($result['ok'], 'a planted path must be refused');
        Assert::same('Not an uploaded file.', $result['error'], 'and say why');
    },

    'accept reports the reason a browser upload failed' => function (): void {
        $media = new Media(tmpDir(), tmpStore());

        $tooBig = $media->accept(['error' => UPLOAD_ERR_INI_SIZE]);
        Assert::false($tooBig['ok'], 'an oversized upload fails');
        Assert::contains('upload_max_filesize', $tooBig['error'], 'and names the setting to change');

        $none = $media->accept(['error' => UPLOAD_ERR_NO_FILE]);
        Assert::same('No file was selected.', $none['error'], 'an empty submission is explained plainly');
    },
];
