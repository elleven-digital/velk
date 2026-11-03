<?php

declare(strict_types=1);

use Velk\Services\MediaService;

/** Grava bytes num arquivo temporário e devolve o caminho. */
if (!function_exists('tmp_media_bytes')) {
    function tmp_media_bytes(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'velk-mime-');
        file_put_contents($path, $bytes);
        return $path;
    }
}

test('sniffMagic: brands ftyp de vídeo/áudio (e heic mantido)', function () {
    $cases = [
        'isom' => 'video/mp4',
        'mp42' => 'video/mp4',
        'avc1' => 'video/mp4',
        'qt  ' => 'video/quicktime',
        'M4A ' => 'audio/mp4',
        'heic' => 'image/heic',
    ];
    foreach ($cases as $brand => $expected) {
        $p = tmp_media_bytes("\x00\x00\x00\x20ftyp" . $brand . str_repeat("\x00", 20));
        check_same($expected, MediaService::sniffMagic($p));
        unlink($p);
    }
});

test('sniffMagic: brand ftyp desconhecido → null (finfo decide)', function () {
    $p = tmp_media_bytes("\x00\x00\x00\x20ftypzzzz" . str_repeat("\x00", 20));
    check_same(null, MediaService::sniffMagic($p));
    unlink($p);
});

test('sniffMagic: EBML com DocType webm → video/webm; sem → null', function () {
    $webm = tmp_media_bytes("\x1A\x45\xDF\xA3" . "\x42\x82\x84" . 'webm' . str_repeat("\x00", 24));
    check_same('video/webm', MediaService::sniffMagic($webm));
    unlink($webm);

    $mkv = tmp_media_bytes("\x1A\x45\xDF\xA3" . "\x42\x82\x88" . 'matroska' . str_repeat("\x00", 20));
    check_same(null, MediaService::sniffMagic($mkv));
    unlink($mkv);
});

test('sniffMagic: mp3 por ID3 e por frame-sync', function () {
    $id3 = tmp_media_bytes('ID3' . str_repeat("\x00", 30));
    check_same('audio/mpeg', MediaService::sniffMagic($id3));
    unlink($id3);

    $sync = tmp_media_bytes("\xFF\xFB" . str_repeat("\x00", 30));
    check_same('audio/mpeg', MediaService::sniffMagic($sync));
    unlink($sync);
});

test('sniffMagic: OggS → audio/ogg', function () {
    $p = tmp_media_bytes('OggS' . str_repeat("\x00", 30));
    check_same('audio/ogg', MediaService::sniffMagic($p));
    unlink($p);
});

test('sniffMagic: RIFF WAVE → audio/wav; RIFF WEBP continua image/webp', function () {
    $wav = tmp_media_bytes('RIFF' . "\x24\x08\x00\x00" . 'WAVE' . str_repeat("\x00", 20));
    check_same('audio/wav', MediaService::sniffMagic($wav));
    unlink($wav);

    $webp = tmp_media_bytes('RIFF' . "\x24\x08\x00\x00" . 'WEBP' . str_repeat("\x00", 20));
    check_same('image/webp', MediaService::sniffMagic($webp));
    unlink($webp);
});

test('promoteZipMime: extensão OOXML promove; resto fica zip', function () {
    $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    check_same($docx, MediaService::promoteZipMime('application/zip', 'Relatório Final.DOCX'));
    check_same(
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        MediaService::promoteZipMime('application/zip', 'planilha.xlsx')
    );
    check_same('application/zip', MediaService::promoteZipMime('application/zip', 'pacote.zip'));
    check_same('application/zip', MediaService::promoteZipMime('application/zip', 'sem-extensao'));
    check_same('video/mp4', MediaService::promoteZipMime('video/mp4', 'x.docx'));
});
