<?php
namespace AzuraCast\Radio\Backend;

class LiquidSoap extends BackendAbstract
{
    public function read()
    {
    }

    /**
     * Write configuration from Station object to the external service.
     * @return bool
     */
    public function write()
    {
        $settings = (array)$this->station->backend_config;

        $playlist_path = $this->station->getRadioPlaylistsDir();
        $media_path = $this->station->getRadioMediaDir();
        $config_path = $this->station->getRadioConfigDir();

        $ls_config = [];
        $ls_config[] = '# WARNING! This file is automatically generated by AzuraCast.';
        $ls_config[] = '# Do not update it directly!';
        $ls_config[] = '';

        $ls_config[] = 'set("init.daemon", false)';
        $ls_config[] = 'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")';
        $ls_config[] = 'set("log.file.path","' . $config_path . '/liquidsoap.log")';

        $ls_config[] = 'set("server.telnet",true)';
        $ls_config[] = 'set("server.telnet.bind_addr","127.0.0.1")';
        $ls_config[] = 'set("server.telnet.port", ' . $this->_getTelnetPort() . ')';
        $ls_config[] = 'set("server.telnet.reverse_dns",false)';

        $ls_config[] = 'set("harbor.bind_addr","0.0.0.0")';
        $ls_config[] = 'set("harbor.reverse_dns",false)';

        $ls_config[] = '';

        // Clear out existing playlists directory.
        $current_playlists = array_diff(scandir($playlist_path), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Write new playlists.
        $playlists_by_type = [];
        $playlists = [];

        $ls_config[] = '# Playlists';

        foreach ($this->station->playlists as $playlist_raw) {
            if (!$playlist_raw->is_enabled) {
                continue;
            }

            $playlist_file = [];
            foreach ($playlist_raw->media as $media_file) {
                $media_file_path = $media_path . '/' . $media_file->path;
                $playlist_file[] = $media_file_path;
            }

            // Add a little extra randomness!
            shuffle($playlist_file);
            $playlist_file_contents = implode("\n", $playlist_file);

            $playlist = $playlist_raw->toArray($this->di['em']);

            $playlist['var_name'] = 'playlist_' . $playlist_raw->getShortName();
            $playlist['file_path'] = $playlist_path . '/' . $playlist['var_name'] . '.pls';

            file_put_contents($playlist['file_path'], $playlist_file_contents);

            $ls_config[] = $playlist['var_name'] . ' = playlist(reload_mode="watch","' . $playlist['file_path'] . '")';

            $playlist_type = $playlist['type'] ?: 'default';
            $playlists_by_type[$playlist_type][] = $playlist;
            $playlists[] = $playlist;
        }

        $ls_config[] = '';
        $ls_config[] = '# Build Radio Station';
        $ls_config[] = '';

        // Cannot build a LiquidSoap playlist with
        if (count($playlists_by_type['default']) == 0) {
            if (count($playlists) > 0) {
                $this->log('LiquidSoap will not start until at least one playlist is set as the "Default" type.',
                    'error');
            }

            return false;
        }

        // Build "default" type playlists.
        $playlist_weights = [];
        $playlist_vars = [];

        foreach ($playlists_by_type['default'] as $playlist) {
            $playlist_weights[] = $playlist['weight'];
            $playlist_vars[] = $playlist['var_name'];
        }

        $ls_config[] = '# Standard Playlists';
        $ls_config[] = 'radio = random(weights=[' . implode(', ', $playlist_weights) . '], [' . implode(', ',
                $playlist_vars) . ']);';
        $ls_config[] = '';

        // Once per X songs playlists
        if (count($playlists_by_type['once_per_x_songs']) > 0) {
            $ls_config[] = '# Once per x Songs Playlists';

            foreach ($playlists_by_type['once_per_x_songs'] as $playlist) {
                $ls_config[] = 'radio = rotate(weights=[1,' . $playlist['play_per_songs'] . '], [' . $playlist['var_name'] . ', radio])';
            }

            $ls_config[] = '';
        }

        // Once per X minutes playlists
        if (count($playlists_by_type['once_per_x_minutes']) > 0) {
            $ls_config[] = '# Once per x Minutes Playlists';

            foreach ($playlists_by_type['once_per_x_minutes'] as $playlist) {
                $delay_seconds = $playlist['play_per_minutes'] * 60;
                $ls_config[] = 'delay_' . $playlist['var_name'] . ' = delay(' . $delay_seconds . '., ' . $playlist['var_name'] . ')';
                $ls_config[] = 'radio = fallback([delay_' . $playlist['var_name'] . ', radio])';
            }

            $ls_config[] = '';
        }

        // Set up "switch" conditionals
        $switches = [];

        // Scheduled playlists
        if (count($playlists_by_type['scheduled']) > 0) {
            foreach ($playlists_by_type['scheduled'] as $playlist) {
                $play_time = $this->_getTime($playlist['schedule_start_time']) . '-' . $this->_getTime($playlist['schedule_end_time']);
                $switches[] = '({ ' . $play_time . ' }, ' . $playlist['var_name'] . ')';
            }
        }

        // Once per day playlists
        if (count($playlists_by_type['once_per_day']) > 0) {
            foreach ($playlists_by_type['once_per_day'] as $playlist) {
                $play_time = $this->_getTime($playlist['play_once_time']);
                $switches[] = '({ ' . $play_time . ' }, ' . $playlist['var_name'] . ')';
            }
        }

        // Set up harbor auth script.
        $ls_config[] = '# DJ Authentication';
        $ls_config[] = 'def dj_auth(user,password) =';
        $ls_config[] = '  ret = get_process_lines("/usr/bin/php '.APP_INCLUDE_ROOT.'/util/cli.php streamer:auth '.$this->station->id.' #{user} #{password}")';
        $ls_config[] = '  ret = list.hd(ret)';
        $ls_config[] = '  bool_of_string(ret)';
        $ls_config[] = 'end';
        $ls_config[] = '';

        // Add harbor live.
        $harbor_params = [
            '"/"',
            'port='.$this->getStreamPort(),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
        ];

        $ls_config[] = '# Harbor Live DJs';
        $ls_config[] = 'live = input.harbor('.implode(', ', $harbor_params).')';
        $ls_config[] = 'radio = fallback(track_sensitive = false, [live, radio])';
        $ls_config[] = '';

        // Add fallback error file.
        // $error_song_path = APP_INCLUDE_ROOT.'/resources/error.mp3';

        $ls_config[] = '# Assemble Fallback';
        // $ls_config[] = 'security = single("'.$error_song_path.'")';
        $ls_config[] = 'requests = request.queue(id="requests")';

        $fallbacks = [];
        // $fallbacks[] = 'live';
        $fallbacks[] = 'requests';

        $switches[] = '({ true }, radio)';
        $fallbacks[] = 'switch([ ' . implode(', ', $switches) . ' ])';
        $fallbacks[] = 'blank(duration=2.)';

        // $ls_config[] = 'radio = fallback(track_sensitive = true, [playlists, security])';
        $ls_config[] = 'radio = fallback(track_sensitive = true, [' . implode(', ', $fallbacks) . '])';

        $crossfade = (int)($settings['crossfade'] ?? 2);
        if ($crossfade > 0) {
            $start_next = round($crossfade * 1.5);

            $ls_config[] = '';
            $ls_config[] = '# Crossfading';
            $ls_config[] = 'radio = crossfade(start_next=' . $start_next . '.,fade_out=' . $crossfade . '.,fade_in=' . $crossfade . '.,radio)';
        }

        if (!empty($settings['custom_config'])) {
            $ls_config[] = '';
            $ls_config[] = '# Custom Configuration (Specified in Station Profile)';
            $ls_config[] = $settings['custom_config'];
        }

        $ls_config[] = '';
        $ls_config[] = '# Outbound Broadcast';

        // Configure the outbound broadcast.
        $fe_settings = (array)$this->station->frontend_config;

        $broadcast_port = $fe_settings['port'];
        $broadcast_source_pw = $fe_settings['source_pw'];

        $settings_repo = $this->di['em']->getRepository('Entity\Settings');
        $base_url = $settings_repo->getSetting('base_url', 'localhost');

        switch ($this->station->frontend_type) {
            case 'remote':
                $this->log(_('You cannot use an AutoDJ with a remote frontend. Please change the frontend type or update the backend to be "Disabled".'),
                    'error');

                return false;
                break;

            case 'shoutcast2':
                $i = 0;
                foreach ($this->station->mounts as $mount_row) {
                    $i++;

                    if (!$mount_row->enable_autodj) {
                        continue;
                    }

                    $bitrate = $mount_row->autodj_bitrate ?: 128;

                    $output_format = '%mp3.cbr(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ')';

                    $output_params = [
                        $output_format, // Required output format (%mp3 etc)
                        'id="radio_out_' . $mount_row->id . '"',
                        'host = "localhost"',
                        'port = ' . ($broadcast_port),
                        'password = "' . $broadcast_source_pw . ':#'.$i.'"',
                        'description = "' . $this->_cleanUpString($this->station->description) . '"',
                        'name = "' . $this->_cleanUpString($this->station->name) . '"',
                        'url = "' . $this->_cleanUpString($this->station->url ?: $base_url) . '"',
                        'public = false',
                        'radio', // Required
                    ];
                    $ls_config[] = 'output.shoutcast(' . implode(', ', $output_params) . ')';
                }
                break;

            case 'icecast':
            default:
                foreach ($this->station->mounts as $mount_row) {
                    if (!$mount_row->enable_autodj) {
                        continue;
                    }

                    $format = strtolower($mount_row->autodj_format ?: 'mp3');
                    $bitrate = $mount_row->autodj_bitrate ?: 128;

                    if ($format == 'ogg') {
                        $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . (int)$bitrate . ')';
                    } else {
                        $output_format = '%mp3.cbr(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ')';
                    }

                    if (!empty($output_format)) {
                        $output_params = [
                            $output_format, // Required output format (%mp3 or %ogg)
                            'id="radio_out_' . $mount_row->id . '"',
                            'host = "localhost"',
                            'port = ' . $broadcast_port,
                            'password = "' . $broadcast_source_pw . '"',
                            'name = "' . $this->_cleanUpString($this->station->name) . '"',
                            'description = "' . $this->_cleanUpString($this->station->description) . '"',
                            'url = "' . $this->_cleanUpString($this->station->url ?: $base_url) . '"',
                            'mount = "' . $mount_row->name . '"',
                            'radio', // Required
                        ];
                        $ls_config[] = 'output.icecast(' . implode(', ', $output_params) . ')';
                    }
                }
                break;
        }

        $ls_config_contents = implode("\n", $ls_config);

        $ls_config_path = $config_path . '/liquidsoap.liq';
        file_put_contents($ls_config_path, $ls_config_contents);

        return true;
    }

    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    protected function _getTime($time_code)
    {
        $hours = floor($time_code / 100);
        $mins = $time_code % 100;

        $system_time_zone = \App\Utilities::get_system_time_zone();
        $system_tz = new \DateTimeZone($system_time_zone);
        $system_dt = new \DateTime('now', $system_tz);
        $system_offset = $system_tz->getOffset($system_dt);

        $app_tz = new \DateTimeZone(date_default_timezone_get());
        $app_dt = new \DateTime('now', $app_tz);
        $app_offset = $app_tz->getOffset($app_dt);

        $offset = $system_offset - $app_offset;
        $offset_hours = floor($offset / 3600);

        $hours += $offset_hours;

        $hours = $hours % 24;
        if ($hours < 0) {
            $hours += 24;
        }

        return $hours . 'h' . $mins . 'm';
    }

    public function getCommand()
    {
        $config_path = $this->station->getRadioConfigDir() . '/liquidsoap.liq';

        return 'liquidsoap ' . $config_path;
    }

    public function request($music_file)
    {
        $queue = $this->command('requests.queue');

        if (!empty($queue[0])) {
            throw new \Exception('Song(s) still pending in request queue.');
        }

        return $this->command('requests.push ' . $music_file);
    }

    public function skip()
    {
        return $this->command('radio_out.skip');
    }

    public function command($command_str)
    {
        $fp = stream_socket_client('tcp://localhost:' . $this->_getTelnetPort(), $errno, $errstr, 20);

        if (!$fp) {
            throw new \App\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    public function getStreamPort()
    {
        return (8000 + (($this->station->id - 1) * 10) + 5);
    }

    protected function _getTelnetPort()
    {
        return (8500 + (($this->station->id - 1) * 10));
    }
}