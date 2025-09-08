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

/**
 * Module pulse 2.0 - Plugin information for the pulse condition class.
 *
 * @package   mod_pulse
 * @copyright 2023, bdecent gmbh bdecent.de
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pulse\plugininfo;

use core\plugininfo\base, part_of_admin_tree, admin_settingpage;


/**
 * Pulse condition class extends base class providing access to the information about a pulse 2.0 plugin.
 */
class pulsecondition extends base {

    /**
     * Returns the information about plugin availability
     *
     * True means that the plugin is enabled. False means that the plugin is
     * disabled. Null means that the information is not available, or the
     * plugin does not support configurable availability or the availability
     * can not be changed.
     *
     * @return null|bool
     */
    public function is_enabled() {
        return true;
    }

    /**
     * Should there be a way to uninstall the plugin via the administration UI.
     *
     * By default uninstallation is not allowed, plugin developers must enable it explicitly!
     *
     * @return bool
     */
    public function is_uninstall_allowed() {
        return true;
    }

    /**
     * Loads plugin setting into the settings tree.
     *
     * @param \part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig whether the current user has moodle/site:config capability
     */
    public function load_settings(part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {

        $ADMIN = $adminroot; // May be used in settings.php.
        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        if (!$hassiteconfig || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $section = $this->get_settings_section_name();
        $page = new admin_settingpage($section, $this->displayname, 'moodle/site:config', $this->is_enabled() === false);
        include($this->full_path('settings.php')); // This may also set $settings to null.

        if ($page) {
            $ADMIN->add($parentnodename, $page);
        }
    }

    /**
     * Get a sub plugins in the ace tools plugin.
     *
     * @return array $subplugins.
     */
    public function get_plugins_list() {
        $conditionplugins = \core_component::get_plugin_list('pulsecondition');
        return $conditionplugins;
    }

    /**
     * Get the list of condition plugins with its base class instance.
     */
    public function get_plugins_base() {

        $conditionextendlist = self::get_condition_depencencies();

        $plugins = $this->get_plugins_list();

        if (class_exists('\core_cache\cache')) {
            $cache = \core_cache\cache::make('mod_pulse', 'pulseplugindependency');
        } else {
            // Fallback for older Moodle versions.
            $cache = \cache::make('mod_pulse', 'pulseplugindependency');
        }

        if (!empty($plugins)) {

            foreach ($plugins as $componentname => $pluginpath) {

                $pluginname = "pulsecondition_{$componentname}";
                // Get the plugin details from cache.
                $componentcache = $cache->get($pluginname);
                // Cache not available for this plugin, so check the plugin details and insert into cache.
                if (empty($componentcache)) {
                    $softdependencies = $conditionextendlist[$pluginname] ?? [];
                    $notavailable = false;
                    foreach ($softdependencies as $dependpluginname => $dependpluginversion) {
                        $component = \core_plugin_manager::instance()->get_plugin_info($dependpluginname);

                        if (empty($component)) {
                            $notavailable = true;
                            continue;
                        }

                        if ($component->versiondisk < $dependpluginversion) {
                            $notavailable = true;
                            continue;
                        }
                    }
                    $cache->set($pluginname, (object) ['dependencyavailable' => !$notavailable]);
                    if ($notavailable) {
                        continue;
                    }

                } else if ($componentcache->dependencyavailable == false) {
                    // Dependency is not available.
                    continue;
                }

                // Soft dependencies are available, so add the plugin to the list.
                // Condition plugin instance.
                $instance = $this->get_plugin($componentname);
                $conditions[$componentname] = $instance;
            }
        }

        return $conditions ?? [];
    }

    /**
     * Get the condtion component actionform instance.
     *
     * @param string $componentname
     * @return \conditionform
     */
    public function get_plugin($componentname) {

        $classname = "pulsecondition_$componentname\conditionform";
        if (!class_exists($classname)) {
            throw new \moodle_exception('actioncomponentmissing', 'pulse');
        }
        $instance = new $classname();
        $instance->set_component($componentname);

        return $instance;
    }

    /**
     * Instance.
     *
     * @return \pulsecondition
     */
    public static function instance() {
        static $instance;
        return $instance ?: new self();
    }

    /**
     * Get list of action plugins base class instance.
     *
     * @return stdclass
     */
    public static function get_list() {
        static $conditionplugins = null;

        if (!$conditionplugins) {
            $conditionplugins = new self();
        }

        $plugins = $conditionplugins->get_plugins_base();
        return $plugins;
    }

    /**
     * Delete the conditions instance overrides.
     *
     * @param int $instanceid
     * @return void
     */
    public static function delete_condition_instance_overrides(int $instanceid) {
        global $DB;

        if ($DB->delete_records('pulse_condition_overrides', ['instanceid' => $instanceid])) {
            return true;
        }

        return false;
    }

    /**
     * Verify the condition dependencies.
     *
     * @return void
     */
    public static function get_condition_depencencies() {

        $conditionextendlist = get_plugin_list_with_function('pulsecondition', 'extend_softdepencencies', 'lib.php');
        array_walk($conditionextendlist, function(&$fn, $name) {
            $fn = $fn();
        });

        return $conditionextendlist;
    }

    /**
     * Check the condition is available or not.
     *
     * @param string $componentname
     * @return bool
     */
    public static function is_condition_available($componentname) {
        global $CFG;

        if (class_exists('\core_cache\cache')) {
            $cache = \core_cache\cache::make('mod_pulse', 'pulseplugindependency');
        } else {
            // Fallback for older Moodle versions.
            $cache = \cache::make('mod_pulse', 'pulseplugindependency');
        }

        $pluginname = "pulsecondition_{$componentname}";
        $componentcache = $cache->get($pluginname);
        if (empty($componentcache)) {
            $file = $CFG->dirroot . '/mod/pulse/conditions/' . $componentname . '/lib.php';
            if (file_exists($file) && function_exists("{$pluginname}_extend_softdepencencies")) {
                $method = "{$pluginname}_extend_softdepencencies";
                $softdependencies = $method();
                $available = true;
                foreach ($softdependencies as $dependpluginname => $dependpluginversion) {
                    $component = \core_plugin_manager::instance()->get_plugin_info($dependpluginname);
                    if (empty($component)) {
                        $available = false;
                        continue;
                    }
                    // Confirm the version.
                    if ($component->versiondisk < $dependpluginversion) {
                        $available = false;
                        continue;
                    }
                }

                $result = !$available ? false : true;

                $cache->set($pluginname, (object) ['dependencyavailable' => $result]);
            }

            // Some condtions not defined the lib.php file, so check the class name.
            // Check the class name and if the class is available then return true.
            $classname = "pulsecondition_$componentname\conditionform";
            if (class_exists($classname)) {
                $cache->set($pluginname, (object) ['dependencyavailable' => true]);
                return true;
            }

        } else if ($componentcache->dependencyavailable == false) {
            // Dependency is not available.
            return false;
        }

        return false;
    }
}
