<?php

namespace App\Archives\Contracts;

use App\Archives\ArchiveValidationException;
use Carbon\CarbonImmutable;

/**
 * Translates a non-Koma gallery ZIP into a Koma Archive v2 manifest.
 *
 * The engine ships one adapter for its own generic gallery manifest. Anything
 * else — a provider's own export shape, a scanner's private format — belongs in
 * a plugin, which registers it through the `archive.gallery_adapters` filter:
 *
 *     Hook::addFilter('archive.gallery_adapters', fn (array $a) => [...$a, new MyAdapter]);
 *
 * Plugin adapters are offered each manifest before the built-in one, so a more
 * specific format can claim a manifest the generic adapter would also accept.
 *
 * An adapter reads only what is already inside the uploaded ZIP. It must not
 * perform network access, and whatever it returns is still put through the
 * strict Koma Archive validator before anything is written.
 */
interface GalleryArchiveAdapter
{
    /**
     * Does this adapter recognise the manifest? Must be cheap and side-effect
     * free — it is called on every candidate adapter in turn.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function supports(array $manifest): bool;

    /**
     * Map each declared page to the ZIP entry it comes from and the archive
     * path it should be staged under.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{source_path:string,archive_path:string}>
     *
     * @throws ArchiveValidationException
     */
    public function pages(array $manifest): array;

    /**
     * Build the Koma Archive v2 manifest, given the media descriptors the
     * engine derived while staging the pages named by {@see self::pages()}.
     *
     * @param  array<string, mixed>  $source
     * @param  list<array{archive_path:string,mime_type:string,sha256:string,bytes:int,width:int,height:int}>  $media
     * @return array<string, mixed>
     *
     * @throws ArchiveValidationException
     */
    public function toKomaManifest(array $source, array $media, ?CarbonImmutable $importedAt = null): array;
}
