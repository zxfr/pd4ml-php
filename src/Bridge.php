<?php

declare(strict_types=1);

namespace Pd4ml;

/**
 * The connection to the Java side: a bridge process this PHP process starts (stdin/stdout), or
 * a bridge server it connects to (`PD4ML_BRIDGE=tcp://127.0.0.1:8723`, see `bin/pd4ml serve`).
 *
 * One bridge per PHP process is shared by every PD4ML object ({@see Bridge::get()}); it starts on
 * first use with the configuration from {@see Bridge::configure()} or the environment:
 *
 * - `PD4ML_CLASSPATH`: jars and class directories, separated by `;` (Windows) or `:`; a
 *   `dir/*` entry stands for all jars of a directory
 * - `PD4ML_CLASSPATH_FILE`: a file with one classpath entry per line
 * - otherwise the PD4ML build {@see Installer::install()} downloaded
 * - `PD4ML_JAVA`: the java executable (default: `JAVA_HOME/bin/java`, else `java`)
 * - `PD4ML_JVM_OPTIONS`: extra JVM options (`-Xmx2g ...`)
 * - `PD4ML_BRIDGE`: a running bridge server to use instead of starting one
 * - `PD4ML_BRIDGE_LOG`: a file the bridge's console output goes to (default: discarded)
 */
final class Bridge
{
    private static ?Bridge $default = null;
    private static array $config = [];

    /** @var resource|null */
    private $process = null;
    /** @var resource */
    private $in;
    /** @var resource */
    private $out;
    private int $nextId = 1;
    private int $nextCallback = 1;
    /** @var array<int, callable> */
    private array $callbacks = [];
    /** @var int[] handles to release with the next request */
    private array $released = [];
    /** @var array<string, JavaClass> */
    private array $classes = [];
    private ?\Throwable $callbackError = null;

    private function __construct(array $config)
    {
        $server = $config['server'] ?? (getenv('PD4ML_BRIDGE') ?: null);
        if ($server) {
            $socket = @stream_socket_client($server, $errno, $error, 30);
            if (!$socket) {
                throw new BridgeException("cannot connect to the PD4ML bridge at $server: $error");
            }
            $this->in = $socket;
            $this->out = $socket;
        } else {
            $this->startProcess($config);
        }
        $hello = $this->request(['op' => 'hello']);
        if (($hello['protocol'] ?? 0) !== 1) {
            throw new BridgeException('incompatible PD4ML bridge protocol');
        }
    }

    /**
     * Sets how the bridge starts; call before the first use of PD4ML. Keys: `classpath`
     * (string|string[]), `java` (the java executable), `jvmOptions` (string[]), `server`
     * (`tcp://host:port` of a running bridge server), `log` (a file for the bridge's output).
     */
    public static function configure(array $config): void
    {
        if (self::$default !== null) {
            throw new BridgeException('the PD4ML bridge runs already: configure() before the first use');
        }
        self::$config = $config + self::$config;
    }

    /** The shared bridge, started on first use. */
    public static function get(): Bridge
    {
        return self::$default ??= new Bridge(self::$config);
    }

    public static function isStarted(): bool
    {
        return self::$default !== null;
    }

    /** Stops the shared bridge (it starts again on the next use). */
    public static function shutdown(): void
    {
        if (self::$default !== null) {
            self::$default->close();
            self::$default = null;
        }
    }

    // --------------------------------------------------------------------------------- startup

    /** The classpath to start with: configured, from the environment, or the installed build. */
    public static function classpath(array $config = []): array
    {
        $cp = $config['classpath'] ?? self::$config['classpath'] ?? null;
        if ($cp === null && ($env = getenv('PD4ML_CLASSPATH'))) {
            $cp = $env;
        }
        if ($cp === null && ($file = getenv('PD4ML_CLASSPATH_FILE'))) {
            $cp = preg_split('/\R/', (string) file_get_contents($file));
        }
        if ($cp === null) {
            $cp = Installer::installedClasspath();
        }
        $entries = is_array($cp) ? $cp : explode(PATH_SEPARATOR, (string) $cp);
        $out = [];
        foreach ($entries as $e) {
            $e = trim((string) $e);
            if ($e === '') {
                continue;
            }
            if (str_ends_with($e, '/*') || str_ends_with($e, '\\*')) {
                $out = array_merge($out, glob(substr($e, 0, -2) . '/*.jar') ?: []);
            } else {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** The bridge jar shipped with this package. */
    public static function bridgeJar(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'pd4ml-bridge.jar';
    }

    /** The java executable to run. */
    public static function javaExecutable(array $config = []): string
    {
        if (!empty($config['java'])) {
            return $config['java'];
        }
        if ($java = getenv('PD4ML_JAVA')) {
            return $java;
        }
        if ($home = getenv('JAVA_HOME')) {
            $exe = rtrim($home, '/\\') . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                . (PHP_OS_FAMILY === 'Windows' ? 'java.exe' : 'java');
            if (is_file($exe)) {
                return $exe;
            }
        }
        return 'java';
    }

    private function startProcess(array $config): void
    {
        $classpath = self::classpath($config);
        if (!$classpath) {
            throw new BridgeException("PD4ML not found: install a build with 'php bin/pd4ml install' "
                . '(or Installer::install()), or point at one with Bridge::configure([\'classpath\' => ...]), '
                . 'PD4ML_CLASSPATH or PD4ML_CLASSPATH_FILE');
        }
        $options = $config['jvmOptions'] ?? [];
        if ($env = getenv('PD4ML_JVM_OPTIONS')) {
            $options = array_merge($options, preg_split('/\s+/', trim($env)));
        }
        $command = array_merge(
            [self::javaExecutable($config), '-Djava.awt.headless=true'],
            $options,
            ['-cp', implode(PATH_SEPARATOR, array_merge([self::bridgeJar()], $classpath)), 'com.pd4ml.bridge.Bridge']
        );
        $log = $config['log'] ?? (getenv('PD4ML_BRIDGE_LOG') ?: null);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $log ? ['file', $log, 'a'] : ['file', $null, 'w']];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new BridgeException('cannot start java: ' . $command[0]);
        }
        $this->process = $process;
        $this->in = $pipes[1];
        $this->out = $pipes[0];
    }

    public function close(): void
    {
        if ($this->process === null && !is_resource($this->out)) {
            return;
        }
        try {
            @fwrite($this->out, json_encode(['id' => 0, 'op' => 'close']) . "\n");
            @fflush($this->out);
        } catch (\Throwable) {
            // closing anyway
        }
        if ($this->process !== null) {
            @fclose($this->out);
            @fclose($this->in);
            proc_close($this->process);
            $this->process = null;
        } elseif (is_resource($this->out)) {
            @fclose($this->out);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // ------------------------------------------------------------------------------- protocol

    /** Sends one request and returns its decoded result; callbacks run while waiting. */
    public function request(array $request, ?array &$outputs = null): mixed
    {
        if ($this->released) {
            $release = ['id' => $this->nextId++, 'op' => 'release', 'refs' => $this->released];
            $this->released = [];
            $this->exchange($release);
        }
        $request['id'] = $this->nextId++;
        $response = $this->exchange($request);
        if (isset($response['error'])) {
            throw JavaException::fromResponse($response['error']);
        }
        $outputs = array_map(fn ($o) => $o === null ? null : base64_decode($o), $response['out'] ?? []);
        if ($this->callbackError !== null) {
            $e = $this->callbackError;
            $this->callbackError = null;
            throw $e;
        }
        return $this->decode($response['result'] ?? null);
    }

    private function exchange(array $request): array
    {
        $line = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (@fwrite($this->out, $line . "\n") === false) {
            throw new BridgeException('the PD4ML bridge is gone');
        }
        fflush($this->out);
        while (true) {
            $reply = fgets($this->in);
            if ($reply === false) {
                throw new BridgeException('the PD4ML bridge closed the connection (see PD4ML_BRIDGE_LOG for its output)');
            }
            $message = json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
            if (isset($message['callback'])) {
                $this->dispatch($message);
                continue;
            }
            if (($message['id'] ?? null) === $request['id']) {
                return $message;
            }
        }
    }

    private function dispatch(array $message): void
    {
        $callback = $this->callbacks[$message['callback']] ?? null;
        if ($callback === null || $this->callbackError !== null) {
            return;
        }
        try {
            $callback(...array_map(fn ($a) => $this->decode($a), $message['args']));
        } catch (\Throwable $e) {
            $this->callbackError = $e;   // rethrown when the call that caused it returns
        }
    }

    // ------------------------------------------------------------------------------ operations

    /** A new Java object. */
    public function create(string $class, array $args = []): mixed
    {
        return $this->request(['op' => 'new', 'class' => $class, 'args' => $this->encodeArgs($args)]);
    }

    /** A Java method call; `$outputs` receives what `Output` arguments were written. */
    public function call(JavaObject $target, string $method, array $args = [], ?array &$outputs = null): mixed
    {
        return $this->request(['op' => 'call', 'ref' => $target->handle(), 'method' => $method,
            'args' => $this->encodeArgs($args)], $outputs);
    }

    public function callStatic(string $class, string $method, array $args = [], ?array &$outputs = null): mixed
    {
        return $this->request(['op' => 'static', 'class' => $class, 'method' => $method,
            'args' => $this->encodeArgs($args)], $outputs);
    }

    public function getField(JavaObject|string $target, string $field): mixed
    {
        $request = ['op' => 'get', 'field' => $field];
        $target instanceof JavaObject ? $request['ref'] = $target->handle() : $request['class'] = $target;
        return $this->request($request);
    }

    public function setField(JavaObject|string $target, string $field, mixed $value): void
    {
        $request = ['op' => 'set', 'field' => $field, 'value' => $this->encode($value)];
        $target instanceof JavaObject ? $request['ref'] = $target->handle() : $request['class'] = $target;
        $this->request($request);
    }

    /** A Java object implementing `$interface` whose methods call `$callback(...args)`. */
    public function proxy(string $interface, callable $callback): JavaObject
    {
        $id = $this->nextCallback++;
        $this->callbacks[$id] = $callback;
        return $this->request(['op' => 'proxy', 'interface' => $interface, 'callback' => $id]);
    }

    public function isInstanceOf(JavaObject $object, string $class): bool
    {
        return (bool) $this->request(['op' => 'instanceof', 'ref' => $object->handle(), 'class' => $class]);
    }

    /** A Java class, for its static members and constructors. */
    public function javaClass(string $name): JavaClass
    {
        return $this->classes[$name] ??= new JavaClass($this, $name);
    }

    /** @internal a PHP object for a handle no longer used */
    public function release(int $handle): void
    {
        $this->released[] = $handle;
    }

    // -------------------------------------------------------------------------------- values

    private function encodeArgs(array $args): array
    {
        return array_map(fn ($a) => $this->encode($a), array_values($args));
    }

    private function encode(mixed $v): mixed
    {
        if ($v instanceof JavaObject) {
            return ['$ref' => $v->handle()];
        }
        if ($v instanceof Bytes) {
            return ['$bytes' => base64_encode($v->data)];
        }
        if ($v instanceof Output) {
            return ['$out' => $v->index];
        }
        if ($v instanceof PD4ML) {
            return ['$ref' => $v->java->handle()];
        }
        if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
            return ['$bytes' => base64_encode($v)];      // binary data
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map(fn ($x) => $this->encode($x), $v);
            }
            $m = [];
            foreach ($v as $k => $x) {
                $m[(string) $k] = $this->encode($x);
            }
            return $m ?: new \stdClass();
        }
        if (is_object($v) && !($v instanceof \stdClass)) {
            throw new \InvalidArgumentException('cannot pass a ' . get_class($v) . ' to Java');
        }
        return $v;
    }

    private function decode(mixed $v): mixed
    {
        if (is_array($v)) {
            if (isset($v['$ref'])) {
                return new JavaObject($this, (int) $v['$ref'], $v['class'] ?? '', $v['enum'] ?? null);
            }
            if (isset($v['$bytes'])) {
                return base64_decode($v['$bytes']);
            }
            return array_map(fn ($x) => $this->decode($x), $v);
        }
        return $v;
    }
}
