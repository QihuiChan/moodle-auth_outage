<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace auth_outage\local\cli;

use auth_outage\local\outage;
use auth_outage\local\outagedb;
use coding_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Outage CLI to create outage.
 *
 * @package    auth_outage
 * @author     Daniel Thee Roperto <daniel.roperto@catalyst-au.net>
 * @copyright  2016 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create extends clibase {
    /**
     * @var mixed[] Defaults to use if given option is null.
     */
    private $defaults;

    /**
     * Generates all options (parameters) available for the CLI command.
     * @return mixed[] Options.
     */
    public function generate_options() {
        // Do not provide some defaults, if cloning an outage we need to know which parameters were provided.
        return [
            'help' => false,
            'clone' => null,
            'autostart' => null,
            'warn' => null,
            'start' => null,
            'duration' => null,
            'title' => null,
            'description' => null,
            'onlyid' => false,
            'block' => false,
        ];
    }

    /**
     * Generate all short forms for the available options.
     * @return string[] Short form options.
     */
    public function generate_shortcuts() {
        return [
            'a' => 'autostart',
            'b' => 'block',
            'c' => 'clone',
            'd' => 'duration',
            'e' => 'description',
            'h' => 'help',
            's' => 'start',
            't' => 'title',
            'w' => 'warn',
        ];
    }

    /**
     * Sets the default values for options.
     * @param mixed[] $defaults Defaults.
     * @throws coding_exception
     */
    public function set_defaults(array $defaults) {
        $missing = $this->generate_options();

        // Check if any extra parameter was given.
        foreach (array_keys($defaults) as $key) {
            if (!array_key_exists($key, $missing)) {
                throw new coding_exception('$default['.$key.'] is not valid.');
            }
            unset($missing[$key]);
        }

        // Check if any required parameter is missing.
        foreach (array_keys($missing) as $k => $v) {
            if (is_null($v)) {
                throw new coding_exception('$default[] missing: '.$k);
            }
        }

        $this->defaults = $defaults;
    }

    /**
     * Executes the CLI.
     */
    public function execute() {
        // Help always overrides any other parameter.
        if ($this->options['help']) {
            $this->show_help('create');
            return;
        }

        // If not help mode, 'start' is required and cannot use default.
        if (is_null($this->options['start'])) {
            throw new cli_exception(get_string('clierrormissingparamaters', 'auth_outage'));
        }

        // If cloning, set defaults to outage being cloned.
        if (!is_null($this->options['clone'])) {
            $this->clone_defaults();
        }

        // Merge provided parameters with defaults then create outage.
        $options = $this->merge_options();
        $id = $this->create_outage($options);

        if ($options['block']) {
            $block = new waitforit(['outageid' => $id]);
            $block->execute();
        }
    }

    /**
     * Merges provided options with defaults.
     * @return mixed[] Parameters to use.
     * @throws cli_exception
     */
    private function merge_options() {
        $options = $this->options;

        // Merge with defaults.
        if (!is_null($this->defaults)) {
            foreach ($options as $k => $v) {
                if (is_null($v) && array_key_exists($k, $this->defaults)) {
                    $options[$k] = $this->defaults[$k];
                }
            }
        }

        return $this->merge_options_check_parameters($options);
    }

    /**
     * Creates an outages based on the provided options.
     * @param mixed[] $options Options used to create the outage.
     * @return int Id of the new outage.
     */
    private function create_outage(array $options) {
        // We need to become an admin to avoid permission problems.
        $this->become_admin_user();

        // Create the outage.
        $start = $this->time + $options['start'];
        $outage = new outage([
            'autostart' => $options['autostart'],
            'warntime' => $start - $options['warn'],
            'starttime' => $start,
            'stoptime' => $start + $options['duration'],
            'title' => $options['title'],
            'description' => $options['description'],
        ]);
        $id = outagedb::save($outage);

        // All done!
        if ($options['onlyid']) {
            printf("%d\n", $id);
        } else {
            printf("%s\n", get_string('clioutagecreated', 'auth_outage', ['id' => $id]));
        }

        return $id;
    }

    private function clone_defaults() {
        $id = $this->options['clone'];
        if (!is_number($id) || ($id <= 0)) {
            throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => 'clone']));
        }

        $outage = outagedb::get_by_id((int)$id);
        $this->set_defaults([
            'autostart' => $outage->autostart,
            'warn' => $outage->get_warning_duration(),
            'duration' => $outage->get_duration_planned(),
            'title' => $outage->title,
            'description' => $outage->description,
        ]);
    }

    /**
     * Check parameters converting their type as needed.
     * @param mixed $options Input options.
     * @return mixed Output options.
     * @throws cli_exception
     */
    private function merge_options_check_parameters(array $options) {
        // Check parameters that must be a non-negative int while converting their type to int.
        foreach (['start', 'warn', 'duration'] as $param) {
            if (!is_number($options[$param])) {
                throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => $param]));
            }
            $options[$param] = (int)$options[$param];
            if ($options[$param] < 0) {
                throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => $param]));
            }
        }

        // Check parameters that must be a non empty string.
        foreach (['title', 'description'] as $param) {
            if (!is_string($options[$param])) {
                throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => $param]));
            }
            $options[$param] = trim($options[$param]);
            if (strlen($options[$param]) == 0) {
                throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => $param]));
            }
        }

        // Check parameters that must be a specified bool.
        foreach (['autostart'] as $param) {
            if (is_string($options[$param])) {
                switch (strtoupper($options[$param])) {
                    case '0':
                    case 'FALSE':
                    case 'NO':
                    case 'N':
                        $options[$param] = false;
                        break;
                    case '1':
                    case 'TRUE':
                    case 'YES':
                    case 'Y':
                        $options[$param] = true;
                        break;
                }
            }
            if (!is_bool($options[$param])) {
                throw new cli_exception(get_string('clierrorinvalidvalue', 'auth_outage', ['param' => $param]));
            }
        }

        return $options;
    }
}
