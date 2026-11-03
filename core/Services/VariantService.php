<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;
use Velk\Models\Media;

/**
 * Gera, no momento do SAVE de conteúdo, as variantes de imagem declaradas
 * nos campos `image`/`gallery` de uma def-list — nunca em render/lazy (é
 * justamente por causa disso que a feature antiga foi removida: gerar sob
 * demanda no request público é lento e imprevisível).
 *
 * Não-fatal em toda a extensão: falta de GD, mídia ausente/não-imagem, ou
 * qualquer erro do GD (ImageVariantService::generate já não lança) nunca
 * derruba um save de conteúdo. Sem retorno, sem escrita no banco — puro
 * efeito colateral em disco, best-effort.
 *
 * Escopo: a árvore INTEIRA de `$defs`, descendo exatamente nos mesmos
 * contêineres que `FieldRenderer::collect()` desce — group/tabs (que
 * achatam os subcampos no mesmo escopo de valores dos irmãos) e
 * repeater/flexible (que abrem um escopo novo por linha). Um campo
 * image/gallery aninhado gera suas variantes no save igual a um de
 * primeiro nível; antes só o topo era varrido e o resto dependia do
 * backfill manual via `bin/velk media:variants`.
 *
 * O custo do save continua previsível porque o walk é puro e barato, e o
 * que é caro (GD) é limitado pelo número de mídias DISTINTAS: collectJobs
 * deduplica por media id, e a busca no banco é uma query só
 * (Media::findMany) em vez de um Media::find por campo.
 */
final class VariantService
{
    /**
     * Percorre `$defs` (lista de defs de campo — cada uma tipo
     * {name, type, variants?, ...}, mesma forma que FieldRenderer::collect
     * recebe) e garante que toda variante declarada em campos image/gallery
     * já exista em disco para a mídia atualmente atribuída em `$values`
     * (saída de FieldRenderer::collect — chaveada pelo `name` do campo).
     *
     * Idempotente: um arquivo de variante já existente NUNCA é
     * regenerado — só o `is_file()` decide, sem comparar mtime/hash da
     * origem (mesma política simples do thumb global no upload).
     */
    public static function ensureForFields(array $defs, array $values): void
    {
        if (!ImageVariantService::gdAvailable()) {
            return;
        }

        $jobs = self::collectJobs($defs, $values);
        if ($jobs === []) {
            return;
        }

        $uploadsPath = App::instance()->config->path('uploads');
        // Uma query só, independente de quantos campos/linhas referenciaram
        // mídia — o walk já deduplicou por id.
        $medias = Media::findMany(array_keys($jobs));

        foreach ($jobs as $mediaId => $variants) {
            $media = $medias[$mediaId] ?? null;
            if ($media === null || !$media->isImage()) continue;

            $srcPath = $uploadsPath . '/' . $media->filename;
            foreach ($variants as $variant) {
                self::ensureOne($uploadsPath, $srcPath, $media->filename, $variant);
            }
        }
    }

    /**
     * Pareia a árvore de defs com a árvore de valores e devolve o trabalho a
     * fazer, agrupado por mídia. Pura — sem banco, sem disco, sem GD.
     *
     * Os contêineres se dividem em dois regimes de escopo, e é por isso que
     * um walk ingênuo (só o topo) errava: `group`/`tabs` NÃO abrem escopo de
     * valores — `FieldRenderer::collect()` achata seus subcampos no mesmo
     * nível dos irmãos, então descemos com o MESMO `$values`. Já `repeater`
     * e `flexible` guardam uma lista de linhas, e cada linha é um escopo
     * novo; no `flexible` o conjunto de defs da linha ainda depende do
     * `_layout` que ela carrega (linha apontando pra layout inexistente é
     * descartada, mesma política self-heal do collect()).
     *
     * A profundidade da recursão vem do site.json (escrito pelo dev), não do
     * POST — os valores só controlam quantas linhas se itera.
     *
     * @param  array<int, mixed> $defs   defs cruas do site.json
     * @param  mixed             $values saída de FieldRenderer::collect neste escopo
     * @return array<int, array<string, mixed>> media id => nome da variante => def bruta
     */
    private static function collectJobs(array $defs, mixed $values): array
    {
        $jobs = [];

        foreach ($defs as $def) {
            if (!is_array($def)) continue;
            $type = (string) ($def['type'] ?? '');

            // --- contêineres achatados: mesmo escopo de valores ---
            if ($type === 'group') {
                self::mergeJobs($jobs, self::collectJobs((array) ($def['fields'] ?? []), $values));
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($def['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    self::mergeJobs($jobs, self::collectJobs((array) ($tab['fields'] ?? []), $values));
                }
                continue;
            }

            $name = (string) ($def['name'] ?? '');
            if ($name === '') continue;
            $value = is_array($values) ? ($values[$name] ?? null) : null;

            // --- contêineres com escopo por linha ---
            if ($type === 'repeater') {
                if (!is_array($value)) continue;
                $subDefs = (array) ($def['fields'] ?? []);
                foreach ($value as $row) {
                    if (!is_array($row)) continue;
                    self::mergeJobs($jobs, self::collectJobs($subDefs, $row));
                }
                continue;
            }
            if ($type === 'flexible') {
                if (!is_array($value)) continue;
                $layouts = (array) ($def['layouts'] ?? []);
                foreach ($value as $row) {
                    if (!is_array($row)) continue;
                    $layoutDef = $layouts[(string) ($row['_layout'] ?? '')] ?? null;
                    if (!is_array($layoutDef)) continue;
                    self::mergeJobs($jobs, self::collectJobs((array) ($layoutDef['fields'] ?? []), $row));
                }
                continue;
            }

            // --- folha: o campo que de fato aponta pra mídia ---
            if ($type !== 'image' && $type !== 'gallery') continue;

            $variants = $def['variants'] ?? null;
            if (!is_array($variants) || $variants === []) continue;

            foreach (self::extractMediaIds($type, $value) as $mediaId) {
                foreach ($variants as $variant) {
                    if (!is_array($variant)) continue;
                    $variantName = $variant['name'] ?? null;
                    if (!is_string($variantName) || $variantName === '') continue;
                    // Chavear por nome colapsa a mesma variante declarada em
                    // campos diferentes pra mesma mídia. Nome igual com specs
                    // diferentes já é rejeitado pelo schema:validate (regra 7),
                    // então a primeira ganhar é indiferente.
                    $jobs[$mediaId][$variantName] = $variant;
                }
            }
        }

        return $jobs;
    }

    /**
     * Funde o resultado de um sub-walk no acumulador, preservando o
     * agrupamento por mídia (array_merge_recursive duplicaria as defs em
     * listas, e array_merge perderia as variantes já coletadas da mídia).
     *
     * @param array<int, array<string, mixed>> $jobs
     * @param array<int, array<string, mixed>> $incoming
     */
    private static function mergeJobs(array &$jobs, array $incoming): void
    {
        foreach ($incoming as $mediaId => $variants) {
            foreach ($variants as $variantName => $variant) {
                $jobs[$mediaId][$variantName] = $variant;
            }
        }
    }

    /**
     * Extrai a lista de media ids do valor coletado de UM campo, de
     * acordo com o tipo. `image` guarda um id escalar único; `gallery`
     * guarda uma lista PLANA de ints (ver FieldRenderer::collectGallery —
     * nunca objetos `{id:...}`). Pura — sem I/O, testável isolada via
     * Reflection (mesmo padrão de outros testes do repo).
     *
     * @return list<int>
     */
    private static function extractMediaIds(string $type, mixed $value): array
    {
        if ($type === 'image') {
            if (!is_numeric($value)) return [];
            $id = (int) $value;
            return $id > 0 ? [$id] : [];
        }

        // gallery
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }
        return $ids;
    }

    /**
     * Gera UMA variante (se ainda não existir em disco) a partir da def
     * bruta declarada em site.json para este campo. `thumb` é sempre
     * pulado aqui — é a caixa GLOBAL gerada no upload (ver
     * MediaService::upload + Config::imageVariants), nunca uma variante
     * por-campo; um campo que declare uma variante chamada "thumb" já é
     * rejeitado pelo `schema:validate` (Task 3), mas nada garante que ele
     * rodou antes deste save acontecer — daí o skip defensivo aqui também.
     *
     * O nome da variante vira nome de DIRETÓRIO em disco
     * (ImageVariantService::variantPath), então é validado contra o mesmo
     * padrão que o schema:validate exige — de novo, defensivo: site.json
     * pode ter sido editado à mão num ambiente sem o CLI.
     */
    private static function ensureOne(string $uploadsPath, string $srcPath, string $filename, mixed $variant): void
    {
        if (!is_array($variant)) return;

        $name = $variant['name'] ?? null;
        if (!is_string($name) || $name === '') return;
        if ($name === 'thumb') return;
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $name) !== 1) return;

        $dest = ImageVariantService::variantPath($uploadsPath, $name, $filename);
        if (is_file($dest)) return; // já gerada — idempotente.

        try {
            $width = self::positiveIntOrNull($variant['width'] ?? null);
            $height = self::positiveIntOrNull($variant['height'] ?? null);
            $fit = $variant['fit'] ?? null;
            $fit = (is_string($fit) && $fit !== '') ? $fit : 'contain';

            ImageVariantService::generate($srcPath, $dest, $width, $height, $fit);
        } catch (\Throwable $e) {
            // generate() já não lança (ver ImageVariantService) — este
            // catch é cinto-e-suspensório, mesmo padrão do thumb global
            // em MediaService::upload.
            error_log('[Velk] field variant generation failed (' . $name . '): ' . $e->getMessage());
        }
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        return (is_numeric($value) && (int) $value > 0) ? (int) $value : null;
    }
}
