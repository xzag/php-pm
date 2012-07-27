<?php 

class Executable {
	protected $_is_terminated = FALSE;

	protected $_cleanup_function = NULL;

	public function __construct() {
		pcntl_signal(SIGTERM, array('Executable', 'signalHandler'));
		pcntl_signal(SIGHUP, array('Executable', 'signalHandler'));
		pcntl_signal(SIGINT, array('Executable', 'signalHandler'));
		pcntl_signal(SIGUSR1, array('Executable', 'signalHandler'));
		pcntl_signal(SIGUSR2, array('Executable', 'signalHandler'));

		stream_set_blocking(STDIN, 0);
		stream_set_blocking(STDOUT, 0);
		stream_set_blocking(STDERR, 0);
	}

	public function __destruct() {
		// 
		//echo "destructor called in " . get_class($this) . PHP_EOL;
		if (!$this->_is_terminated) {
			$this->_is_terminated = TRUE;
			$this->isTerminated();
		}
	}

	private function cleanup() {
		if (is_callable($this->_cleanup_function)) {
			call_user_func($this->_cleanup_function);
		}
	}

	protected function registerCleanup($callable) {
		if (is_callable($callable)) {
			$this->_cleanup_function = $callable;
		} else {
			trigger_error("$callable is not callable func", E_USER_WARNING);
		}
	}

	protected function isTerminated() {
		pcntl_signal_dispatch();
		if ($this->_is_terminated) {
			$this->cleanup();
		}

		return $this->_is_terminated;
	}

	protected function dispatch($cmd) {
		//switch ($cmd) {

//		}
	}

	protected function checkStdin() {
		$read = array(STDIN);
		$write = NULL;
		$except = NULL;

		if (is_array($read) && count($read) > 0) {
			if (false === ($num_changed_streams = stream_select($read, $write, $except, 2))) {
				// oops
			} elseif ($num_changed_streams > 0) {
				if (is_array($read) && count($read) > 0) {
					// stdin
					$content = '';
					while ($cmd = fgets(STDIN)) {
						if (!$cmd) break;
						$content .= $cmd;
					}
					$this->dispatch($content);
					echo "recieved $content";
					//echo "stdin> " . $cmd;
				}
			}
		}

	}

	protected function signalHandler ($signo) {
		switch ($signo) {
			case SIGTERM:
			case SIGHUP:
			case SIGINT:
				// handle shutdown tasks
				//exit;

				$this->_is_terminated = TRUE;
				//echo "exiting in ".get_class($this)."...\n";
				break;
			case SIGUSR1:
				//echo "SIGUSR1 recieved\n";
				$this->checkStdin();
				break;
			case SIGUSR2:
				$this->_is_terminated = TRUE;
				echo "[SHUTDOWN] in " . get_class($this) . PHP_EOL;
				flush();
				exit(1);
				break;
			default:
				// handle all other signals
				break;
		}
	}
}
