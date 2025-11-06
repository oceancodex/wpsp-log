<?php

namespace WPSPCORE\Log;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class Log {

	protected static ?MonologLogger $logger  = null;
	protected static string         $path    = '';
	protected static string         $channel = 'default';
	protected static int            $days    = 14;

	/**
	 * Khởi tạo logger (một lần duy nhất)
	 */
	protected static function boot(): void {
		if (self::$logger) return;

		self::$path = sys_get_temp_dir() . '/wpsp-logs';
		if (!is_dir(self::$path)) mkdir(self::$path, 0777, true);

		$file   = self::$path . '/' . self::$channel . '.log';
		$logger = new MonologLogger(self::$channel);

		// Handler: rotating file (daily)
		$handler = new RotatingFileHandler($file, self::$days, MonologLogger::DEBUG, true);
		$handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true));

		$logger->pushHandler($handler);
		self::$logger = $logger;
	}

	public static function channel(string $name): self {
		self::$channel = $name;
		self::$logger  = null; // reset
		self::boot();
		return new static;
	}

	public static function info(string $message, array $context = []): void {
		self::boot();
		self::$logger->info($message, $context);
	}

	public static function error(string $message, array $context = []): void {
		self::boot();
		self::$logger->error($message, $context);
	}

	public static function warning(string $message, array $context = []): void {
		self::boot();
		self::$logger->warning($message, $context);
	}

	public static function debug(string $message, array $context = []): void {
		self::boot();
		self::$logger->debug($message, $context);
	}

	public static function critical(string $message, array $context = []): void {
		self::boot();
		self::$logger->critical($message, $context);
	}

	public static function getLogger(): MonologLogger {
		self::boot();
		return self::$logger;
	}

	public static function setPath(string $path): void {
		self::$path   = rtrim($path, '/');
		self::$logger = null;
	}

	public static function setDays(int $days): void {
		self::$days   = $days;
		self::$logger = null;
	}

}
