<?php

namespace Bluora\LaravelFolderWatcher;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class FolderWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watcher
                            {action? : The action to run: load, background, run, list, kill}
                            {optional-value? : When running the help action, specify the action you need help on}
                            {--config-file= : Specify a Yaml config file to load multiple watchers (background)}
                            {--watch-path= : Specify a path to watch for file system changes (background,run)}
                            {--binary= : Specify the binary that is called on a file system change (background,run)}
                            {--script-arguments= : Specify the arguments to run against the binary that is called on a file system change (background,run)}
                            {--pid= : Specify a process PID so we can kill it (kill)}';

    /**
     * The console command description.
     *
     * @var strings
     */
    protected $description = 'Watch a folder. Run the given script over the file that changed.';

    /**
     * Notify instance.
     *
     * @var array
     */
    private $watcher = [];

    /**
     * Constants for what we need to be notified about.
     *
     * @var array
     */
    private $watch_constants = IN_CLOSE_WRITE | IN_MOVE | IN_CREATE | IN_DELETE;

    /**
     * Track notification watch to path.
     *
     * @var array
     */
    private $track_watches = [];

    /**
     * Options for paths.
     *
     * @var array
     */
    private $path_options = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        switch ($this->argument('action')) {
            case 'help':
                return;
            case 'log':
                if ($this->argument('optional-value') === 'clear') {
                    return $this->clearLog();
                }
                $this->requireArguments($this->argument('action'), 'pid');
                return $this->logForProcess($this->option('pid'));
            case 'load':
                $this->requireArguments($this->argument('action'), 'config-file');
                return $this->loadFolderWatchers($this->option('config-file'));
            case 'background':
                $this->requireArguments($this->argument('action'), 'watch-path', 'binary', 'script-arguments');
                return $this->backgroundProcess($this->option('watch-path'), $this->option('binary'), $this->option('script-arguments'));
            case 'run':
                $this->requireArguments($this->argument('action'), 'watch-path', 'binary', 'script-arguments');
                return $this->runProcess($this->option('watch-path'), $this->option('binary'), $this->option('script-arguments'));
            case 'list':
                return $this->listProcesses();
            case 'kill':
                $this->requireArguments($this->argument('action'), 'pid');
                return $this->killProcess($this->option('pid'));
        }

        $this->line('');
        $this->error(sprintf('\'%s\' is not a valid action.', $this->argument('action')));
        $this->line('');
        $this->line('You can view the available actions by reviewing this commands help"');
        $this->line('');
        $this->line('   \'php artisan watcher --help\'');
        $this->line('');
    }

    /**
     * Check given options for action.
     *
     * @param  string $action
     * @param  array  ...$options
     *
     * @return void
     */
    private function requireArguments($action, ...$options)
    {
        foreach ($options as $count => $name) {
            if (empty($this->option($name))) {
                $this->line('');
                $this->error(sprintf('%s requires %s options. Missing value for %s.', ucfirst($action), count($options), $name));
                $this->line('');
                $this->line(sprintf('For example: `php artisan watcher %s %s`', $action, implode(' ', array_map(function($value) { return '--'.$value.'=x'; }, $options))));
                $this->line('');
                exit();
            }
        }
    }

    /**
     * Load watchers from a file.
     *
     * @return int
     */
    private function loadFolderWatchers($config_file)
    {
        if (!file_exists($config_file_path = $config_file)) {
            $config_file_path = base_path().'/'.$config_file;

            if (!file_exists($config_file_path)) {
                $this->line('');
                $this->error(sprintf('Config file %s can not be found.', $config_file));
                $this->line('');

                return 1;
            }
        }

        try {
            $config = Yaml::parse(file_get_contents($config_file_path));
        } catch (ParseException $e) {
            $this->line('');
            $this->error(sprintf('Unable to parse %s %s', $config_file, $e->getMessage()));
            $this->line('');

            return 1;
        }

        foreach ($config as $folder => $scripts) {
            if (!file_exists($folder_path = $folder)) {
                $folder_path = base_path().'/'.$folder;
                if (!file_exists($config_file_path)) {
                    $this->line('');
                    $this->error(sprintf('Folder %s requested to watch does not exist.', $folder));
                    $this->line('');

                    return 1;
                }
            }

            foreach ($scripts as $script) {
                foreach ($script as $binary => $script_arguments) {
                    $this->addLog(sprintf('Will watch \'%s\' and run \'%s %s\'', $folder_path, $binary, str_replace('%s', '<file-path>', $script_arguments)), getmypid());
                    $this->backgroundProcess($folder_path, $binary, $script_arguments);
                }
            }            
        }

        return 0;
    }

    /**
     * Run the process in the background.
     *
     * @param  string $directory_path
     * @param  string $command
     *
     * @return int
     */
    private function backgroundProcess($directory_path, $binary, $script_arguments)
    {
        $this->cleanProcessList();
        $data = $this->getProcessList();
        $command_hash = hash('sha256', $binary.' '.$script_arguments);

        if (!isset($data[$command_hash])) {
            $op = [];
            exec($complete_command = sprintf('nohup php artisan watcher run --watch-path=%s --binary=%s --script-arguments="%s" > /dev/null 2>&1 & echo $!', $directory_path, $binary, $script_arguments, $command_hash), $op);
            $pid = (int)$op[0];
            $this->addLog($complete_command, $pid);

            if ($pid > 0) {
                $this->addProcess($pid, $directory_path, $binary, $script_arguments);
                return 0;
            }

            $this->error('Failed to run this background process.');

            return 0;
        }
        $this->error('Folder watch already exists.');

        return 1;
    }

    /**
     * Watch the provided folder and run the given command on files.
     *
     * @param  string $directory_path
     * @param  string $command
     *
     * @return int
     */
    private function runProcess($directory_path, $binary, $script_arguments)
    {
        if (!function_exists('inotify_init')) {
            $this->error('You need to install PECL inotify to be able to use watcher.');

            return 1;
        }

        $this->command = $binary.' '.$script_arguments;

        // Initialize an inotify instance.
        $this->watcher = inotify_init();

        // Add the given path.
        $this->addWatchPath($directory_path);

        // Listen for notifications.
        return $this->listenForEvents();
    }

    /**
     * List the processes that are running in the background.
     *
     * @return void
     */
    private function listProcesses()
    {
        $this->cleanProcessList();
        $data = $this->getProcessList();

        $this->line('');

        if (count($data)) {
            $this->info('Listed below are the active folder watchers.');
            $this->line('');

            $headers = ['PID', 'Watching folder', 'Binary', 'Script arguments'];
            $rows = [];

            foreach ($data as $pid => $process) {
                if (is_int($pid)) {
                    $rows[] = [
                        $pid,
                        $process['directory_path'],
                        $process['binary'],
                        str_replace('%s', '[file-path]', $process['script_arguments'])
                    ];
                }
            }

            $this->table($headers, $rows);

            $this->line('');
            $this->line('You can view a processes log by running:');
            $this->line('');
            $this->line('   \'php artisan watcher log --pid=[<pid>]\'');
            $this->line('');
            $this->line('You can kill a specific or all processes by running the following:');
            $this->line('');
            $this->line('   \'php artisan watcher kill --pid=[<pid>|all]\'');
            $this->line('');
            return;
        }

        $this->line('No active folder watchers.');
        $this->line('');
    }

    /**
     * Log for a specific process.
     *
     * @param  int|string $pid
     *
     * @return void
     */
    private function logForProcess($pid)
    {
        $log_path = $this->logPath();
        $size = 0;
        while (true) {
            clearstatcache();
            $current_size = filesize($log_path);
            if ($size == $current_size) {
                usleep(10000);
                continue;
            }

            $fh = fopen($log_path, 'r');
            fseek($fh, $size);

            while ($line = fgets($fh)) {
                if ($pid === 'all' || stripos($line, '<'.$pid.'>') !== false) {
                    $this->line(trim($line));
                }
            }

            fclose($fh);
            $size = $current_size;
        }
    }

    /**
     * Kill a background process.
     *
     * @param  int $pid
     *
     * @return int
     */
    private function killProcess($pid)
    {
        $data = $this->getProcessList();

        if ($pid === 'all') {
            foreach ($data as $pid => $process) {
                if (is_int($pid)) {
                    $this->killProcess($pid);
                }
            }

            return 0;
        }

        if (is_int($pid) && isset($data[$pid])) {
            unset($data[$pid]);
            $this->saveProcessList($data);
            posix_kill((int) $pid, SIGKILL);

            return 0;
        }

        return 1;
    }

    /**
     * Listen for notification.
     *
     * @return void
     */
    private function listenForEvents()
    {
        // As long as we have watches that exist, we keep looping.
        while (count($this->track_watches)) {
            // Check the inotify instance for any change events.
            $events = inotify_read($this->watcher);

            // One or many events occured.
            if ($events !== false && count($events)) {
                foreach ($events as $event_detail) {
                    $this->processEvent($events);
                }
            }
        }
    }

    /**
     * Process the events that have occured.
     *
     * @param array $events
     *
     * @return void
     */
    private function processEvent($events)
    {
        $is_dir = false;

        // Directory events have a different hex, convert to the same number for a file event.
        $hex = (string) dechex($event_detail['mask']);
        if (substr($hex, 0, 1) == '4') {
            $hex[0] = '0';
            $event_detail['mask'] = hexdec((int) $hex);
            $is_dir = true;
        }

        // This event is ignored, obviously.
        if ($event_detail['mask'] == IN_IGNORED) {
            $this->removeWatchPath($event_detail['wd']);
        }

        // This event refers to a path that exists.
        elseif (isset($this->track_watches[$event_detail['wd']])) {
            // File or folder path
            $file_path = $this->track_watches[$event_detail['wd']].'/'.$event_detail['name'];
            $path_options = $this->path_options[$event_detail['wd']];

            if ($is_dir) {
                switch ($event_detail['mask']) {
                    // New folder created.
                    case IN_CREATE:
                    // New folder was moved, so need to watch new folders.
                    // New files will run the command.
                    case IN_MOVED_TO:
                        $this->addWatchPath($file_path, $path_options);
                        break;

                    // Folder was deleted or moved.
                    // Each file will trigger and event and so will run the command then.
                    case IN_DELETE:
                    case IN_MOVED_FROM:
                        $this->removeWatchPath($file_path);
                        break;
                }

                return;
            }

            // Check file extension against the specified filter.
            $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);
            if (isset($path_options['filter']) && $file_extension != '') {
                if (count($path_options['filter_allowed']) && !in_array($file_extension, $path_options['filter_allowed'])) {
                    return;
                }
                if (count($path_options['filter_not_allowed']) && in_array('!'.$file_extension, $path_options['filter_not_allowed'])) {
                    return;
                }
            }

            // Run command for all these file events.
            switch ($event_detail['mask']) {
                case IN_CLOSE_WRITE:
                case IN_MOVED_TO:
                case IN_MOVED_FROM:
                case IN_DELETE:
                    $this->runCommand($file_path);
                    break;
            }
        }
    }

    /**
     * Run the given provided command.
     *
     * @param  string $file_path
     *
     * @return void
     */
    private function runCommand($file_path)
    {
        $this->addLog('Running: '.sprintf($this->command, $file_path));
        exec(sprintf($this->command, $file_path));
    }

    /**
     * Add a path to watch.
     *
     * @param string        $path
     * @param boolean|array $options
     *
     * @return void
     */
    private function addWatchPath($original_path, $options = false)
    {
        $this->addLog('Watching '.$original_path);
        $path = trim($original_path);

        if ($options === false) {
            list($path, $options) = self::parseOptions($path);
        }

        if (isset($options['filter'])) {
            $options['filter'] = explode(',', $options['filter']);
            $options['filter_allowed'] = array_filter($options['filter'], function($value) {
                return substr($value, 0, 1) !== '!';
            });
            $options['filter_not_allowed'] = array_filter($options['filter'], function($value) {
                return substr($value, 0, 1) === '!';
            });
        }

        // Watch this folder.
        $watch_id = inotify_add_watch($this->watcher, $path, $this->watch_constants);
        $this->track_watches[$watch_id] = $path;
        $this->path_options[$watch_id] = $options;

        if (is_dir($path)) {
            // Find and watch any children folders.
            $folders = $this->scan($path, true, false);
            foreach ($folders as $folder_path) {
                if (file_exists($folder_path)) {
                    $watch_id = inotify_add_watch($this->watcher, $folder_path, $this->watch_constants);
                    $this->track_watches[$watch_id] = $folder_path;
                    $this->path_options[$watch_id] = $options;
                }
            }
        }
    }

    /**
     * Parse options off a string.
     *
     * @return array
     */
    public static function parseOptions($input)
    {
        $input_array = explode('?', $input);
        $string = $input_array[0];
        $string_options = !empty($input_array[1]) ? $input_array[1] : '';
        $options = [];
        parse_str($string_options, $options);

        return [$string, $options];
    }

    /**
     * Scan recursively through each folder for all files and folders.
     *
     * @param string $scan_path
     * @param bool   $include_folders
     * @param bool   $include_files
     * @param int    $depth
     *
     * @return void
     */
    public static function scan($scan_path, $include_folders = true, $include_files = true, $depth = -1)
    {
        $paths = [];

        if (substr($scan_path, -1) != '/') {
            $scan_path .= '/';
        }

        $contents = scandir($scan_path);

        foreach ($contents as $key => $value) {
            if ($value === '.' || $value === '..') {
                continue;
            }
            $absolute_path = $scan_path.$value;
            if (is_dir($absolute_path) && $depth != 0) {
                $new_paths = self::scan($absolute_path.'/', $include_folders, $include_files, $depth - 1);
                $paths = array_merge($paths, $new_paths);
            }
            if ((is_file($absolute_path) && $include_files) || (is_dir($absolute_path) && $include_folders)) {
                $paths[] = $absolute_path;
            }
        }

        return $paths;
    }

    /**
     * Remove path from watching.
     *
     * @param string $file_path
     *
     * @return void
     */
    private function removeWatchPath($file_path)
    {
        // Find the watch ID for this path.
        $watch_id = array_search($file_path, $this->track_watches);

        // Remove the watch for this folder and remove from our tracking array.
        if ($watch_id !== false && isset($this->track_watches[$watch_id])) {
            $this->line('   Removing watch for '.$this->track_watches[$watch_id]);
            try {
                inotify_rm_watch($this->watcher, $watch_id);
            } catch (\Exception $exception) {
            }
            unset($this->track_watches[$watch_id]);
            unset($this->path_options[$watch_id]);
        }
    }

    /**
     * Get the file path that we're using to store our background processes.
     *
     * @return string
     */
    private function processListPath()
    {
        $xdg_runtime_dir = env('XDG_RUNTIME_DIR') ? env('XDG_RUNTIME_DIR') : '~/';
        $path = $xdg_runtime_dir.'/.active_folder_watcher.yml';

        // Create empty file.
        if (!file_exists($path)) {
            file_put_contents($path, Yaml::dump([]));
        }

        return $path;
    }

    /**
     * Add a background process to the file.
     *
     * @param int    $pid
     * @param string $directory_path
     * @param string $command
     *
     * @return void
     */
    private function addProcess($pid, $directory_path, $binary, $script_arguments)
    {
        $data = $this->getProcessList();
        $data[$pid] = [
            'directory_path'   => $directory_path,
            'binary'           => $binary,
            'script_arguments' => $script_arguments,
        ];
        $data[hash('sha256', $binary.' '.$script_arguments)] = $pid;
        $this->saveProcessList($data);
    }

    /**
     * Remove any background processes that may have terminated.
     *
     * @return void
     */
    private function cleanProcessList()
    {
        $data = $this->getProcessList();

        $sha_to_pid = [];

        foreach ($data as $pid => $process) {
            if (is_int($pid)) {
                if (!posix_kill($pid, 0)) {
                    unset($data[$pid]);
                    $this->addLog('Process was dead', $pid);
                }
                continue;
            }
            $sha_to_pid[$process] = $pid;
        }

        foreach ($sha_to_pid as $pid => $sha) {
            if (!isset($data[$pid])) {
                unset($data[$sha]);
            }
        }

        $this->saveProcessList($data);
    }

    /**
     * Get the list of processes from the file.
     *
     * @return array
     */
    private function getProcessList()
    {
        $process_list_path = $this->processListPath();

        // Parse the YAML config file.
        try {
            return Yaml::parse(file_get_contents($process_list_path));
        } catch (ParseException $e) {
            $this->error(sprintf('Unable to parse %s %s', $process_list_path, $e->getMessage()));

            exit(1);
        }
    }

    /**
     * Save the process to the file.
     *
     * @param  array $data
     *
     * @return void
     */
    private function saveProcessList($data)
    {
        $process_list_path = $this->processListPath();
        file_put_contents($process_list_path, Yaml::dump($data));
    }

    /**
     * Get the file path that we're using to store our process logs.
     *
     * @return string
     */
    private function logPath()
    {
        $xdg_runtime_dir = env('XDG_RUNTIME_DIR') ? env('XDG_RUNTIME_DIR') : '~/';
        $log_path = $xdg_runtime_dir.'/.log_folder_watcher.yml';

        // Create empty file.
        if (!file_exists($log_path)) {
            file_put_contents($log_path, '');
        }

        return $log_path;
    }

    /**
     * Add text to the log.
     *
     * @param string $text
     *
     * @return void
     */
    private function addLog($text, $pid = false)
    {
        if ($pid === false) {
            $pid = getmypid();
        }
        $log_path = $this->logPath();
        $fh = fopen($log_path, 'a+');
        fwrite($fh, sprintf('[%s] <%s> %s', date('Y-m-d H:i:s'), $pid, $text)."\n");
        fclose($fh);
    }

    private function clearLog()
    {
        $log_path = $this->logPath();
        file_put_contents($log_path, '');

        $this->line('');
        $this->info('Log file has been cleared.');
        $this->line('');
    }
}
