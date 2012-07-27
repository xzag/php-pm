<?php

class Job_Manager {
	private $_pool_size = 20;
	private $_pool = array();
	private $_streams = array();
	private $_stderr = array();

	private $_is_terminated = FALSE;
	protected $_dispatch_function = NULL;

	public function __construct() {
		// init pool
		// 
	}

	public function __destruct() {
		// destroy pool
		foreach (array_keys($this->_pool) as $index) {
			$this->stopJob($index);
		}
	}

	private function checkJobs() {
		$running_jobs = 0;
		foreach ($this->_pool as $index => $job) {
			if (!$job->isRunning()) {
				echo "Stopping job ".$this->_pool[$index]->name()." ($index)" . PHP_EOL;
				$this->stopJob($index);
			} else {
				$running_jobs++;
			}
		}

		return $running_jobs;
	}

	private function getFreeIndex() {
		foreach ($this->_pool as $index => $job) {
			if (!isset($job)) return $index;
		}

		return count($this->_pool) < $this->_pool_size ? count($this->_pool) : -1;
	}

	public function startJob($cmd, $name = 'job') {
		// broadcast existing jobs
		$this->checkJobs();

		$free_pool_slots = $this->_pool_size - count($this->_pool);

		if ($free_pool_slots <= 0) {
			// output error "no free slots in the pool"
			return -1;
		}

		$free_slot_index = $this->getFreeIndex();
		if ($free_slot_index < 0) {
			return -1;
		}

		echo "Starting job $name ($free_slot_index)" . PHP_EOL;
		$this->_pool[$free_slot_index] = new Job($cmd, $name);
		$this->_pool[$free_slot_index]->execute();
		$this->_streams[$free_slot_index] = $this->_pool[$free_slot_index]->getPipe();
		$this->_stderr[$free_slot_index] = $this->_pool[$free_slot_index]->getStderr();

		return $free_slot_index;
	}

	public function stopJob($index) {
		if (!isset($this->_pool[$index]))
			return FALSE;
		
		unset($this->_streams[$index]);
		unset($this->_stderr[$index]);
		unset($this->_pool[$index]);
	}

	public function name($index) {
		if (!isset($this->_pool[$index]))
			return FALSE;

		return $this->_pool[$index]->name();
	}

	public function pipeline($index, $nohup = FALSE) {
		if (!isset($this->_pool[$index]))
			return FALSE;

		return $this->_pool[$index]->pipeline($nohup);
	}	

	public function stderr($index, $nohup = FALSE) {
		if (!isset($this->_pool[$index]))
			return FALSE;

		return $this->_pool[$index]->stderr($nohup);
	}

	private function broadcastMessage($msg) {
		// sends selected signal to all child processes
		foreach ($this->_pool as $pool_index => $job) {
			$job->message($msg);
		}
	}

	private function broadcastSignal($sig) {
		// sends selected signal to all child processes
		foreach ($this->_pool as $pool_index => $job) {
			$job->signal($sig);
		}
	}

	protected function dispatch($cmd) {
		if (is_callable($this->_dispatch_function)) {
			call_user_func($this->_dispatch_function, $cmd);
		}
	}

	public function registerDispatch($callable) {
		if (is_callable($callable)) {
			$this->_dispatch_function = $callable;
		} else {
			trigger_error("$callable is not callable func", E_USER_WARNING);
		}
	}

	private function dispatchMain($cmd) {
		$parts = explode(' ', $cmd);
		$arg = isset($parts[0]) ? $parts[0] : '';
		$val = isset($parts[1]) ? $parts[1] : '';
		switch ($arg) {
			case "exit": 
				$this->broadcastSignal(SIGTERM);
				$this->_is_terminated = TRUE;
				break;

			case "test":
				echo 'sending bulka' . PHP_EOL;
				$this->broadcastMessage('bulka');
				$this->broadcastSignal(SIGUSR1);
				break;
			case 'kill':
				$pool_index = $val !== '' && (int)$val >= 0 ? (int)$val : -1;
				if ($pool_index >= 0 && isset($this->_pool[$pool_index])) {
					$this->_pool[$pool_index]->signal(SIGKILL);
				}
				break;
			default:
				//$this->dispatch($cmd);
				break;
		}
		return FALSE;
	}

	public function process() {
		stream_set_blocking(STDIN, 0);

		$write = NULL;
		$except = NULL;
		while (!$this->_is_terminated) {
			
			$read = $this->_streams;
			$except = $this->_stderr;
			$read[$this->_pool_size] = STDIN;
			//var_dump($read);
			//exit;
			//$except = $this->_stderr;

			if (is_array($read) && count($read) > 0) {
				if (false === ($num_changed_streams = stream_select($read, $write, $except, 2))) {
					// oops
				} elseif ($num_changed_streams > 0) {
					//var_dump($read);
					if (is_array($read) && count($read) > 0) {
						$cmp_array = $this->_streams;
						$cmp_array[$this->_pool_size] = STDIN;
						foreach ($read as $resource) {
							$pool_index = array_search($resource, $cmp_array, TRUE);
							if ($pool_index === FALSE) continue;
							
							if ($pool_index == $this->_pool_size) {
								// stdin
								$content = '';
								while ($cmd = fgets(STDIN)) {
									if (!$cmd) break;
									$content .= $cmd;
								}
								$content = trim($content);
								if ($content) {
									$this->dispatchMain($content);
								}
								//echo "stdin> " . $cmd;
							} else {
								$pool_content = $this->pipeline($pool_index, TRUE);
								$job_name = $this->name($pool_index);

								if ($pool_content) {
									echo $job_name ." ($pool_index)" . ': ' . $pool_content;
								}

								$pool_content = $this->stderr($pool_index, TRUE);
								if ($pool_content) {
									echo $job_name ." ($pool_index)" . ' [STDERR]: ' . $pool_content;
								}
							}
						}
					}
					/*
					if (is_array($except) && count($except) > 0) {
						var_dump($except);
						$cmp_array = $this->_stderr;
						foreach ($except as $resource) {
							$pool_index = array_search($resource, $cmp_array, TRUE);
							if ($pool_index === FALSE) continue;
							
								$pool_content = $this->stderr($pool_index, TRUE);
								$job_name = $this->name($pool_index);

								if ($pool_content) {
									echo $job_name ." ($pool_index)" . ' [STDERR]: ' . $pool_content;
								}
						}
					}*/
				}
			}
			$this->checkJobs();
		}
	}

}