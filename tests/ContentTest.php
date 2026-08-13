<?php

declare(strict_types=1);

use Cms\Content;

/**
 * Pages and posts. The load-bearing assertions here are that drafts stay out of
 * published() — that is what stops an unfinished edit reaching the live site —
 * and that a slug collision does not overwrite an existing item.
 */

return [

    'save creates an item and find retrieves it' => function (): void {
        $content = new Content(tmpStore());
        $result  = $content->save('page', [
            'title'  => 'About us',
            'body'   => '<p>Hello</p>',
            'status' => 'published',
        ]);

        Assert::true($result['ok'], 'saving a valid page should succeed');
        Assert::same('about-us', $result['slug'], 'the slug is derived from the title');

        $found = $content->find('page', 'about-us');
        Assert::same('About us', $found['title'], 'the item is retrievable by slug');
        Assert::same('<p>Hello</p>', $found['body'], 'and its body is intact');
    },

    'save requires a title' => function (): void {
        $content = new Content(tmpStore());
        $result  = $content->save('page', ['title' => '   ', 'body' => 'x']);

        Assert::false($result['ok'], 'a blank title must be refused');
        Assert::same('Title is required.', $result['error'], 'with a usable message');
    },

    'save sanitises the body' => function (): void {
        // Sanitising on save rather than on render means the stored data is
        // already safe, so a template that forgets to escape cannot resurrect
        // a payload that was submitted months earlier.
        $content = new Content(tmpStore());
        $content->save('post', [
            'title' => 'Test',
            'body'  => '<p>ok</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
        ]);

        $stored = $content->find('post', 'test')['body'];
        Assert::missing('<script', $stored, 'script tags must not reach storage');
        Assert::missing('alert(1)', $stored, 'nor their contents');
        Assert::missing('javascript:', $stored, 'nor a javascript: href');
        Assert::contains('<p>ok</p>', $stored, 'legitimate markup is kept');
    },

    'drafts are excluded from published' => function (): void {
        $content = new Content(tmpStore());
        $content->save('post', ['title' => 'Live one', 'body' => 'x', 'status' => 'published']);
        $content->save('post', ['title' => 'Draft one', 'body' => 'x', 'status' => 'draft']);

        Assert::same(2, count($content->all('post')), 'the admin sees both');
        Assert::same(1, count($content->published('post')), 'the public site sees only the published one');
        Assert::same('Live one', $content->published('post')[0]['title'], 'and it is the right one');
    },

    'an unknown status is treated as a draft' => function (): void {
        // Fail closed: anything that is not exactly "published" stays private.
        $content = new Content(tmpStore());
        $content->save('page', ['title' => 'Odd', 'body' => 'x', 'status' => 'somethingelse']);

        Assert::same('draft', $content->find('page', 'odd')['status'], 'unrecognised status must fall back to draft');
        Assert::same([], $content->published('page'), 'and stay out of the public list');
    },

    'findPublished refuses drafts' => function (): void {
        $content = new Content(tmpStore());
        $content->save('post', ['title' => 'Secret', 'body' => 'x', 'status' => 'draft']);

        Assert::true($content->find('post', 'secret') !== null, 'the admin can load it');
        Assert::same(null, $content->findPublished('post', 'secret'), 'the public route cannot');
    },

    'a colliding slug does not overwrite the existing item' => function (): void {
        $content = new Content(tmpStore());
        $content->save('post', ['title' => 'Same Name', 'body' => 'first']);
        $second = $content->save('post', ['title' => 'Same Name', 'body' => 'second']);

        Assert::same('same-name-2', $second['slug'], 'the second gets a suffixed slug');
        Assert::same(2, count($content->all('post')), 'both items exist');
        Assert::same('first', $content->find('post', 'same-name')['body'], 'the original is untouched');
    },

    'updating an item keeps its slug and created_at' => function (): void {
        $content = new Content(tmpStore());
        $content->save('page', ['title' => 'Original', 'body' => 'v1', 'status' => 'draft']);
        $created = $content->find('page', 'original')['created_at'];

        $content->save('page', ['title' => 'Renamed', 'body' => 'v2', 'status' => 'published'], 'original');

        Assert::same(1, count($content->all('page')), 'updating must not create a duplicate');
        $updated = $content->find('page', 'original');
        Assert::same('Renamed', $updated['title'], 'the title changed');
        Assert::same('v2', $updated['body'], 'the body changed');
        Assert::same($created, $updated['created_at'], 'the creation date did not');
    },

    'explicit slugs are honoured and normalised' => function (): void {
        $content = new Content(tmpStore());
        $result  = $content->save('page', ['title' => 'Anything', 'slug' => 'Custom Slug!', 'body' => 'x']);

        Assert::same('custom-slug', $result['slug'], 'an explicit slug is used, after normalising');
    },

    'delete removes only the named item' => function (): void {
        $content = new Content(tmpStore());
        $content->save('post', ['title' => 'Keep', 'body' => 'x']);
        $content->save('post', ['title' => 'Remove', 'body' => 'x']);

        $content->delete('post', 'remove');

        Assert::same(1, count($content->all('post')), 'one item should remain');
        Assert::same('Keep', $content->all('post')[0]['title'], 'and it should be the other one');
    },

    'all() returns newest first' => function (): void {
        $content = new Content(tmpStore());
        $content->save('post', ['title' => 'First', 'body' => 'x']);
        sleep(1);                                     // created_at has second resolution
        $content->save('post', ['title' => 'Second', 'body' => 'x']);

        Assert::same('Second', $content->all('post')[0]['title'], 'the newest post should sort first');
    },

    'an unknown content type is rejected' => function (): void {
        $content = new Content(tmpStore());

        Assert::throws(
            static fn () => $content->all('widget'),
            'Unknown content type',
            'only page and post are valid types'
        );
    },

    'saving creates a revision of the previous state' => function (): void {
        $content = new Content($store = tmpStore());
        $content->save('page', ['title' => 'V1', 'body' => 'original']);
        $content->save('page', ['title' => 'V1', 'body' => 'replaced'], 'v1');

        $dir   = (new ReflectionProperty(Cms\Store::class, 'dir'))->getValue($store);
        $found = glob($dir . '/revisions/pages-*.php') ?: [];

        Assert::true(count($found) >= 1, 'editing must leave a recoverable copy behind');
    },
];
