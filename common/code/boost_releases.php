<?php
# Copyright 2016 Daniel James
# Distributed under the Boost Software License, Version 1.0.
# (See accompanying file LICENSE_1_0.txt or http://www.boost.org/LICENSE_1_0.txt)

class BoostReleases {
    var $release_file;
    var $release_data = array();

    function __construct($release_file) {
        $this->release_file = $release_file;

        if (is_file($this->release_file)) {
            $release_data = array();
            foreach(BoostState::load($this->release_file) as $version => $data) {
                $data = $this->unflatten_array($data);
                $version_object = BoostVersion::from($version);
                $base_version = $version_object->base_version();
                $version = (string) $version_object;
                $data['version'] = $version_object;

                if (isset($this->release_data[$base_version][$version])) {
                    echo "Duplicate release data for {$version}.\n";
                }
                $this->release_data[$base_version][$version] = $data;
            }
        }
    }

    function save() {
        $flat_release_data = array();
        foreach($this->release_data as $base_version => $versions) {
            foreach($versions as $version => $data) {
                unset($data['version']);
                $flat_release_data[$version] = $this->flatten_array($data);
            }
        }
        BoostState::save($flat_release_data, $this->release_file);
    }

    function unflatten_array($array) {
        $result = array();
        foreach ($array as $key => $value) {
            $reference = &$result;
            foreach(explode('.', $key) as $key_part) {
                if (!array_key_exists($key_part, $reference)) {
                    $reference[$key_part] = array();
                }
                $reference = &$reference[$key_part];
            }
            $reference = $value;
            unset($reference);
        }
        return $result;
    }

    function flatten_array($x, $key_base = '') {
        $flat = array();
        foreach ($x as $sub_key => $value) {
            $key = $key_base ? "{$key_base}.{$sub_key}" : $sub_key;
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flatten_array($value, $key));
            }
            else {
                $flat[$key] = $value;
            }
        }
        return $flat;
    }

    function set_release_data($version, $fields) {
        $base_version = $version->base_version();
        $version_string = (string) $version;
        if (!array_key_exists($base_version, $this->release_data)) {
            $this->release_data[$base_version] = array();
        }
        if (!array_key_exists($version_string, $this->release_data[$base_version])) {
            $this->release_data[$base_version][$version_string] = $this->default_release_data($version);
        }
        foreach ($fields as $name => $value) {
            $this->release_data[$base_version][$version_string][$name] = $value;
        }
    }

    // Get the latest release data for a version
    function get_latest_release_data($version) {
        $version = BoostVersion::from($version);
        $base_version = $version->base_version();
        if (array_key_exists($base_version, $this->release_data)) {
            $chosen_is_dev = true;
            $chosen_version = null;
            $release_data = null;

            foreach ($this->release_data[$base_version] as $version2 => $data) {
                $version_object = BoostVersion::from($version2);
                $is_dev = array_key_exists('release_status', $data) && $data['release_status'] == 'dev';

                if (!$chosen_version ||
                    ($chosen_is_dev && !$is_dev) ||
                    ($chosen_is_dev == $is_dev && $version_object->compare($chosen_version) > 0))
                {
                    $chosen_is_dev = $is_dev;
                    $chosen_version = $version_object;
                    $release_data = $data;
                }
            }

            assert($release_data);
            return $release_data;
        }
        else {
            return $this->default_release_data($version);
        }
    }

    function default_release_data($version) {
        if ($version->compare('1.50.0') < 0) {
            // Assume old versions are released if there's no data.
            return array(
                'version' => $version
            );
        }
        else {
            // For newer versions, release info hasn't been added yet
            // so default to dev version.
            // TODO: Need pre-beta version.
            return array(
                'version' => BoostVersion::master(),
                'release_status' => 'dev',
                'documentation' => '/doc/libs/master/',
            );
        }
    }

    // Expected format:
    //
    // URL
    // (blank line)
    // Output of sha256sum
    function loadReleaseInfo($release_details) {
        if (!preg_match('@
            \A
            \s*([^\s]*)[ \t]*\n
            [ \t]*\n
            (.*)
            @xs', $release_details, $matches))
        {
            throw new BoostException("Error parsing release details");
        }

        $download_page = $matches[1];
        $sha256sums = explode("\n", trim($matches[2]));

        // TODO: Better URL validation?
        if (substr($download_page, -1) != '/') {
            throw new BoostException("Release details needs to start with a directory URL");
        }

        $version = BoostVersion::from($download_page);
        $version_string = (string) $version;

        $downloads = array();
        foreach($sha256sums as $sha256sum) {
            if (!preg_match('@^([0-9a-f]{64}) *([a-zA-Z0-9_.]*)$@', trim($sha256sum), $match)) {
                throw new BoostException("Invalid sha256sum: {$sha256sum}");
            }

            $sha256 = $match[1];
            $filename = $match[2];
            $extension = pathinfo($filename, PATHINFO_EXTENSION);

            $extensions = array(
                '7z' => 'windows', 'zip' => 'windows',
                'gz' => 'unix', 'bz2' => 'unix',
            );
            if (!array_key_exists($extension, $extensions)) {
                throw new BoostException("Invalid extension: {$filename}");
            }
            $line_endings = $extensions[$extension];

            $downloads[$extension] = array(
                'line_endings' => $line_endings,
                'url' => "{$download_page}{$filename}",
                'sha256' => $sha256,
            );
        }

        $data = $this->set_release_data($version, array(
            'download_page' => $download_page,
            'downloads' => $downloads
        ));
    }

    function addDocumentation($version, $path) {
        $data = $this->set_release_data($version, array(
            'documentation' => $path,
        ));
    }

    function setReleaseStatus($version, $status) {
        $base_version = $version->base_version();
        $version_string = (string) $version;

        // TODO: Check for more documentation/downloads?
        //       Not sure how strict this should be, releasing without
        //       any information should work okay, but is not desirable
        if (!isset($this->release_data[$base_version][$version_string])) {
            throw new BoostException("No release info for {$version_string}");
        }

        assert(in_array($status, array('released', 'dev')));
        if ($status === 'released') {
            unset($this->release_data[$base_version][$version_string]['release_status']);
            $this->release_data[$base_version][$version_string]['release_date'] = new DateTime();
        }
        else {
            $this->release_data[$base_version][$version_string]['release_status'];
            unset($this->release_data[$base_version][$version_string]['release_date']);
        }
    }
}
