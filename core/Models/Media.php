<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Services\ImageVariantService;

final class Media
{
    public int $id;
    public string $filename;
    public string $originalName;
    public string $mime;
    public int $size;
    public ?int $width = null;
    public ?int $height = null;
    public ?string $alt = null;
    public array $meta = [];
    public string $createdAt;

    /**
     * Per-language alt text overrides. Default-language alt stays in
     * the `alt` column. Empty array when the media has no translations
     * (the common case for sites without i18n).
     *
     * @var array<string, string>
     */
    public array $altTranslations = [];

    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int) $row['id'];
        $m->filename = (string) $row['filename'];
        $m->originalName = (string) $row['original_name'];
        $m->mime = (string) $row['mime'];
        $m->size = (int) $row['size'];
        $m->width = isset($row['width']) ? (int) $row['width'] : null;
        $m->height = isset($row['height']) ? (int) $row['height'] : null;
        $m->alt = $row['alt'] ?? null;
        $m->meta = is_string($row['meta'] ?? null) ? (array) (json_decode($row['meta'], true) ?? []) : [];
        $m->createdAt = (string) $row['created_at'];

        if (is_string($row['alt_translations'] ?? null) && $row['alt_translations'] !== '') {
            $decoded = json_decode($row['alt_translations'], true);
            $m->altTranslations = is_array($decoded) ? $decoded : [];
        }

        return $m;
    }

    /**
     * Alt text in a specific language. Falls back to the default-
     * language `alt` value when no translation exists, then to empty
     * string. Same fallback contract as titleIn / fieldIn on the
     * other translatable models.
     */
    public function altIn(string $language): string
    {
        $value = $this->altTranslations[$language] ?? '';
        if ($value !== '') return $value;
        return $this->alt ?? '';
    }

    /**
     * Replace the alt_translations map on the row. Empty array
     * stores NULL so the column reads clean when no translations
     * exist. Used by MediaController on save.
     *
     * @param array<string, string> $translations
     */
    public function setAltTranslations(array $translations): void
    {
        $clean = array_filter($translations, fn($v) => is_string($v) && $v !== '');
        App::instance()->db->update(
            'media',
            ['alt_translations' => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE)],
            ['id' => $this->id]
        );
        $this->altTranslations = $clean;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM media WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByFilename(string $filename): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM media WHERE filename = ? LIMIT 1', [$filename]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * @param list<int> $ids
     * @return array<int, self> Indexed by id
     */
    public static function findMany(array $ids): array
    {
        if ($ids === []) return [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = App::instance()->db->fetchAll(
            "SELECT * FROM media WHERE id IN ({$placeholders})",
            $ids
        );
        $out = [];
        foreach ($rows as $row) {
            $m = self::fromRow($row);
            $out[$m->id] = $m;
        }
        return $out;
    }

    public static function all(int $limit = 100, int $offset = 0): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM media ORDER BY created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Public URL for the media file.
     *
     * PURE resolution — no generation happens here. When $variant is
     * '' or 'full', returns the original file's URL. Otherwise, checks
     * whether that variant was already generated on disk (at upload or
     * content-save time — see ImageVariantService::generate() via
     * MediaService/VariantService) and returns its URL if so; if the
     * variant file doesn't exist (never generated, or since removed),
     * falls back to the original file's URL. A missing variant is a
     * normal, expected state, not an error — this method never touches
     * GD or writes to disk.
     */
    public function url(string $variant = 'full'): string
    {
        $original = App::instance()->basePath . '/uploads/' . $this->filename;

        if ($variant === '' || $variant === 'full') {
            return $original;
        }

        $uploadsPath = App::instance()->config->path('uploads');
        $variantPath = ImageVariantService::variantPath($uploadsPath, $variant, $this->filename);

        if (is_file($variantPath)) {
            return App::instance()->basePath . '/uploads/' . ImageVariantService::variantRelPath($variant, $this->filename);
        }

        return $original;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Categoria de mídia derivada do mime — sem coluna no banco.
     * `application/ogg` é áudio; `document` cobre PDF + Office (OOXML);
     * como só entram mimes da whitelist, o default 'document' é seguro.
     */
    public static function categoryOf(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/') || $mime === 'application/ogg') return 'audio';
        if ($mime === 'application/zip') return 'archive';
        return 'document';
    }

    public function category(): string
    {
        return self::categoryOf($this->mime);
    }

    /**
     * WHERE de filtragem por categorias (picker com ?accept=). Retorna
     * [sql, params]; ['', []] quando a lista é vazia. Pura — testável.
     *
     * @param list<string> $categories
     * @return array{0: string, 1: array}
     */
    public static function categoriesWhereSql(array $categories): array
    {
        $conds = [];
        $params = [];
        foreach ($categories as $cat) {
            switch ($cat) {
                case 'image':
                    $conds[] = "mime LIKE 'image/%'";
                    break;
                case 'video':
                    $conds[] = "mime LIKE 'video/%'";
                    break;
                case 'audio':
                    $conds[] = "(mime LIKE 'audio/%' OR mime = 'application/ogg')";
                    break;
                case 'archive':
                    $conds[] = "mime = 'application/zip'";
                    break;
                case 'document':
                    $conds[] = 'mime IN (?, ?, ?, ?)';
                    array_push(
                        $params,
                        'application/pdf',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                    );
                    break;
            }
        }
        if ($conds === []) {
            return ['', []];
        }
        return ['(' . implode(' OR ', $conds) . ')', $params];
    }

    /**
     * Listagem filtrada por categorias (mesma ordenação do all()).
     *
     * @param list<string> $categories
     * @return list<self>
     */
    public static function byCategories(array $categories, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = self::categoriesWhereSql($categories);
        if ($where === '') {
            return self::all($limit, $offset);
        }
        $rows = App::instance()->db->fetchAll(
            "SELECT * FROM media WHERE {$where} ORDER BY created_at DESC LIMIT "
                . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** @param list<string> $categories */
    public static function countByCategories(array $categories): int
    {
        [$where, $params] = self::categoriesWhereSql($categories);
        $sql = 'SELECT COUNT(*) FROM media' . ($where === '' ? '' : " WHERE {$where}");
        return (int) App::instance()->db->fetchColumn($sql, $params);
    }

    /** Palavras de uma busca que entram na consulta; o resto é ignorado. */
    public const SEARCH_MAX_WORDS = 5;

    /**
     * WHERE da busca da biblioteca (`q` em /admin/media e no seletor): o nome
     * original (o que a grade mostra), o nome gravado em disco e o alt do
     * idioma padrão. Retorna [sql, params]; ['', []] para termo vazio. Pura —
     * testável.
     *
     * Busca por palavras: cada uma precisa aparecer em algum dos três campos,
     * em qualquer ordem — "camiseta roxa" acha o alt "Camiseta Velk roxa", e
     * acha também um arquivo "camiseta.jpg" de alt "modelo roxa". Até
     * SEARCH_MAX_WORDS palavras, para a consulta não crescer sem limite.
     *
     * `%` e `_` digitados são literais: "foto_1" não pode achar "foto-1". O
     * escape é `!` e não `\` porque o MySQL e o SQLite dos testes leem a barra
     * de jeitos diferentes dentro do literal do ESCAPE. Maiúsculas e acentos
     * seguem a collation das colunas (utf8mb4_unicode_ci no MySQL).
     *
     * @return array{0: string, 1: array}
     */
    public static function searchWhereSql(string $search): array
    {
        $words = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice($words, 0, self::SEARCH_MAX_WORDS);
        if ($words === []) {
            return ['', []];
        }
        $groups = [];
        $params = [];
        foreach ($words as $word) {
            $needle = '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $groups[] = "(original_name LIKE ? ESCAPE '!' OR filename LIKE ? ESCAPE '!' OR alt LIKE ? ESCAPE '!')";
            array_push($params, $needle, $needle, $needle);
        }
        return [implode(' AND ', $groups), $params];
    }

    /**
     * Categorias (seletor com ?accept=) e busca juntas, num WHERE só.
     *
     * @param list<string> $categories
     * @return array{0: string, 1: array}
     */
    private static function filteredWhereSql(array $categories, string $search): array
    {
        $conds = [];
        $params = [];
        foreach ([self::categoriesWhereSql($categories), self::searchWhereSql($search)] as [$sql, $p]) {
            if ($sql !== '') {
                $conds[] = $sql;
                array_push($params, ...$p);
            }
        }
        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }

    /**
     * A listagem da biblioteca: categorias e busca opcionais, mesma ordenação
     * do all().
     *
     * @param list<string> $categories
     * @return list<self>
     */
    public static function listFiltered(array $categories, string $search, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = self::filteredWhereSql($categories, $search);
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM media' . $where . ' ORDER BY created_at DESC LIMIT '
                . (int) $limit . ' OFFSET ' . (int) $offset,
            $params
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** @param list<string> $categories */
    public static function countFiltered(array $categories, string $search): int
    {
        [$where, $params] = self::filteredWhereSql($categories, $search);
        return (int) App::instance()->db->fetchColumn('SELECT COUNT(*) FROM media' . $where, $params);
    }

    public function humanSize(): string
    {
        $bytes = $this->size;
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    public static function create(array $data): self
    {
        $id = App::instance()->db->insert('media', [
            'filename' => $data['filename'],
            'original_name' => $data['original_name'],
            'mime' => $data['mime'],
            'size' => $data['size'],
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'alt' => $data['alt'] ?? null,
            'meta' => json_encode($data['meta'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to create media.');
    }
}
