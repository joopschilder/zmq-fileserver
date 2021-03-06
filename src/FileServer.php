<?php

class FileServer
{
	private ZMQSocket $commandSocket;
	private ZMQSocket $querySocket;
	private ZMQPoll $poll;
	private string $rootDirectory;

	public function __construct(ZMQContext $context, string $rootDirectory, array $commandDSNs, array $queryDSNs)
	{
		$this->initializeCommandSocket($context, $commandDSNs);
		$this->initializeQuerySocket($context, $queryDSNs);
		$this->initializeRootDirectory($rootDirectory);
		$this->initializePoll();
		$this->logf('Info: listening...');
	}

	private function logf(string $format, ...$args): void
	{
		printf("[%s]  %s%s", date('H:i:s'), sprintf($format, ...$args), PHP_EOL);
	}

	private function initializeRootDirectory(string $rootDirectory): void
	{
		$this->rootDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR);
		if (!is_dir($this->rootDirectory)) {
			mkdir($this->rootDirectory, 0777, true);
		}
		if (!is_dir($this->rootDirectory)) {
			$this->logf('Error: unable to create directory \'%s\'', $this->rootDirectory);
			die(1);
		}
	}

	private function initializeCommandSocket(ZMQContext $context, array $commandDSNs): void
	{
		if (count($commandDSNs) === 0) {
			$this->logf('Error: need at least one DSN for the command socket');
			die(1);
		}
		$this->commandSocket = new ZMQSocket($context, ZMQ::SOCKET_PULL);
		$this->commandSocket->setSockOpt(ZMQ::SOCKOPT_HWM, 5);
		foreach ($commandDSNs as $dsn) {
			$this->commandSocket->bind($dsn);
		}
	}

	private function initializeQuerySocket(ZMQContext $context, array $queryDSNs): void
	{
		if (count($queryDSNs) === 0) {
			$this->logf('Error: need at least one DSN for the query socket');
			die(1);
		}
		$this->querySocket = new ZMQSocket($context, ZMQ::SOCKET_REP);
		$this->querySocket->setSockOpt(ZMQ::SOCKOPT_HWM, 5);
		foreach ($queryDSNs as $dsn) {
			$this->querySocket->bind($dsn);
		}
	}

	private function initializePoll(): void
	{
		$this->poll = new ZMQPoll();
		$this->poll->add($this->commandSocket, ZMQ::POLL_IN);
		$this->poll->add($this->querySocket, ZMQ::POLL_IN);
	}

	public function run()
	{
		$readable = $writable = [];
		while (true) {
			$this->poll->poll($readable, $writable);
			foreach ($readable as $socket) {
				$socket === $this->querySocket and $this->onQuery();
				$socket === $this->commandSocket and $this->onCommand();
			}
		}
	}

	private function onQuery(): void
	{
		$arguments = $this->querySocket->recvMulti();
		$query = array_shift($arguments);
		switch ($query) {
			case 'LOAD':
				if (count($arguments) !== 2) {
					$this->querySocket->send(-1);
					$this->onUnexpectedAmountOfArguments($query, 2, count($arguments));
					return;
				}
				$this->querySocket->send(@file_get_contents("{$this->rootDirectory}/{$arguments[0]}/{$arguments[1]}") ?: -1);
				break;
			case 'CONTAINS':
				if (count($arguments) !== 2) {
					$this->querySocket->send(-1);
					$this->onUnexpectedAmountOfArguments($query, 2, count($arguments));
					return;
				}
				$this->querySocket->send(file_exists("{$this->rootDirectory}/{$arguments[0]}/{$arguments[1]}") ? 'Y' : 'N');
				break;
			default:
				$this->querySocket->send(-1);
				$this->onUnknownInput($query, $arguments);
				break;
		}
	}

	private function onCommand(): void
	{
		$arguments = $this->commandSocket->recvMulti();
		$command = array_shift($arguments);
		switch ($command) {
			case 'SAVE':
				if (count($arguments) !== 3) {
					$this->onUnexpectedAmountOfArguments($command, 3, count($arguments));
					return;
				}
				$this->saveFile($arguments[0], $arguments[1], $arguments[2]);
				break;
			case 'DELETE':
				if (count($arguments) !== 2) {
					$this->onUnexpectedAmountOfArguments($command, 2, count($arguments));
					return;
				}
				$this->deleteFile($arguments[0], $arguments[1]);
				break;
			case 'DELETE_ALL':
				if (count($arguments) !== 1) {
					$this->onUnexpectedAmountOfArguments($command, 1, count($arguments));
					return;
				}
				$this->deleteAll($arguments[0]);
				break;
			default:
				$this->onUnknownInput($command, $arguments);
				break;
		}
	}

	private function onUnexpectedAmountOfArguments(string $input, int $expected, int $actual): void
	{
		$this->logf('Error: unexpected amount of arguments for input \'%s\' (%d/%d)', $input, $actual, $expected);
	}

	private function onUnknownInput(string $input, array $arguments): void
	{
		$arguments = array_map(fn($v) => substr($v, 0, 12), $arguments);
		$this->logf('Error: unknown input \'%s\' with arguments [%s]', $input, implode(', ', $arguments));
	}

	private function saveFile(string &$namespace, string &$name, string &$content): void
	{
		is_dir($targetDir = "{$this->rootDirectory}/$namespace") or mkdir($targetDir, 0777, true);
		file_put_contents("$targetDir/$name", $content);
	}

	private function deleteFile(string &$namespace, string &$name): void
	{
		@unlink("{$this->rootDirectory}/$namespace/$name");
	}

	private function deleteAll(string &$namespace): void
	{
		$directory = "{$this->rootDirectory}/$namespace";
		if (!is_dir($directory)) {
			return;
		}
		static $source = '/tmp/zmq.fileserver.rsync_empty_dir.d';
		is_dir($source) or @mkdir($source);
		@system("nohup rsync --archive --recursive --delete {$source}/ {$directory}/ 2>&1 >/dev/null &");
		@rmdir($directory);
	}
}
