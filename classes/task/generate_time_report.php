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
 * Time Report tool task class.
 *
 * @package   tool_time_report
 * @copyright 2023 Pierre Duverneix - Fondation UNIT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_time_report\task;

require_once(dirname(__FILE__) . '/../../../../../config.php');
require_once(dirname(__FILE__) . '/../../locallib.php');

require_login();

use core\message\message;
use moodle_url;

class generate_time_report extends \core\task\adhoc_task {

    public $totaltime = 0;

    public function set_total_time($totaltime) {
        $this->totaltime = $totaltime;
    }

    public function get_total_time() {
        return $this->totaltime;
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (isset($data)) {
            // Check the dates.
            if (!isset($data->start)) {
                $data->start = time() * 1000;
            }
            if (!isset($data->end)) {
                $data->end = time() * 1000;
            }
            // Convert Javascript timestamp to PHP.
            $startdate = $data->start / 1000;
            $enddate = $data->end / 1000;

            $user = $DB->get_record('user', array('id' => $data->userid), '*', MUST_EXIST);
            $results = get_log_records($user->id, $startdate, $enddate);
            $csvdata = $this->prepare_results($results);
            $this->create_csv($user, $data->requestorid, $csvdata, $data->contextid, $startdate, $enddate);
        }
    }

    private static function format_seconds($seconds) {
        $hours = 0;
        $milliseconds = str_replace('0.', '', $seconds - floor($seconds));
        if ($seconds > 3600) {
            $hours = floor($seconds / 3600);
        }
        $seconds = $seconds % 3600;
        return str_pad($hours, 2, '0', STR_PAD_LEFT)
            . date(':i:s', $seconds)
            . ($milliseconds ? $milliseconds : '');
    }

    private function prepare_results($data) {
        if (!array_values($data)) {
            return '<h5>' . get_string('no_results_found', 'tool_time_report') . '</h5>';
        }

        $idletime = get_config('tool_time_report', 'idletime') / MINSECS;
        $borrowedtime = get_config('tool_time_report', 'borrowedtime') * 1;
        $currentday = array_values($data)[0];
        $timefortheday = 0;
        $i = 0;
        $length = count($data);

        $out = array();
        $totaltime = 0;

        for ($i; $i < $length; $i++) {
            $item = array_values($data)[$i];
            $nextval = self::get_nextval($data, $i);

            // Last iteration.
            if ($item->id == $nextval->id) {
                $totaltime = $totaltime + $timefortheday;
                $out = self::push_result($out, $item->timecreated, $timefortheday);
                break;
            }

            // If the item log time is different than the current day time, we move forward.
            if ($item->logtimecreated != $currentday->logtimecreated) {
                $currentday = $item;
                $timefortheday = 0;
            }

            if (isset($nextval) && $nextval->logtimecreated == $currentday->logtimecreated) {
                $nextvaltimecreated = intval($nextval->timecreated);
                $itemtimecreated = intval($item->timecreated);
                $timedelta = $nextvaltimecreated - $itemtimecreated;

                if (intval($timedelta / MINSECS) > $idletime) {
                    $timefortheday = $timefortheday + $borrowedtime;
                } else {
                    $tmpdaytime = $timefortheday + $nextvaltimecreated - $itemtimecreated;
                    if ($tmpdaytime < intval($timefortheday + $idletime)) {
                        continue;
                    } else {
                        $timefortheday = $tmpdaytime;
                    }
                }
            } else if ($nextval->logtimecreated != $currentday->logtimecreated) {
                // Last iteration of the day.
                $timefortheday = $timefortheday + $borrowedtime;
            }

            if (($timefortheday > 0 && isset($nextval) && $nextval->logtimecreated != $currentday->logtimecreated)
                || ($timefortheday > 0 && $nextval == $item)
            ) {
                $totaltime = $totaltime + $timefortheday;
                $out = self::push_result($out, $item->timecreated, $timefortheday);
            }
        }

        $this->set_total_time($totaltime);
        return $out;
    }

    /**
     * Get the next item of the array of report results.
     */
    private static function get_nextval($data, $iteration) {
        $item = array_values($data)[$iteration];
        if (!isset(array_values($data)[$iteration + 1])) {
            return $item;
        }
        return array_values($data)[$iteration + 1];
    }

    private static function push_result($items, $itemtimecreated, $timefortheday) {
        $date = date('d/m/Y', $itemtimecreated);
        $seconds = self::format_seconds($timefortheday);
        array_push($items, array($date, $seconds));
        return $items;
    }

    private function create_csv($user, $requestorid, $data, $contextid, $startdate, $enddate) {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');
        require_once(dirname(__FILE__) . '/../../locallib.php');

        $strstartdate = date('d-m-Y', $startdate);
        $strenddate = date('d-m-Y', $enddate);

        $delimiter = \csv_import_reader::get_delimiter('comma');
        $csventries = array(array());
        $csventries[] = array(get_string('name', 'core'), $user->lastname);
        $csventries[] = array(get_string('firstname', 'core'), $user->firstname);
        $csventries[] = array(get_string('email', 'core'), $user->email);
        $csventries[] = array(get_string('period', 'tool_time_report'), $strstartdate . ' - ' . $strenddate);
        $csventries[] = array(get_string('period_total_time', 'tool_time_report'), self::format_seconds($this->get_total_time()));
        $csventries[] = array('Date', get_string('total_duration', 'tool_time_report'));

        $returnstr = '';
        $len = count($data);
        $shift = count($csventries);

        for ($i = 0; $i < $len; $i++) {
            $csventries[$i + $shift] = $data[$i];
        }
        foreach ($csventries as $entry) {
            $returnstr .= '"' . implode('"' . $delimiter . '"', $entry) . '"' . "\n";
        }

        $filename = generate_file_name(fullname($user), $strstartdate, $strenddate);

        return $this->write_new_file($returnstr, $contextid, $filename, $user, $requestorid);
    }

    private function write_new_file($content, $contextid, $filename, $user, $requestorid) {
        global $CFG;

        $fs = get_file_storage();
        $fileinfo = array(
            'contextid' => $contextid,
            'component' => 'tool_time_report',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $user->id
        );

        $file = $fs->get_file(
            $fileinfo['contextid'],
            $fileinfo['component'],
            $fileinfo['filearea'],
            $fileinfo['itemid'],
            $fileinfo['filepath'],
            $fileinfo['filename']
        );

        if ($file) {
            $file->delete(); // Delete the old file first.
        }

        if ($fs->create_file_from_string($fileinfo, $content)) {
            $path = "$CFG->wwwroot/pluginfile.php/$contextid/tool_time_report/content/0/$filename";
            $this->generate_message($user, $path, $filename, $file, $requestorid);
        }

        return $file;
    }

    public function generate_message($user, $path, $filename, $file, $requestorid) {
        $fullname = fullname($user);
        $messagehtml = "<p>" . get_string('download', 'core') . " : ";
        $messagehtml .= "<a href=\"$path\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
        $contexturl = new moodle_url('/admin/tool/time_report/view.php', array('userid' => $user->id));

        $message = new message();
        $message->component         = 'tool_time_report';
        $message->name              = 'reportcreation';
        $message->userfrom          = \core_user::get_noreply_user();
        $message->userto            = $requestorid;
        $message->subject           = get_string('messageprovider:reportcreation', 'tool_time_report') . " : " . $fullname;
        $message->fullmessageformat = FORMAT_HTML;
        $message->fullmessage       = html_to_text($messagehtml);
        $message->fullmessagehtml   = $messagehtml;
        $message->smallmessage      = get_string('messageprovider:report_created', 'tool_time_report');
        $message->notification      = 1;
        $message->contexturl        = $contexturl;
        $message->contexturlname    = get_string('time_report', 'tool_time_report');
        $message->attachment = $file; // Set the file attachment.
        message_send($message);
    }
}
