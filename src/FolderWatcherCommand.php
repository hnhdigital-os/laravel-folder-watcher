<?php

namespace Bluora\LaravelFolderWatcher;

use Illuminate\Console\Command;

class FolderWatcherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'watcher {action=} {option1=} {option2=}';

    /**
     * The console command description.
     *
     * @var strings
     */
    protected $description = 'Watch a folder. Run the given script.';

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
            case 'background':
                return $this->backgroundProcess($this->argument('option1'), $this->argument('option2'));
            case 'run':
                return $this->runProcess($this->argument('option1'), $this->argument('option2'));
            case 'list':
                return $this->listProcesses();
            case 'kill':
                return $this->killProcess($this->option('option1'));
        }
    }

    /**
     * Run the process in the background.
     *
     * @param  string $directory_path
     * @param  string $command
     *
     * @return int
     */
    private function backgroundProcess($directory_path, $command)
    {
        $op = [];
        exec(sprintf('nohup php artisan files:watch %s %s > /dev/null 2>&1 & echo $!', $directory_path, $command), $op);
        $pid = (int)$op[0];
        $this->addWatch($pid, $directory_path, $command);

        return 0;
    }

    /**
     * Watch the provided folder and run the given command on files.
     *
     * @param  string $directory_path
     * @param  string $command
     *
     * @return int
     */
    private function runProcess($directory_path, $command)
    {
        if (!function_exists('inotify_init')) {
            static::console()->error('You need to install PECL inotify to be able to use files:watch.');

            return 1;
        }

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

        foreach ($data as $pid => $process) {
            $this->info(sprintf('[%s] %s - %s', $pid, $process[0], $process[1]));
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

        if (isset($data[$pid])) {
            unset($data[$pid]);
            $this->saveProcessList($data);
            posix_kill($pid, SIGKILL);

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

                continue;
            }

            // Check file extension against the specified filter.
            $file_extension = pathinfo($file_path, PATHINFO_EXTENSION);
            if (isset($path_options['filter']) && $file_extension != '') {
                if (count($path_options['filter_allowed']) && !in_array($file_extension, $path_options['filter_allowed'])) {
                    continue;
                }
                if (count($path_options['filter_not_allowed']) && in_array('!'.$file_extension, $path_options['filter_not_allowed'])) {
                    continue;
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
        static::console()->line('   Watching '.$original_path);
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
            static::console()->line('   Removing watch for '.$this->track_watches[$watch_id]);
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
            yaml_emit_file($path, []);
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
    private function addProcess($pid, $directory_path, $command)
    {
        $data = $this->getProcessList();
        $data[$pid] = [$directory_path, $command];
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

        foreach ($data as $pid => $process) {
            if (!posix_kill($pid, 0)) {
                unset($data[$pid]);
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

        return yaml_parse_file($process_list_path);
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
        yaml_emit_file($process_list_path, $data);
    }
}
