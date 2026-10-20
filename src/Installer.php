<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * Downloads a PD4ML build from the PD4ML Maven repository and makes it the default one.
 *
 *     php bin/pd4ml install                  # the most recent 4.* release
 *     php bin/pd4ml install --snapshots      # the most recent 4.* build, snapshots included
 *     php bin/pd4ml install --version=4.0.23fx2
 *     php bin/pd4ml list                     # the available versions
 *
 * or `Installer::install()` / `Pd4ml::install()` from PHP. The jar is stored in the user's cache
 * directory (`PD4ML_HOME` to choose another one) with the Bouncy Castle jars PD4ML needs for
 * encryption and signing, every file verified against its published SHA-1 checksum. When no
 * classpath is configured the bridge uses the installed build.
 */
final class Installer
{
    public const REPOSITORY = 'https://pd4ml.com/maven2';
    public const CENTRAL = 'https://repo1.maven.org/maven2';
    private const GROUP_PATH = 'com/pd4ml/pd4ml';
    /** The Bouncy Castle version PD4ML is built against (https://pd4ml.com/docs/#2.-obtaining-pd4ml). */
    public const BOUNCYCASTLE_VERSION = '1.84';
    private const BOUNCYCASTLE = ['bcprov-jdk18on', 'bcpkix-jdk18on', 'bcutil-jdk18on'];
    private const VERSION = '/^(\d+)\.(\d+)\.(\d+)(?:fx(\d+)(?:a(\d+))?)?(-jakarta)?(-SNAPSHOT)?$/';
    private const MANIFEST = 'installed.json';

    // --------------------------------------------------------------------------------- versions

    /**
     * An ordering key for a PD4ML version (`4.1.1`, `4.0.23fx2`, `4.0.23fx1a1`, `4.1.2-SNAPSHOT`),
     * or null for an unknown form. A snapshot sorts below its release, an `fxNaM` pre-release
     * below `fxN`.
     */
    public static function versionKey(string $version): ?array
    {
        if (!preg_match(self::VERSION, $version, $m)) {
            return null;
        }
        $fx = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $pre = isset($m[5]) && $m[5] !== '' ? [0, (int) $m[5]] : [1, 0];
        $snapshot = isset($m[7]) && $m[7] !== '';
        return [(int) $m[1], (int) $m[2], (int) $m[3], $fx, $pre[0], $pre[1], $snapshot ? 0 : 1];
    }

    /** The most recent `$major.*` version: releases only unless `$snapshots`, `-jakarta` only with `$jakarta`. */
    public static function selectVersion(array $versions, int $major = 4, bool $snapshots = false,
                                         bool $jakarta = false): string
    {
        $best = null;
        $bestKey = null;
        foreach ($versions as $v) {
            $key = self::versionKey($v);
            if ($key === null || $key[0] !== $major) {
                continue;
            }
            if (str_ends_with($v, '-SNAPSHOT') && !$snapshots) {
                continue;
            }
            if (str_contains($v, '-jakarta') !== $jakarta) {
                continue;
            }
            if ($bestKey === null || $key > $bestKey) {
                $best = $v;
                $bestKey = $key;
            }
        }
        if ($best === null) {
            throw new InstallException("no $major.* version in the repository");
        }
        return $best;
    }

    /** The versions the repository lists. */
    public static function availableVersions(string $repository = self::REPOSITORY): array
    {
        $xml = self::xml(self::fetch("$repository/" . self::GROUP_PATH . '/maven-metadata.xml'));
        $out = [];
        foreach ($xml->versioning->versions->version as $v) {
            $out[] = (string) $v;
        }
        return $out;
    }

    // --------------------------------------------------------------------------------- install

    /**
     * Downloads a PD4ML build (unless already there) and makes it the default one.
     *
     * @param string $version `'latest'` (the most recent 4.* release; with `snapshots`, the most
     *        recent 4.* build) or an exact version such as `'4.0.23fx2'`
     * @param array $options `snapshots` (bool), `jakarta` (bool), `repository`, `home`, `force`
     *        (bool), `quiet` (bool), `bouncycastle` (bool, default true)
     * @return string the PD4ML jar's path
     * @throws InstallException version not found, download failed or checksum mismatch
     */
    public static function install(string $version = 'latest', array $options = []): string
    {
        $repository = $options['repository'] ?? self::REPOSITORY;
        $home = $options['home'] ?? self::defaultHome();
        $force = (bool) ($options['force'] ?? false);
        $quiet = (bool) ($options['quiet'] ?? false);
        if ($version === 'latest') {
            $version = self::selectVersion(self::availableVersions($repository), 4,
                (bool) ($options['snapshots'] ?? false), (bool) ($options['jakarta'] ?? false));
        }
        $name = self::jarName($version, $repository);
        $path = $home . DIRECTORY_SEPARATOR . $name;
        self::download("$repository/" . self::GROUP_PATH . "/$version/$name", $path, $force, $quiet);
        $libs = [];
        if ($options['bouncycastle'] ?? true) {
            foreach (self::BOUNCYCASTLE as $artifact) {
                $lib = "$artifact-" . self::BOUNCYCASTLE_VERSION . '.jar';
                self::download(self::CENTRAL . "/org/bouncycastle/$artifact/" . self::BOUNCYCASTLE_VERSION . "/$lib",
                    $home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $lib, $force, $quiet);
                $libs[] = 'lib/' . $lib;
            }
        }
        $manifest = self::manifest($home);
        $manifest = array_merge($manifest, ['version' => $version, 'jar' => $name, 'libs' => $libs,
            'repository' => $repository, 'installed' => date('c')]);
        file_put_contents($home . DIRECTORY_SEPARATOR . self::MANIFEST,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!$quiet) {
            fwrite(STDERR, "PD4ML $version: $path\n");
        }
        return $path;
    }

    /** Where downloaded builds go: `PD4ML_HOME`, else the user's cache directory. */
    public static function defaultHome(): string
    {
        if ($home = getenv('PD4ML_HOME')) {
            return $home;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $base = getenv('LOCALAPPDATA') ?: (getenv('USERPROFILE') . '\\AppData\\Local');
            return $base . '\\pd4ml-php';
        }
        $base = getenv('XDG_CACHE_HOME') ?: (getenv('HOME') . '/.cache');
        return $base . '/pd4ml-php';
    }

    /** The jar {@see install()} made the default, or null. */
    public static function installedJar(?string $home = null): ?string
    {
        $home ??= self::defaultHome();
        $jar = self::manifest($home)['jar'] ?? null;
        $path = $jar ? $home . DIRECTORY_SEPARATOR . $jar : null;
        return $path && is_file($path) ? $path : null;
    }

    /** The installed build's classpath: the PD4ML jar and its companion jars; empty if none. */
    public static function installedClasspath(?string $home = null): array
    {
        $home ??= self::defaultHome();
        $jar = self::installedJar($home);
        if ($jar === null) {
            return [];
        }
        $out = [$jar];
        foreach (self::manifest($home)['libs'] ?? [] as $lib) {
            $path = $home . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $lib);
            if (is_file($path)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    /** The version {@see install()} made the default, or null. */
    public static function installedVersion(?string $home = null): ?string
    {
        $home ??= self::defaultHome();
        return self::installedJar($home) ? (self::manifest($home)['version'] ?? null) : null;
    }

    // ------------------------------------------------------------------------------- internals

    private static function jarName(string $version, string $repository): string
    {
        if (!str_ends_with($version, '-SNAPSHOT')) {
            return "pd4ml-$version.jar";
        }
        $xml = self::xml(self::fetch("$repository/" . self::GROUP_PATH . "/$version/maven-metadata.xml"));
        foreach ($xml->versioning->snapshotVersions->snapshotVersion ?? [] as $sv) {
            if ((string) $sv->extension === 'jar' && (string) $sv->classifier === '' && (string) $sv->value !== '') {
                return 'pd4ml-' . $sv->value . '.jar';
            }
        }
        $ts = (string) ($xml->versioning->snapshot->timestamp ?? '');
        $build = (string) ($xml->versioning->snapshot->buildNumber ?? '');
        if ($ts !== '' && $build !== '') {
            return 'pd4ml-' . substr($version, 0, -strlen('-SNAPSHOT')) . "-$ts-$build.jar";
        }
        throw new InstallException("no jar listed for $version");
    }

    private static function download(string $url, string $path, bool $force, bool $quiet): void
    {
        if (!$force && is_file($path)) {
            return;
        }
        if (!$quiet) {
            fwrite(STDERR, "downloading $url\n");
        }
        $data = self::fetch($url, 300);
        $expected = strtolower(trim(explode(' ', trim(self::fetch("$url.sha1")))[0]));
        $actual = sha1($data);
        if ($expected !== '' && $actual !== $expected) {
            throw new InstallException("checksum mismatch for $url: $actual != $expected");
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new InstallException("cannot create $dir");
        }
        $tmp = $path . '.part';
        if (file_put_contents($tmp, $data) === false || !rename($tmp, $path)) {
            throw new InstallException("cannot write $path");
        }
    }

    private static function fetch(string $url, int $timeout = 60): string
    {
        if (str_starts_with($url, 'https:') && !extension_loaded('openssl')) {
            throw new InstallException('the openssl PHP extension is needed to download over HTTPS');
        }
        $context = stream_context_create(['http' => ['user_agent' => 'pd4ml-php', 'timeout' => $timeout,
            'follow_location' => 1, 'ignore_errors' => true]]);
        $data = @file_get_contents($url, false, $context);
        $status = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)
            ? (int) $m[1] : 0;
        if ($data === false || $status >= 400) {
            throw new InstallException("cannot download $url" . ($status ? " (HTTP $status)" : ''));
        }
        return $data;
    }

    private static function xml(string $data): \SimpleXMLElement
    {
        $xml = @simplexml_load_string($data);
        if ($xml === false) {
            throw new InstallException('invalid repository metadata');
        }
        return $xml;
    }

    private static function manifest(string $home): array
    {
        $file = $home . DIRECTORY_SEPARATOR . self::MANIFEST;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }
}
