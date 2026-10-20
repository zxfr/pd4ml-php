<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * The PD4ML runtime: version, installation, bridge setup, and value helpers.
 *
 *     Runtime::install();                 // once: the latest PD4ML 4.* release
 *     echo Runtime::version();
 */
final class Runtime
{
    private const FORMATS = ['PDF', 'PDFA', 'PDFUA'];
    private static ?bool $hasEngines = null;

    /** The PD4ML (Java) version. */
    public static function version(): string
    {
        return (string) Java::PD4ML()->getVersion();
    }

    /** Downloads a PD4ML build ({@see Installer::install()}). */
    public static function install(string $version = 'latest', array $options = []): string
    {
        return Installer::install($version, $options);
    }

    /** Sets up the bridge before first use ({@see Bridge::configure()}). */
    public static function configure(array $config): void
    {
        Bridge::configure($config);
    }

    /**
     * Whether the PD4ML build chooses between layout engines (`Constants.LayoutEngine`, from
     * 4.2): earlier builds, the published 4.1.x among them, have one engine only.
     */
    public static function hasLayoutEngines(): bool
    {
        if (self::$hasEngines === null) {
            try {
                Java::LayoutEngine()->v42;
                self::$hasEngines = true;
            } catch (JavaException) {
                self::$hasEngines = false;
            }
        }
        return self::$hasEngines;
    }

    /** A Java `URL` for a URL string or a local path. */
    public static function toUrl(string|JavaObject $location): JavaObject
    {
        if ($location instanceof JavaObject) {
            return $location;
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://|^(file|jar|data):~i', $location)
                && !preg_match('~^[A-Za-z]:[\\\\/]~', $location)) {
            return Java::cls('java.net.URL')->new($location);
        }
        $path = realpath($location) ?: $location;
        return Java::cls('java.io.File')->new($path)->toURI()->toURL();
    }

    /**
     * A `PdfSpec` for one of its keywords (`'pdf/a-3b'`, case-insensitive); a `PdfSpec`, null and
     * the format names `'PDF'`/`'PDFA'`/`'PDFUA'` as they are.
     *
     * @throws \InvalidArgumentException an unknown keyword (`PdfSpec::getInstance()` alone falls
     *         back to PDF 1.7 for it)
     */
    public static function pdfSpec(string|JavaObject|null $spec): string|JavaObject|null
    {
        if (!is_string($spec) || in_array(strtoupper($spec), self::FORMATS, true)) {
            return $spec;
        }
        $PdfSpec = Java::PdfSpec();
        preg_match_all("/'([^']+)'/", (string) $PdfSpec->listKeywords(), $m);
        if (!in_array(strtolower($spec), array_map('strtolower', $m[1]), true)) {
            throw new \InvalidArgumentException("unknown PDF spec '$spec'; one of: " . implode(', ', $m[1]));
        }
        return $PdfSpec->getInstance($spec);
    }

    /** @internal writes `$data` to a path or stream, or returns it for a null target */
    public static function deliver(string $data, mixed $target): ?string
    {
        if ($target === null) {
            return $data;
        }
        if (is_resource($target)) {
            fwrite($target, $data);
        } elseif (file_put_contents((string) $target, $data) === false) {
            throw new \RuntimeException("cannot write $target");
        }
        return null;
    }
}
