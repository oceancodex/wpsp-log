<?php

namespace WPSPCORE\Log;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use WPSPCORE\Base\BaseInstances;
use WPSPCORE\Events\Event\Dispatcher;

/**
 * @property array<string, MonologLogger> $channels
 * @property Dispatcher|null              $events
 */
class Log extends BaseInstances {

	protected $config;
	protected $events   = null;
	protected $channels = [];
	protected $defaultChannel;

	protected $selectedChannel = null;

	public function afterConstruct() {
		$this->config         = $this->loadConfig();
		$this->defaultChannel = $this->config['default'] ?? 'stack';
		$this->events         = $this->funcs->getEvents();
	}

	// ----------------------------------------------------------------------
	// Instance API
	// ----------------------------------------------------------------------

	public function write($level, $message, $context = [], $channel = null) {
		$channelName = $channel ?? $this->selectedChannel ?? $this->defaultChannel;
		$logger      = $this->get($channelName);

		// Event: before write
		$this->dispatch('logging.writing', [
			'channel' => $channelName,
			'level'   => $this->normalizeLevel($level)->getName(),
			'message' => $message,
			'context' => $context,
		]);

		$logger->log($this->normalizeLevel($level), $message, $context);

		if (php_sapi_name() === 'cli') {
			echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
		}

		// Event: after write
		$this->dispatch('logging.written', [
			'channel' => $channelName,
			'level'   => $this->normalizeLevel($level)->getName(),
			'message' => $message,
			'context' => $context,
			'time'    => gmdate('c'),
		]);
	}

	/**
	 * @return MonologLogger
	 */
	public function get($channel) {
		if (!isset($this->channels[$channel])) {
			$this->channels[$channel] = $this->buildChannel($channel);
			$this->selectedChannel = $channel;
		}
		return $this->channels[$channel];
	}

	// ----------------------------------------------------------------------
	// Builders
	// ----------------------------------------------------------------------

	/**
	 * @return MonologLogger
	 */
	protected function buildChannel($channel) {
		$config = $this->configFor($channel);

		$name   = $config['name'] ?? $channel;
		$logger = new MonologLogger($name);

		$level     = $this->toLevel($config['level'] ?? $this->config['level'] ?? 'debug');
		$formatter = $this->defaultFormatter();

		$driver = $config['driver'] ?? 'single';

		switch ($driver) {
			case 'stack':
				$channels = (array)($config['channels'] ?? $this->config['channels'] ?? []);
				foreach ($channels as $child) {
					$childLogger = $this->get($child);
					foreach ($childLogger->getHandlers() as $h) {
						$logger->pushHandler($h);
					}
				}
				break;

			case 'daily':
				$path    = $this->resolveLogPath($config['path'] ?? null, $name);
				$days    = (int)($config['days'] ?? 14);
				$handler = new RotatingFileHandler($path, $days, $level, true, 0644);
				$handler->setFormatter($formatter);
				$logger->pushHandler($handler);
				break;

			case 'stderr':
				$handler = new StreamHandler('php://stderr', $level, true);
				$handler->setFormatter($formatter);
				$logger->pushHandler($handler);
				break;

			case 'syslog':
				$ident    = $config['ident'] ?? $name;
				$facility = $config['facility'] ?? LOG_USER;
				$handler  = new SyslogHandler($ident, $facility, $level, true);
				$handler->setFormatter($formatter);
				$logger->pushHandler($handler);
				break;

			case 'single':
			default:
				$path    = $this->resolveLogPath($config['path'] ?? null, $name);
				$handler = new StreamHandler($path, $level, true, 0644);
				$handler->setFormatter($formatter);
				$logger->pushHandler($handler);
				break;
		}

		return $logger;
	}

	/**
	 * @return LineFormatter
	 */
	protected function defaultFormatter() {
		// Format tương tự Laravel: "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n"
		return new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n", 'Y-m-d H:i:s', true, true);
	}

	protected function resolveLogPath($path, $channel) {
		// Mặc định: storage/logs/wpsp.log hoặc <channel>.log
		$base = $this->funcs->_getStoragePath('/logs');
		if (!is_dir($base)) {
			@mkdir($base, 0755, true);
		}
		$file = $path ?: ($channel . '.log');
//		if (!preg_match('~^([a-zA-Z]:)?[\\/]|^php://~', $file)) {
//			$file = rtrim($base, '/\\') . '/' . ltrim($file, '/\\');
//		}
		return $file;
	}

	// ----------------------------------------------------------------------
	// Config + Helpers
	// ----------------------------------------------------------------------

	protected function loadConfig(): array {
		$config = [];

		try {
			// Nếu có WPSP\Config('logging') thì merge
			$cfg = $this->funcs->_config('logging');
			if (is_array($cfg)) {
				$config = array_replace_recursive($config, $cfg);
			}
		}
		catch (\Throwable $ex) {
			// bỏ qua
		}

		return $config;
	}

	protected function configFor($channel) {
		$channels = $this->config['channels'] ?? [];
		return $channels[$channel] ?? ['driver' => 'single', 'path' => $channel . '.log', 'level' => $this->config['level'] ?? 'debug'];
	}

	protected function toLevel($level) {
		if ($level instanceof Level) return $level;
		$map = [
			'debug'     => Level::Debug,
			'info'      => Level::Info,
			'notice'    => Level::Notice,
			'warning'   => Level::Warning,
			'error'     => Level::Error,
			'critical'  => Level::Critical,
			'alert'     => Level::Alert,
			'emergency' => Level::Emergency,
		];
		$key = strtolower((string)$level);
		return $map[$key] ?? Level::Debug;
	}

	protected function normalizeLevel($level) {
		return $this->toLevel($level);
	}

	protected function makeDispatcher() {
		try {
			// Sử dụng WPSP\Funcs::event() -> trả về dispatcher
			if (class_exists(\WPSP\Funcs::class)) {
				return \WPSP\Funcs::event();
			}
		}
		catch (\Throwable $ex) {
		}
		return null;
	}

	protected function dispatch($event, $payload = []) {
		try {
			if ($this->events) {
				$this->events->dispatch($event, $payload);
			}
		}
		catch (\Throwable $ex) {
			// không cản trở logging nếu event lỗi
		}
	}

	/*
	 *
	 */

	/**
	 * @return static|null
	 */
	public function _channel($name = null) {
		$this->get($name ?? $this->defaultChannel);
		return $this;
	}

	public function _info($message, $context = []) {
		$this->write('info', $message, $context);
	}

	public function _alert($message, $context = []) {
		$this->write('alert', $message, $context);
	}

	public function _debug($message, $context = []) {
		$this->write('debug', $message, $context);
	}

	public function _error($message, $context = []) {
		$this->write('error', $message, $context);
	}

	public function _notice($message, $context = []) {
		$this->write('notice', $message, $context);
	}

	public function _warning($message, $context = []) {
		$this->write('warning', $message, $context);
	}

	public function _critical($message, $context = []) {
		$this->write('critical', $message, $context);
	}

	public function _emergency($message, $context = []) {
		$this->write('emergency', $message, $context);
	}

	public function _log($level, $message, $context = []) {
		$this->write($level, $message, $context);
	}

}