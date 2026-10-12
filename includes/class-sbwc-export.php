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
        unset($state['dir'], $state['file'], $state['work']);

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
            'work' => self::work_dir($token, $dir),
            'file' => $dir . '/' . $name,
            'url' => $url . '/' . $name,
            'method' => null,
            'progress' => ['phase' => 'starting'],
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
        self::$job = $state['job'];
        $work = $state['work'];
        try {
            $sql = $work . '/database.sql';
            $state['method'] = self::dump_database($sql);
            file_put_contents($work . '/manifest.json', wp_json_encode(SBWC_Manifest::build(), JSON_PRETTY_PRINT));
            self::progress(['phase' => 'archive']);
            $state['method'] .= '+' . self::pack($state['file'], $work);
            clearstatcache();
            $state = array_merge(self::current(), [
                'state' => 'ready',
                'method' => $state['method'],
                'bytes' => (int) filesize($state['file']),
                'sha256' => hash_file('sha256', $state['file']),
                'finished' => time(),
                'progress' => ['phase' => 'done'],
            ]);
        } catch (SBWC_Cancelled $e) {
            self::remove_dir($work);

            return;
        } catch (Throwable $e) {
            $state = array_merge(self::current() ?: $state, ['state' => 'failed', 'error' => $e->getMessage()]);
        }
        self::remove_dir($work);
        if ((self::current()['job'] ?? null) === self::$job) {
            update_option(self::OPTION, $state, false);
        }
    }

    /** @var string|null the job this process is running, so a newer export or a clean-up can stop it */
    private static $job = null;

    private static $last_progress = 0.0;

    private static function current(): array
    {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        $state = get_option(self::OPTION);

        return is_array($state) ? $state : [];
    }

    /**
     * Record progress (at most every two seconds), and stop if this export was cancelled or replaced: the panel
     * removing the archive, or starting another, is how a running export is stopped.
     */
    private static function progress(array $progress, bool $force = true): void
    {
        if (! $force && microtime(true) - self::$last_progress < 2) {
            return;
        }
        self::$last_progress = microtime(true);
        $state = self::current();
        if (($state['job'] ?? null) !== self::$job) {
            throw new SBWC_Cancelled();
        }
        $state['progress'] = $progress;
        update_option(self::OPTION, $state, false);
    }

    /**
     * Where the dump and manifest are written while the archive is built: outside the web root when the system's
     * temp folder is usable and has room, so a half-written dump can never be fetched over the web. Otherwise a
     * subfolder of the export folder, with a random name and a deny rule (honoured by Apache and LiteSpeed).
     */
    private static function work_dir(string $token, string $exportDir): string
    {
        global $wpdb;
        $needed = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(data_length) FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s',
            DB_NAME,
            $wpdb->esc_like($wpdb->prefix) . '%'
        )) * 2 + 50 * MB_IN_BYTES;
        foreach ([defined('WP_TEMP_DIR') ? WP_TEMP_DIR : '', sys_get_temp_dir(), (string) ini_get('upload_tmp_dir')] as $base) {
            $base = rtrim((string) $base, '/');
            if ($base === '' || strpos(wp_normalize_path($base) . '/', wp_normalize_path(ABSPATH)) === 0 || ! @is_writable($base)) {
                continue;
            }
            $free = function_exists('disk_free_space') ? @disk_free_space($base) : false;
            if ($free !== false && $free < $needed) {
                continue;
            }
            $dir = $base . '/sbwc-' . $token;
            if (wp_mkdir_p($dir)) {
                @chmod($dir, 0700);

                return $dir;
            }
        }
        $dir = $exportDir . '/work-' . bin2hex(random_bytes(16));
        wp_mkdir_p($dir);
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($dir . '/index.html', '');

        return $dir;
    }

    private static function remove_dir(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }
        foreach (glob(rtrim($dir, '/') . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /** Remove the archive, its folder and the work folder. A running export notices and stops. */
    public static function cleanup(): void
    {
        $state = get_option(self::OPTION);
        if (is_array($state)) {
            self::remove_dir((string) ($state['work'] ?? ''));
            foreach (glob(rtrim((string) ($state['dir'] ?? ''), '/') . '/work-*', GLOB_ONLYDIR) ?: [] as $work) {
                self::remove_dir($work);
            }
            self::remove_dir((string) ($state['dir'] ?? ''));
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
        self::progress(['phase' => 'database']);
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

    /**
     * A table-by-table dump in plain PHP. Each table is read with ONE unbuffered query (MYSQLI_USE_RESULT): rows
     * stream as they are read, so memory stays flat and a large table is one pass. Paging with LIMIT/OFFSET re-scans
     * every earlier row per page and slowed to ~60 KB/s on a 934 MB database (a shared-hosting test site, 2026-10-11).
     * The stream uses a connection of its own, since an open unbuffered result blocks its connection and progress
     * is written through WordPress's.
     */
    private static function php_dump(string $sql): void
    {
        global $wpdb;
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        $db = self::connect();
        $out = fopen($sql, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not write the database dump.');
        }
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n");
        $written = 0;
        foreach ($tables as $index => $table) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch_row();
            fwrite($out, "\nDROP TABLE IF EXISTS {$quoted};\n" . $create[1] . ";\n");
            $result = $db->query('SELECT * FROM ' . $quoted, MYSQLI_USE_RESULT);
            if ($result === false) {
                throw new RuntimeException('Could not read table ' . $table . ': ' . $db->error);
            }
            $rows = 0;
            $batch = [];
            $batchBytes = 0;
            while (($row = $result->fetch_row()) !== null) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = $value === null ? 'NULL' : "'" . $db->real_escape_string($value) . "'";
                }
                $line = '(' . implode(',', $values) . ')';
                $batch[] = $line;
                $batchBytes += strlen($line);
                $rows++;
                if (count($batch) >= 1000 || $batchBytes > MB_IN_BYTES) {
                    $written += fwrite($out, 'INSERT INTO ' . $quoted . ' VALUES ' . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $batchBytes = 0;
                    self::progress(['phase' => 'database', 'table' => $table, 'table_index' => $index + 1, 'tables' => count($tables), 'rows' => $rows, 'bytes' => $written], false);
                }
            }
            $result->free();
            if ($batch) {
                $written += fwrite($out, 'INSERT INTO ' . $quoted . ' VALUES ' . implode(",\n", $batch) . ";\n");
            }
            self::progress(['phase' => 'database', 'table' => $table, 'table_index' => $index + 1, 'tables' => count($tables), 'rows' => $rows, 'bytes' => $written]);
        }
        fwrite($out, "SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($out);
        $db->close();
    }

    private static function connect(): mysqli
    {
        [$host, $port, $socket] = self::db_host();
        $db = mysqli_init();
        if (! @$db->real_connect($host, DB_USER, DB_PASSWORD, DB_NAME, $port !== '' ? (int) $port : null, $socket !== '' ? $socket : null)) {
            throw new RuntimeException('Could not open a second database connection for the dump.');
        }
        $db->set_charset('utf8mb4');

        return $db;
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

/** Thrown inside a running export when the panel removed it or started another. */
final class SBWC_Cancelled extends RuntimeException
{
}
