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
 * @package    plagiarism_turnitin
 * @subpackage cli
 * @copyright  2016 Adam Riddell <adamr@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * CLI script to handle resubmission of TII plagiarism enabled assignments that
 * do not have an associated plagiarism_turnitin_files record. This scenario
 * has likely occurred as a result of a bug which has since been fixed by TII:
 *
 * b8b76c2e7195040687bc0f8cd64480a725fe2ca5
 *
 * This script utilises a monstrous query to identify relevant TII-enabled
 * assignments that are missing a plagiarism_turnitin_files record. It then
 * iterates through these file submissions, assembles the relevant data and
 * resubmits them to TII.
 */

define('CLI_SCRIPT', true);

require(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot.'/mod/turnitintooltwo/lib.php');
require_once($CFG->dirroot.'/mod/turnitintooltwo/turnitintooltwo_view.class.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/plagiarism/lib.php');

// Include plugin classes
require_once(dirname(dirname(__FILE__))."/turnitinplugin_view.class.php");
require_once(dirname(dirname(__FILE__)).'/classes/turnitin_class.class.php');
require_once(dirname(dirname(__FILE__)).'/classes/turnitin_submission.class.php');
require_once(dirname(dirname(__FILE__)).'/classes/turnitin_comms.class.php');
require_once(dirname(dirname(__FILE__)).'/classes/digitalreceipt/pp_receipt_message.php');

// Include supported module specific code
require_once(dirname(dirname(__FILE__)).'/classes/modules/turnitin_assign.class.php');
require_once(dirname(dirname(__FILE__)).'/classes/modules/turnitin_forum.class.php');
require_once(dirname(dirname(__FILE__)).'/classes/modules/turnitin_workshop.class.php');

global $DB;

$sql = 'SELECT assub.id, cm.id AS cmid, f.itemid, f.userid, f.pathnamehash, ptc2.value
FROM {assign_submission} assub
JOIN {assign} a ON (assub.assignment = a.id)
LEFT JOIN {assign_grades} ag ON (ag.assignment = a.id AND ag.userid = assub.userid AND ag.attemptnumber = assub.attemptnumber)
JOIN {course_modules} cm ON (a.id = cm.instance)
JOIN {modules} m ON (cm.module = m.id AND m.name = \'assign\')
JOIN {context} c ON (c.instanceid = cm.id AND contextlevel = 70)
JOIN {assignsubmission_file} asf ON (asf.submission = assub.id)
JOIN {files} f ON (f.contextid = c.id AND f.itemid = assub.id AND f.component = \'assignsubmission_file\' AND f.filename != \'.\')
JOIN {user_enrolments} ue ON (ue.userid = f.userid AND ue.status = 0)
JOIN {enrol} e ON (e.id = ue.enrolid AND e.courseid = cm.course)
JOIN {turnitintooltwo_users} tu ON (tu.userid = f.userid AND tu.user_agreement_accepted = 1)
JOIN {plagiarism_turnitin_config} ptc ON (ptc.cm = cm.id AND ptc.name = \'use_turnitin\' AND ptc.value = \'1\')
JOIN {plagiarism_turnitin_config} ptc2 ON (ptc2.cm = cm.id AND ptc2.name = \'turnitin_assignid\')
LEFT JOIN {plagiarism_turnitin_files} ptf ON (ptf.identifier = f.pathnamehash)
WHERE AND assub.status != \'draft\' AND assub.latest = 1 AND ptf.id IS NULL
ORDER BY assub.assignment';

$missing = $DB->get_recordset_sql($sql);
$pp_turnitin = new plagiarism_plugin_turnitin;

foreach($missing as $sub) {
    // Fetch course module object and course data.
    $cm = get_coursemodule_from_id('', $sub->cmid);
    $coursedata = $pp_turnitin->get_course_data($cm->id, $cm->course, 'cron');
    
    // Assemble user data.
    $moduleobject = new turnitin_assign;
    $author = $moduleobject->get_author($sub->itemid);
    $author = (!empty($author)) ? $author : $sub->userid;
    $user = new turnitintooltwo_user($author, 'Learner');
    $user->join_user_to_class($coursedata->turnitin_cid);
    $submitter = $sub->userid;
    
    // Handle the resubmission.
    $pp_turnitin->tii_submission($cm, (int) $sub->value, $user, $submitter,
            $sub->pathnamehash, 'file', $sub->itemid, '', '', '');
}

$missing->close();
