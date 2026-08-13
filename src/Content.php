<?php

declare(strict_types=1);

namespace Cms;

/**
 * Pages and blog posts.
 *
 * Both are the same shape — slug, title, body, status — so they share one
 * implementation and differ only in which store they live in. Pages are the
 * fixed structure of the site; posts are dated and listed newest first.
 *
 * Every item carries a published body and, while it is being edited, a draft
 * body. The public site only ever reads the published one, so an unfinished
 * edit cannot appear on the live site by accident.
 */
final class Content
{
    public function __construct(private Store $store) {}

    /** @return list<array<string, mixed>> Newest first. */
    public function all(string $type): array
    {
        $items = $this->store->read($this->storeFor($type))['items'] ?? [];

        usort($items, static fn (array $a, array $b): int =>
            strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return $items;
    }

    /** @return list<array<string, mixed>> Published only, for the public site. */
    public function published(string $type): array
    {
        return array_values(array_filter(
            $this->all($type),
            static fn (array $i): bool => ($i['status'] ?? 'draft') === 'published'
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(string $type, string $slug): ?array
    {
        foreach ($this->all($type) as $item) {
            if (($item['slug'] ?? '') === $slug) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null Published only. */
    public function findPublished(string $type, string $slug): ?array
    {
        $item = $this->find($type, $slug);

        return ($item !== null && ($item['status'] ?? '') === 'published') ? $item : null;
    }

    /**
     * Create or update an item.
     *
     * @param array<string, mixed> $input
     * @return array{ok:true,slug:string}|array{ok:false,error:string}
     */
    public function save(string $type, array $input, ?string $existingSlug = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => 'Title is required.'];
        }

        $storeName = $this->storeFor($type);

        // Decide which text to slug *before* slugging. Html::slug('') returns a
        // hash-derived fallback rather than an empty string, so testing the
        // result with ?: never falls through — and every item saved without an
        // explicit slug would share one meaningless slug.
        $requested = trim((string) ($input['slug'] ?? ''));

        $slug = match (true) {
            // An explicit slug always wins.
            $requested !== '' => Html::slug($requested),

            // Editing an existing item with the slug field left blank keeps the
            // current URL. Re-deriving it from the title would mean renaming a
            // published page silently moves it, breaking inbound links and
            // everything already indexed against the old address.
            $existingSlug !== null && $existingSlug !== '' => $existingSlug,

            default => Html::slug($title),
        };
        $body      = Html::sanitise((string) ($input['body'] ?? ''));
        $status    = ($input['status'] ?? 'draft') === 'published' ? 'published' : 'draft';

        // Keep a copy before overwriting anything a human wrote.
        $this->store->revise($storeName);
        $this->store->pruneRevisions($storeName);

        $result = $this->store->mutate($storeName, function (array $data) use (
            $slug, $existingSlug, $title, $body, $status, $input
        ): array {
            $items = $data['items'] ?? [];
            $slug  = $this->uniqueSlug($items, $slug, $existingSlug);
            $now   = gmdate('c');

            $index = null;
            foreach ($items as $i => $item) {
                if (($item['slug'] ?? '') === ($existingSlug ?? $slug)) {
                    $index = $i;
                    break;
                }
            }

            $record = [
                'slug'        => $slug,
                'title'       => $title,
                'body'        => $body,
                'status'      => $status,
                'meta_title'  => trim((string) ($input['meta_title'] ?? '')),
                'meta_desc'   => trim((string) ($input['meta_desc'] ?? '')),
                'image'       => trim((string) ($input['image'] ?? '')),
                'created_at'  => $index !== null ? ($items[$index]['created_at'] ?? $now) : $now,
                'updated_at'  => $now,
            ];

            if ($index !== null) {
                $items[$index] = $record;
            } else {
                $items[] = $record;
            }

            $data['items'] = array_values($items);
            $data['_slug'] = $slug;                 // handed back to the caller

            return $data;
        });

        return ['ok' => true, 'slug' => (string) ($result['_slug'] ?? $slug)];
    }

    public function delete(string $type, string $slug): void
    {
        $storeName = $this->storeFor($type);
        $this->store->revise($storeName);

        $this->store->mutate($storeName, static function (array $data) use ($slug): array {
            $data['items'] = array_values(array_filter(
                $data['items'] ?? [],
                static fn (array $i): bool => ($i['slug'] ?? '') !== $slug
            ));

            return $data;
        });
    }

    /**
     * Append two slugs that clash with a counter, so saving a second post
     * called "About" does not silently overwrite the first.
     *
     * @param list<array<string, mixed>> $items
     */
    private function uniqueSlug(array $items, string $slug, ?string $ignore): string
    {
        $taken = [];
        foreach ($items as $item) {
            $existing = (string) ($item['slug'] ?? '');
            if ($existing !== '' && $existing !== $ignore) {
                $taken[$existing] = true;
            }
        }

        if (!isset($taken[$slug])) {
            return $slug;
        }

        $n = 2;
        while (isset($taken["{$slug}-{$n}"])) {
            $n++;
        }

        return "{$slug}-{$n}";
    }

    private function storeFor(string $type): string
    {
        return match ($type) {
            'page' => 'pages',
            'post' => 'posts',
            default => throw new \InvalidArgumentException("Unknown content type: {$type}"),
        };
    }
}
