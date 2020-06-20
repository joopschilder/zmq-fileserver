<?php


class FileServer
{
	private ZMQSocket $commandSocket;
	private ZMQSocket $querySocket;
	private ZMQPoll $poll;
	private string $rootDirectory;

	public function __construct(string $rootDirectory, ZMQContext $context, array $commandDsns, array $queryDsns)
	{
		$this->initializeRootDirectory($rootDirectory);
		$this->initializeCommandSocket($context, $commandDsns);
		$this->initializeQuerySocket($context, $queryDsns);
		$this->initializePoll();
	}

	private function initializeRootDirectory(string $rootDirectory): void
	{
		$this->rootDirectory = rtrim($rootDirectory, DIRECTORY_SEPARATOR);
		if (!is_dir($this->rootDirectory)) {
			mkdir($this->rootDirectory, 0777, true);
		}
	}

	private function initializeCommandSocket(ZMQContext $context, array $bindings): void
	{
		if (count($bindings) === 0) {
			throw new InvalidArgumentException("At least one binding required");
		}
		$this->commandSocket = new ZMQSocket($context, ZMQ::SOCKET_PULL);
		$this->commandSocket->setSockOpt(ZMQ::SOCKOPT_HWM, 5);
		foreach ($bindings as $dsn) {
			$this->commandSocket->bind($dsn);
		}
	}

	private function initializeQuerySocket(ZMQContext $context, array $bindings): void
	{
		if (count($bindings) === 0) {
			throw new InvalidArgumentException("At least one binding required");
		}
		$this->querySocket = new ZMQSocket($context, ZMQ::SOCKET_REP);
		$this->querySocket->setSockOpt(ZMQ::SOCKOPT_HWM, 5);
		foreach ($bindings as $dsn) {
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
					print("Malformed LOAD query\n");
					return;
				}
				$this->querySocket->send(@file_get_contents("{$this->rootDirectory}/{$arguments[0]}/{$arguments[1]}") ?: -1);
				break;
			case 'CONTAINS':
				if (count($arguments) !== 2) {
					$this->querySocket->send(-1);
					print("Malformed CONTAINS query\n");
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
					print("Malformed SAVE command\n");
					return;
				}
				$this->saveFile($arguments[0], $arguments[1], $arguments[2]);
				break;
			case 'DELETE':
				if (count($arguments) !== 2) {
					print("Malformed DELETE command\n");
					return;
				}
				$this->deleteFile($arguments[0], $arguments[1]);
				break;
			case 'DELETE_ALL':
				if (count($arguments) !== 1) {
					print("Malformed DELETE_ALL command\n");
				}
				$this->deleteAll($arguments[0]);
				break;
			default:
				$this->onUnknownInput($command, $arguments);
				break;
		}
	}

	private function onUnknownInput(string $input, array $arguments): void
	{
		$arguments = array_map(fn($v) => substr($v, 0, 12), $arguments);
		printf("Unknown input '%s' with args (%s)\n", $input, implode(', ', $arguments));
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
