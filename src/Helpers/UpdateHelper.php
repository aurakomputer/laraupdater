<?php

namespace pcinaglia\laraupdater\Helpers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class UpdateHelper
{
    private string $tmp_backup_dir;
    private string $response_html = '';
    private string $update_type;
    private \GuzzleHttp\Client $guzzle;


    public function __construct()
    {
        $this->tmp_backup_dir = storage_path('app/laraupdater') . '/backup_' . date('Ymd');

        $this->update_type = config('laraupdater.update_type');
        $this->guzzle = new \GuzzleHttp\Client([
            'auth' => [
                config('laraupdater.url.username'),
                config('laraupdater.url.password'),
            ],
        ]);
    }


    public function log($msg, $append_response = false, $type = 'info')
    {
        //Response HTML
        if ($append_response) {
            $this->response_html .= $msg . "<BR>";
        }
        //Log
        $header = "LaraUpdater - ";
        if ($type == 'info') {
            Log::info($header . '[info]' . $msg);
        } elseif ($type == 'warn') {
            Log::error($header . '[warn]' . $msg);
        } elseif ($type == 'err') {
            Log::error($header . '[err]' . $msg);
        } else {
            return;
        }

        if (app()->runningInConsole()) {
            dump($header . ' - ' . $msg);
        } else {
            echo($header . ' - ' . $msg . "<br/>");
        }
    }

    /*
    * Download and Install Update.
    */
    public function update()
    {
        $this->log(trans("laraupdater.SYSTEM_VERSION") . $this->getCurrentVersion(), true, 'info');

        $last_version_info = $this->getLastVersion();
        $last_version = null;

        if ($last_version_info['version'] <= $this->getCurrentVersion()) {
            $this->log(trans("laraupdater.ALREADY_UPDATED"), true, 'info');
            return;
        }

        try {

            if (($last_version = $this->downloadUpdate($last_version_info['url'])) === false) {
                return;
            }

            Artisan::call('down'); // Maintenance mode ON
            $this->log(trans("laraupdater.MAINTENANCE_MODE_ON"), true, 'info');

            if (($status = $this->install($last_version)) === false) {
                $this->log(trans("laraupdater.INSTALLATION_ERROR"), true, 'err');
                return;
            }

            $this->setCurrentVersion($last_version_info['version']); //update system version
            $this->log(trans("laraupdater.INSTALLATION_SUCCESS"), true, 'info');

            $this->log(trans("laraupdater.SYSTEM_VERSION") . $this->getCurrentVersion(), true, 'info');

            Artisan::call('up'); // Maintenance mode OFF
            $this->log(trans("laraupdater.MAINTENANCE_MODE_OFF"), true, 'info');
        } catch (\Exception $e) {
            $this->log(trans("laraupdater.EXCEPTION") . '<small>' . $e->getMessage() . '</small>', true, 'err');
            $this->recovery();

            // up laravel after recovery on error
            Artisan::call('up');
        }
    }

    private function install($archive)
    {
        try {
            $execute_commands = false;
            $update_script = base_path() . '/' . config('laraupdater.tmp_folder_name') . '/' . config('laraupdater.script_filename');


            $zip = new \ZipArchive();
            if ($zip->open($archive)) {
                $archive = substr($archive, 0, -4);

                // check if upgrade_scipr exist
                $update_script_content = $zip->etFromName(config('laraupdater.script_filename'));
                // print($update_script_content);
                if ($update_script_content) {
                    File::put($update_script, $update_script_content);

                    // include update script;
                    include_once $update_script;
                    $execute_commands = true;

                    // run beforeUpdate function from update script
                    beforeUpdate();
                }


                $this->log(trans("laraupdater.CHANGELOG"), true, 'info');

                // dump($archive);
                // die();


                for ($indexFile = 0; $indexFile < $zip->numFiles; $indexFile++) {
                    $filename = $zip->getNameIndex($indexFile);
                    $dirname = dirname($filename);

                    // Exclude files

                    if (substr($filename, -1, 1) == '/' || dirname($filename) === $archive || substr($dirname, 0, 2) === '__') {
                        continue;
                    }

                    if (strpos($filename, 'version.txt') !== false) {
                        continue;
                    }


                    $excludes = config('laraupdater.excludes');
                    if (in_array($filename, $excludes)) {
                        continue;
                    }

                    if (substr($dirname, 0, strlen($archive)) === $archive) {
                        $dirname = substr($dirname, (strlen($dirname) - strlen($archive) - 1) * (-1));
                    };


                    // $filename = $dirname . '/' . basename($filename); //set new purify path for current file

                    if (!is_dir(base_path() . '/' . $dirname)) { //Make NEW directory (if exist also in current version continue...)
                        File::makeDirectory(base_path() . '/' . $dirname, 0755, true, true);
                        $this->log(trans("laraupdater.DIRECTORY_CREATED") . $dirname, true, 'info');
                    }

                    if (!is_dir(base_path() . '/' . $filename)) { //Overwrite a file with its last version
                        if (File::exists(base_path() . '/' . $filename)) {
                            $this->log(trans("laraupdater.FILE_EXIST") . $filename, true, 'info');
                            $this->backup($filename); //backup current version
                        }

                        $this->log(trans("laraupdater.FILE_COPIED") . $filename, true, 'info');
                        ;
                        // dd($filename);
                        $zip->extractTo(base_path(), $filename);
                    }
                }
                $zip->close();

                if ($execute_commands == true) {
                    // upgrade-VERSION.php contains the 'main()' method with a BOOL return to check its execution.
                    afterUpdate();
                    unlink($update_script);
                    $this->log(trans("laraupdater.EXECUTE_UPDATE_SCRIPT") . ' (\'upgrade.php\')', true, 'info');
                }

                File::delete($archive);
                File::deleteDirectory($this->tmp_backup_dir);
                $this->log(trans("laraupdater.TEMP_CLEANED"), true, 'info');
            }
        } catch (\Exception $e) {
            $this->log(trans("laraupdater.EXCEPTION") . '<small>' . $e->getMessage() . '</small>', true, 'err');
            return false;
        }

        return true;
    }

    /*
    * Download Update from $update_baseurl to $tmp_folder_name (local folder).
    */
    private function downloadUpdate($url)
    {
        $this->log(trans("laraupdater.DOWNLOADING"), true, 'info');

        $tmp_folder_name = base_path() . '/' . config('laraupdater.tmp_folder_name');

        if (!is_dir($tmp_folder_name)) {
            File::makeDirectory($tmp_folder_name, $mode = 0755, true, true);
        }

        try {
            $local_file = $tmp_folder_name . '/' . basename($url);

            $update_file = fopen($local_file, "w");
            $assetResponse = $this->guzzle->get(
                $url,
            );

            file_put_contents($local_file, $assetResponse->getBody());
            // die($url);

            $this->log(trans("laraupdater.DOWNLOADING_SUCCESS"), true, 'info');
            return $local_file;
        } catch (\Exception $e) {
            $this->log(trans("laraupdater.DOWNLOADING_ERROR"), true, 'err');
            $this->log(trans("laraupdater.EXCEPTION") . '<small>' . $e->getMessage() . '</small>', true, 'err');
            return false;
        }
    }

    /*
    * Current version ('version.txt' in main folder)
    */
    public function getCurrentVersion()
    {
        // todo: env file version
        if (File::exists(base_path() . '/version.txt')) {
            $version = File::get(base_path() . '/version.txt');
        } else {
            $version = 0;
        }
        return trim(preg_replace('/\s\s+/', ' ', $version));
    }
    private function setCurrentVersion($version)
    {
        // todo: env file version
        File::put(base_path() . '/version.txt', $version);
    }

    /*
    * Check if a new Update exist.
    */
    public function check()
    {
        $last_version = $this->getLastVersion();
        if ($last_version && version_compare($last_version['version'], $this->getCurrentVersion(), ">")) {
            return $last_version;
        }
        return '';
    }

    private function getLastVersion()
    {
        if ($this->update_type == 'url') {
            $response = $this->guzzle->get(config('laraupdater.url.update_baseurl') . '/laraupdater.json');
            $last_version = json_decode($response->getBody(), true);
            return $last_version;
        } elseif ($this->update_type == 'github') {
            // generate last version data from github api
            $response = $this->guzzle->request('GET', 'https://api.github.com/repos/' . config('laraupdater.github.repo') . '/releases/latest');

            $json_data = $response->getBody();
            $data = json_decode($json_data);
            // dump($response);


            foreach ($data->assets as $asset) {
                if ($asset->name == 'release.zip') {
                    return [
                        'version'       => $data->name,
                        'description'   => $data->body,
                        'url'       => $asset->browser_download_url,
                    ];
                }
            }


            return null;
        }
    }

    /*
    * Backup files before performing the update.
    */
    private function backup($filename)
    {

        $backup_dir = $this->tmp_backup_dir;
        if (!is_dir($backup_dir)) {
            File::makeDirectory($backup_dir, $mode = 0755, true, true);
        }

        if (!is_dir($backup_dir . '/' . dirname($filename))) {
            File::makeDirectory($backup_dir . '/' . dirname($filename), $mode = 0755, true, true);
        }

        File::copy(base_path() . '/' . $filename, $backup_dir . '/' . $filename); //to backup folder
    }

    /*
    * Recovery system from the last backup.
    */
    private function recovery()
    {
        $this->log(trans("laraupdater.RECOVERY") . '<small>' . $e . '</small>', true, 'info');

        try {
            $backup_dir = $this->tmp_backup_dir;
            $backup_files = File::allFiles($backup_dir);
            foreach ($backup_files as $file) {
                $filename = (string) $file;
                $filename = substr($filename, (strlen($filename) - strlen($backup_dir) - 1) * (-1));
                File::copy($backup_dir . '/' . $filename, base_path() . '/' . $filename); //to respective folder
            }
        } catch (\Exception $e) {
            $this->log(trans("laraupdater.RECOVERY_ERROR"), true, 'err');
            $this->log(trans("laraupdater.EXCEPTION") . '<small>' . $e->getMessage() . '</small>', true, 'err');
            return false;
        }

        $this->log(trans("laraupdater.RECOVERY_SUCCESS"), true, 'info');
        return true;
    }
}
