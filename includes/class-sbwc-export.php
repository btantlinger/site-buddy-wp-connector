<?php

defined('ABSPATH') || exit;

/**
 * Builds one archive of the site for Site Buddy: a database dump plus wp-content WITHOUT the media library (Site
 * Buddy serves media from the live site), caches and other backup plugins' output. The archive lands at a long,
 * unguessable URL under uploads/, served by the web server itself, and is deleted once Site Buddy says it has it,
 * or after a few hours regardless.
 *
 * With shell access it uses mysqldump and tar, which are fast and handle any size; otherwise it falls back to plain
 * PHP (a table-by-table SQL dump and PharData). A host that kills the build before it finishes leaves the state at
 * "running" past its deadline, which the status call reports as failed.
 */
final class SBWC_Export
{
    const OPTION = 'sbwc_export';
    const KEEP_SECONDS = 6 * HOUR_IN_SECONDS;
    const STALE_RUNNING_SECONDS = 2 * HOUR_IN_SECONDS;
    const ROWS_PER_SELECT = 500;

    /** wp-content entries never copied (relative to wp-content). */
    const EXCLUDE = ['uploads', 'cache', 'upgrade', 'upgrade-temp-backup', 'updraft', 'ai1wm-backups', 'wflogs', 'et-cache', 'litespeed', 'backup-db', 'backups', 'w3tc-config', 'debug.log'];

    const EXCLUDE_PREFIXES = ['backups-dup-', 'backwpup-'];

    public static function boot(): void
    {
        add_action('sbwc_cleanup', [self::class, 'cleanup_if_old']);
        if (! wp_next_scheduled('sbwc_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'sbwc_cleanup');
        }
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION);
        if (! is_array($state)) {
            return ['state' => 'idle'];
        }
        if (($state['state'] ?? '') === 'running' && time() - (int) ($state['started'] ?? 0) > self::STALE_RUNNING_SECONDS) {
            $state['state'] = 'failed';
            $state['error'] = 'The export stopped before it finished; the host probably ended it. Use your host\'s own backup, or ask Site Buddy for help.';
        }

        return $state;
    }

    /** Public view of the state: what the panel needs, never a filesystem path. */
    public static function status(): array
    {
        $state = self::state();
        unset($state['dir'], $state['file']);

        return $state;
    }

    /** Prepare a new export and record it as running. Returns the job id. */
    public static function begin(): string
    {
        self::cleanup();
        $uploads = wp_upload_dir(null, false);
        $token = bin2hex(random_bytes(24));
        $dir = trailingslashit($uploads['basedir']) . 'site-buddy-' . $token;
        $url = trailingslashit($uploads['baseurl']) . 'site-buddy-' . $token;
        if (! wp_mkdir_p($dir)) {
            throw new RuntimeException('Could not create the export folder under uploads.');
        }
        file_put_contents($dir . '/index.html', '');
        file_put_contents($dir . '/.htaccess', "Options -Indexes\n");
        $name = 'site-' . bin2hex(random_bytes(8)) . '.tar.gz';

        update_option(self::OPTION, [
            'state' => 'running',
            'job' => $token,
            'started' => time(),
            'dir' => $dir,
            'file' => $dir . '/' . $name,
            'url' => $url . '/' . $name,
            'method' => null,
        ], false);

        return $token;
    }

    /** Do the work. Runs after the "started" answer has gone back to the panel. */
    public static function run(): void
    {
        $state = get_option(self::OPTION);
        if (! is_array($state) || ($state['state'] ?? '') !== 'running') {
            return;
        }
        @set_time_limit(0);
        @ignore_user_abort(true);
        try {
            $dir = $state['dir'];
            $sql = $dir . '/database.sql';
            $state['method'] = self::dump_database($sql);
            file_put_contents($dir . '/manifest.json', wp_json_encode(SBWC_Manifest::build(), JSON_PRETTY_PRINT));
            $state['method'] .= '+' . self::pack($state['file'], $dir);
            @unlink($sql);
            @unlink($dir . '/manifest.json');
            clearstatcache();
            $state['state'] = 'ready';
            $state['bytes'] = (int) filesize($state['file']);
            $state['sha256'] = hash_file('sha256', $state['file']);
            $state['finished'] = time();
        } catch (Throwable $e) {
            $state['state'] = 'failed';
            $state['error'] = $e->getMessage();
        }
        update_option(self::OPTION, $state, false);
    }

    /** Remove the archive and its folder. */
    public static function cleanup(): void
    {
        $state = get_option(self::OPTION);
        if (is_array($state) && ! empty($state['dir']) && is_dir($state['dir'])) {
            foreach (glob(rtrim($state['dir'], '/') . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($state['dir']);
        }
        delete_option(self::OPTION);
    }

    public static function cleanup_if_old(): void
    {
        $state = get_option(self::OPTION);
        if (is_array($state) && time() - (int) ($state['started'] ?? 0) > self::KEEP_SECONDS) {
            self::cleanup();
        }
    }

    // ------------------------------------------------------------------ database

    private static function dump_database(string $sql): string
    {
        if (self::can_exec() && ($mysqldump = self::which('mysqldump')) !== '' && self::mysqldump($mysqldump, $sql)) {
            return 'mysqldump';
        }
        self::php_dump($sql);

        return 'php-dump';
    }

    private static function mysqldump(string $binary, string $sql): bool
    {
        global $wpdb;
        [$host, $port, $socket] = self::db_host();
        $defaults = tempnam(sys_get_temp_dir(), 'sbwc');
        if ($defaults === false) {
            return false;
        }
        @chmod($defaults, 0600);
        $lines = ['[client]', 'user="' . addcslashes(DB_USER, '"\\') . '"', 'password="' . addcslashes(DB_PASSWORD, '"\\') . '"', 'host="' . addcslashes($host, '"\\') . '"'];
        if ($port !== '') {
            $lines[] = 'port=' . (int) $port;
        }
        if ($socket !== '') {
            $lines[] = 'socket="' . addcslashes($socket, '"\\') . '"';
        }
        file_put_contents($defaults, implode("\n", $lines) . "\n");
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        $command = escapeshellarg($binary) . ' --defaults-extra-file=' . escapeshellarg($defaults)
            . ' --single-transaction --quick --no-tablespaces --skip-lock-tables --default-character-set=utf8mb4 '
            . escapeshellarg(DB_NAME) . ' ' . implode(' ', array_map('escapeshellarg', $tables))
            . ' > ' . escapeshellarg($sql) . ' 2>/dev/null';
        exec($command, $output, $code);
        @unlink($defaults);

        return $code === 0 && is_file($sql) && filesize($sql) > 0;
    }

    /** A table-by-table dump in plain PHP, in batches so memory stays flat. */
    private static function php_dump(string $sql): void
    {
        global $wpdb;
        $out = fopen($sql, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write the database dump.');
        }
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        foreach ($tables as $table) {
            $create = $wpdb->get_row('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`', ARRAY_N);
            fwrite($out, "\nDROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n");
            for ($offset = 0; ; $offset += self::ROWS_PER_SELECT) {
                $rows = $wpdb->get_results('SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . self::ROWS_PER_SELECT . ' OFFSET ' . $offset, ARRAY_N);
                if (! $rows) {
                    break;
                }
                $values = [];
                foreach ($rows as $row) {
                    $values[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'", $row)) . ')';
                }
                fwrite($out, 'INSERT INTO `' . $table . '` VALUES ' . implode(",\n", $values) . ";\n");
            }
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
    }

    /** @return array{0: string, 1: string, 2: string} host, port, socket from DB_HOST */
    private static function db_host(): array
    {
        $host = DB_HOST;
        $port = '';
        $socket = '';
        if (strpos($host, ':') !== false) {
            [$host, $rest] = explode(':', $host, 2);
            ctype_digit($rest) ? $port = $rest : $socket = $rest;
        }

        return [$host === '' ? 'localhost' : $host, $port, $socket];
    }

    // ------------------------------------------------------------------ archive

    private static function pack(string $archive, string $dir): string
    {
        if (self::can_exec() && ($tar = self::which('tar')) !== '') {
            $excludes = '';
            foreach (self::EXCLUDE as $name) {
                $excludes .= ' --exclude=' . escapeshellarg('./' . $name);
            }
            foreach (self::EXCLUDE_PREFIXES as $prefix) {
                $excludes .= ' --exclude=' . escapeshellarg('./' . $prefix . '*');
            }
            $command = escapeshellarg($tar) . ' -czf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg(WP_CONTENT_DIR) . $excludes . ' --transform=' . escapeshellarg('s,^\./,wp-content/,') . ' .'
                . ' -C ' . escapeshellarg($dir) . ' database.sql manifest.json 2>/dev/null';
            exec($command, $output, $code);
            if ($code === 0 && is_file($archive) && filesize($archive) > 0) {
                return 'tar';
            }
            @unlink($archive);
        }
        if (! class_exists('PharData')) {
            throw new RuntimeException('This host can neither run tar nor build archives in PHP (PharData is missing).');
        }
        // One pass: buildFromIterator writes the archive once, where addFile per file rewrote it every time
        // (quadratic; a 7,000-file wp-content took minutes and had barely started).
        $tarPath = substr($archive, 0, -3);
        $phar = new PharData($tarPath);
        $phar->buildFromIterator((function () use ($dir): Generator {
            foreach (self::content_files() as $absolute => $relative) {
                yield 'wp-content/' . $relative => $absolute;
            }
            yield 'database.sql' => $dir . '/database.sql';
            yield 'manifest.json' => $dir . '/manifest.json';
        })());
        $phar->compress(Phar::GZ);
        unset($phar);
        @unlink($tarPath);

        return 'phardata';
    }

    /** @return iterable<string, string> absolute path => path relative to wp-content, for every file copied */
    private static function content_files(): iterable
    {
        $base = rtrim(WP_CONTENT_DIR, '/');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file, string $path) use ($base): bool {
                    $relative = ltrim(substr($path, strlen($base)), '/');
                    $top = explode('/', $relative)[0];
                    if (in_array($top, self::EXCLUDE, true)) {
                        return false;
                    }
                    foreach (self::EXCLUDE_PREFIXES as $prefix) {
                        if (strpos($top, $prefix) === 0) {
                            return false;
                        }
                    }

                    return ! $file->isLink();
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield $file->getPathname() => ltrim(substr($file->getPathname(), strlen($base)), '/');
            }
        }
    }

    public static function content_bytes(): int
    {
        $bytes = 0;
        $deadline = microtime(true) + 5;
        foreach (self::content_files() as $absolute => $relative) {
            $bytes += (int) @filesize($absolute);
            if (microtime(true) > $deadline) {
                break;
            }
        }

        return $bytes;
    }

    // ------------------------------------------------------------------ host

    /** Whether shell tools may be used. The sbwc_use_shell filter turns them off (hosts whose shell misbehaves; tests). */
    public static function can_exec(): bool
    {
        if (! apply_filters('sbwc_use_shell', true) || ! function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    public static function which(string $binary): string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/', '/usr/local/mysql/bin/'] as $path) {
            if (@is_executable($path . $binary)) {
                return $path . $binary;
            }
        }
        if (self::can_exec()) {
            $found = trim((string) @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
            if ($found !== '' && @is_executable($found)) {
                return $found;
            }
        }

        return '';
    }
}
