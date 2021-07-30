<?php
class Server
{
	private $daemon_name = 'php_daemon';
	private $daemon_mod = true;
	private $pid_file = '/tmp/php_daemon.pid';
	private $pid;
	private $port = 8888;
	private $max_clients = 10;
	private $log_path = '/tmp/';
	private $log_level = 3;
	private $current_sock_cnt = 0;
	private $server_run = 't'; //t:tcp, u:udp

	public $io_use_flag = true; //todo
	public $log_callback = null; 
	public $command_callback = null;

	public function __construct($call_func = null) {
		if (is_callable($call_func)) {
			$this->command_callback = $call_func;
		}

		$options = getopt("hsnp:c:t:");

		if (isset($options['h'])) { //help
			echo <<<EOT
usage: deamon.php [-s] [-n] [-p] [-c] [-t]

-s : server stop
     ex) daemon.php -s

-n : run no daemon_mod
     ex) daemon.php -n

-p : set port
     ex) daemon.php -p8080

-c : set max num client
     ex) daemon.php -c10

-t : set udp server or tcp server
     ex) daemon.php -tu (udp)
         daemon.php -t (tcp)
         daemon.php (tcp)

EOT;
			exit;
		}

		if (isset($options['s'])) { //stop
			if (!file_exists($this->pid_file)) {
				die("file {$this->pid_file} dosent exist\n");
			}

			$old_pid = trim(file_get_contents($this->pid_file));

			if (!$old_pid) {
				die("no data pid\n");
			}

			if (function_exists('posix_kill')) {
			    posix_kill($pid, 9);
			    unlink($this->pid_file);
			    exit(0);
			} else {
				system("kill -9 $old_pid");
			}
			unlink($this->pid_file);
			$this->log("server($old_pid): close");
			die("server($old_pid) fisnish\n");
		}

		if (isset($options['n'])) { //no daemon
			$this->daemon_mod = false;
		}

		if (isset($options['p']) && is_numeric($options['p'])) { //port
			$this->port = $options['p'];
		}

		if (isset($options['c']) && is_numeric($options['c'])) { //client num
			$this->max_clients = $options['c'];
		}

		if (isset($options['t']) && $options['t'] == 'u') { //udp
			$this->server_run = 'u';
		}
	}

	private function daemon() {
		$pid = pcntl_fork();

		if ($pid == -1) {
			die('could not fork\n');
		} else if ($pid) { //parent
			exit(0);
		}

		if (function_exists('posix_getpid')) {
			$this->pid = posix_getpid();
		} else if (function_exists('getmypid')){
			$this->pid = getmypid();
		} else {
			die("can't create pid file\n");
		}

		if (file_exists($this->pid_file)) {
			die("file ({$this->pid_file}) exist\n");
		}

		if (function_exists('posix_setsid')) {
			if (!posix_setsid()) {
				die("could not set session leader\n");
			}
		}

		if (!chdir('/')) {
			die("could not chdir/\n");
		}

		if (function_exists('cli_set_process_title')) {
			cli_set_process_title($this->daemon_name);
		}

		if (!file_put_contents($this->pid_file, $this->pid)) {
			die("could not make pid_file\n");
		}

		if (!$this->io_use_flag) {
			fclose(STDIN);
			fclose(STDOUT);
			fclose(STDERR);

			$STDIN = fopen('/dev/null', 'r');
			$STDOUT = fopen('/dev/null', 'wb');
			$STDERR = fopen('/dev/null', 'wb');
		}
	}

	public function run() {
		if ($this->daemon_mod) {
			$this->daemon();
		} else {
			if (function_exists('posix_getpid')) {
				$this->pid = posix_getpid();
			} else if (function_exists('getmypid')){
				$this->pid = getmypid();
			} else {
				die("can't create pid\n");
			}
		}

		if ($this->server_run == 't') {
			$this->run_tcp();
		} else {
			$this->run_udp();
		}
	}

	public function run_udp() {
		$this->log("server($this->pid): udp start");
		printf("server start pid($this->pid) pid_path($this->pid_file) log_path($this->log_path) port($this->port)\n");

		if(!($sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP))) {
			$err_msg = sprintf("server: Unable to running udp server: %d: %s", socket_last_error(), socket_strerror(socket_last_error()));
			$this->log($err_msg);
			die($err_msg);
		}

		if(!socket_bind($sock, 0, $this->port)) {
			$err_msg = sprintf("server: dont bind udp socker: %d: %s", socket_last_error(), socket_strerror(socket_last_error()));
			$this->log($err_msg);
			die($err_msg);
		}

		while (true) {
					
			if (is_callable($this->command_callback)) {
				call_user_func($this->command_callback, $sock);
			} else {
				if(socket_recv($sock, $buf, 1024, MSG_WAITALL) === FALSE) {
					$err_msg = sprintf("server: Could not receive data: %d: %s", socket_last_error(), socket_strerror(socket_last_error()));
					die($err_msg);
				}	

				$this->log("server: data($buf)");
			}
		}

	}
      
	public function run_tcp() {

		$max_clients = $this->max_clients;
		$sock;
		$client_sock;
	
		if ($max_clients < 1) {
			$max_clients = 5;
		} else if ($max_clients > 30) {
			$max_clients = 30;
		}

		$self = $this;
		pcntl_signal(SIGCHLD, function () use ($self) {
			while(($pid = pcntl_waitpid(-1, $status, WNOHANG)) && ($pid > 0)) {
				$self->current_sock_cnt--;
				$self->log("server: child(pid:$pid) close");
				$self->log("server: connected count($this->current_sock_cnt)");
			}
		});

		$ok = false;
		do {
			if (($sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false) break;
			if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) break;
			if (!socket_bind($sock, 0, $this->port)) break;
			if (!socket_listen($sock, $max_clients)) break;
			//if (!socket_set_nonblock($sock)) break;
			$ok = true;
		} while (0);

		if (!$ok) {
			$err_msg = sprintf("server: Unable to running tcp server: %d: %s", socket_last_error(), socket_strerror(socket_last_error()));
			$this->log($err_msg);
			die($err_msg);
		}

		$this->log("server($this->pid): tcp start");
		printf("server start pid($this->pid) pid_path($this->pid_file) log_path($this->log_path) port($this->port)\n");

		while (true) {
			pcntl_signal_dispatch(); //c와 다르게 어떻게 하든 시그널핸들러가 틱단위로 실행되기 때문에 그냥 이렇게 처리..
			if ($this->current_sock_cnt > $max_clients) {
				$this->log("server: too many client count({$this->current_sock_cnt})");
				usleep(500000); //0.5 second sigpause(0);대체할게 없어보인다.
				continue;
			}

			$client_sock = socket_accept($sock);
			if ($client_sock === false) {
				usleep(500000); //0.5second
				continue;
			} else if ($client_sock < 0) { //fail
				socket_close($client_sock);
				continue;
			}

			$pid = pcntl_fork();

			if ($pid == -1) {
				$this->log("server: fork failed");
				socket_close($client_sock);
				continue;
			} else if ($pid > 0) { //parent
				socket_close($client_sock);
				$this->current_sock_cnt++;
				$this->log("server: connected count($this->current_sock_cnt)");
			} else { //child
				socket_getpeername($client_sock, $c_ip,$c_port);
				$this->log("server: client connected ({$c_ip}:{$c_port})");

				if (is_callable($this->command_callback)) {
					call_user_func($this->command_callback, $client_sock);
				} else {
					$buf = socket_read($client_sock, 2048);
					$this->log("server: data($buf)");
				}
				socket_close($client_sock);
				exit(0);
			}
		} //while true

		socket_close($sock);
		$this->log("server: close");
		exit(0);
	}

	public function log($msg) {
		if (is_callable($this->log_callback)) {
			call_user_func($this->log_callback, $msg);
		} else {
			$this->_log($msg);
		}
	}

	public function _log($msg) {
		$logfile = $this->log_path.date("Ymd");

		if (!file_exists($logfile)) {
			$fp = fopen($logfile, "w");
			if ($fp) {
				fclose($fp);
			}    
			//chown($logfile, $conf['g_localuser']);
			//chgrp($logfile, 777);
		}
		if ($msg) $msg = sprintf("%s,%s %s %s", $this->log_level, date("H:i:s"), $this->pid, $msg);
		error_log("{$msg}\n", 3, $logfile);
	}

}

//$server = New Server();

$server = New Server(function ($sock) {
	$file_path = "/tmp/sum.csv";
	if(socket_recv ($sock, $buf, 1024, MSG_WAITALL) !== FALSE) {
		file_put_contents($file_path, $buf."\n", FILE_APPEND);
	} else {
		$errorcode = socket_last_error();
		$errormsg = socket_strerror($errorcode);
		file_put_contents($file_path, sprintf("errorcode: %s errormsg:%s\n", $errorcode, $errormsg), FILE_APPEND);
	}
});
$server->run();
?>
