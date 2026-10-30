<?php
/**
 * EduBot — Upload course PDF materials.
 *
 * A reliable upload page for teachers to add PDF files to their courses.
 * Works independently of Moodle's file picker (which has known bugs).
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/edubot/upload_material.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_edubot') . ' — Upload Course Materials');
$PAGE->set_heading('Upload Course Materials');
$PAGE->set_pagelayout('standard');

// Get courses the user can manage.
$mycourses = enrol_get_all_users_courses($USER->id, true);
$manageable = [];
foreach ($mycourses as $c) {
    $cctx = context_course::instance($c->id);
    if (has_capability('moodle/course:manageactivities', $cctx)) {
        $manageable[$c->id] = $c;
    }
}
// Admin can see all courses.
if (is_siteadmin()) {
    $allcourses = $DB->get_records('course', ['visible' => 1], 'fullname ASC', 'id, fullname, shortname');
    foreach ($allcourses as $c) {
        if ($c->id > 1) { // Skip site course.
            $manageable[$c->id] = $c;
        }
    }
}

// ============ Handle form submission ============
$message = '';
$messagetype = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $courseid = required_param('courseid', PARAM_INT);

    // Validate course.
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        $message = 'Course not found.';
        $messagetype = 'danger';
    } else if (!isset($_FILES['pdffile']) || $_FILES['pdffile']['error'] !== UPLOAD_ERR_OK) {
        $uploaderrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds PHP upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form max size.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk.',
        ];
        $errcode = $_FILES['pdffile']['error'] ?? UPLOAD_ERR_NO_FILE;
        $message = $uploaderrors[$errcode] ?? 'Unknown upload error.';
        $messagetype = 'danger';
    } else {
        $tmppath  = $_FILES['pdffile']['tmp_name'];
        $filename = clean_filename($_FILES['pdffile']['name']);
        $filesize = $_FILES['pdffile']['size'];

        // Validate it's a PDF.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmppath);
        finfo_close($finfo);

        if ($mime !== 'application/pdf') {
            $message = 'Only PDF files are allowed. Got: ' . $mime;
            $messagetype = 'danger';
        } else {
            // Check if resource with same name already exists.
            $resourcename = pathinfo($filename, PATHINFO_FILENAME);
            $existing = $DB->get_record_sql(
                "SELECT r.id FROM {resource} r WHERE r.course = ? AND r.name = ?",
                [$courseid, $resourcename]
            );

            if ($existing) {
                $message = 'A resource named "' . $resourcename . '" already exists in this course.';
                $messagetype = 'warning';
            } else {
                // Create the resource.
                try {
                    $moduleid = $DB->get_field('modules', 'id', ['name' => 'resource']);

                    $resource = new stdClass();
                    $resource->course = $courseid;
                    $resource->name = $resourcename;
                    $resource->intro = '<p>Course material uploaded via EduBot.</p>';
                    $resource->introformat = FORMAT_HTML;
                    $resource->tobemigrated = 0;
                    $resource->legacyfiles = 0;
                    $resource->legacyfileslast = null;
                    $resource->display = 0;
                    $resource->displayoptions = serialize(['printintro' => 0]);
                    $resource->filterfiles = 0;
                    $resource->revision = 1;
                    $resource->timemodified = time();
                    $resource->id = $DB->insert_record('resource', $resource);

                    $cm = new stdClass();
                    $cm->course = $courseid;
                    $cm->module = $moduleid;
                    $cm->modname = 'resource';
                    $cm->instance = $resource->id;
                    $cm->section = 0;
                    $cm->visible = 1;
                    $cm->visibleoncoursepage = 1;
                    $cm->added = time();
                    $cmid = add_course_module($cm);

                    // Add to first section.
                    $section = $DB->get_record('course_sections',
                        ['course' => $courseid, 'section' => 0]);
                    if ($section) {
                        course_add_cm_to_section($course, $cmid, $section->section);
                    }

                    // Store file via Moodle File API.
                    $modcontext = context_module::instance($cmid);
                    $fs = get_file_storage();

                    $filerecord = [
                        'contextid' => $modcontext->id,
                        'component' => 'mod_resource',
                        'filearea'  => 'content',
                        'itemid'    => 0,
                        'filepath'  => '/',
                        'filename'  => $filename,
                        'userid'    => $USER->id,
                        'license'   => $CFG->sitedefaultlicense ?? 'unknown',
                        'author'    => fullname($USER),
                        'source'    => $filename,
                    ];

                    $fs->create_file_from_pathname($filerecord, $tmppath);

                    // Rebuild course cache.
                    rebuild_course_cache($courseid, true);

                    // explicitly queue the background indexing task!
                    $task = new \local_edubot\task\background_indexing_task();
                    $task->set_custom_data([
                        'courseid' => $courseid,
                        'cmid' => $cmid,
                        'is_delete' => false
                    ]);
                    \core\task\manager::queue_adhoc_task($task);

                    $message = '✅ "<strong>' . s($resourcename) . '</strong>" uploaded to <strong>'
                             . format_string($course->fullname) . '</strong>. '
                             . 'Indexing task queued! Run cron.php to process it.';
                    $messagetype = 'success';

                } catch (Exception $e) {
                    $message = 'Error: ' . $e->getMessage();
                    $messagetype = 'danger';
                }
            }
        }
    }
}

// ============ Render page ============
echo $OUTPUT->header();
?>

<div style="max-width: 700px; margin: 0 auto;">
    <div style="background: linear-gradient(135deg, #0f6cbf 0%, #1a7fd4 100%);
                color: #fff; padding: 24px 28px; border-radius: 12px 12px 0 0;">
        <h2 style="margin: 0 0 4px 0; font-size: 22px;">📚 Upload Course Materials</h2>
        <p style="margin: 0; opacity: 0.85; font-size: 14px;">
            Upload PDF files to your courses. EduBot will automatically index them for RAG-based tutoring.
        </p>
    </div>

    <div style="background: #fff; border: 1px solid #e5e7eb; border-top: none;
                border-radius: 0 0 12px 12px; padding: 28px;">

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messagetype; ?>" style="border-radius: 8px;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($manageable)): ?>
            <div class="alert alert-warning">
                You don't have permission to manage any courses. Please contact an administrator.
            </div>
        <?php else: ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                <div style="margin-bottom: 20px;">
                    <label for="courseid" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                        📘 Select Course
                    </label>
                    <select name="courseid" id="courseid" required
                            style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db;
                                   border-radius: 8px; font-size: 14px; background: #fff;">
                        <option value="">-- Choose a course --</option>
                        <?php foreach ($manageable as $c): ?>
                            <option value="<?php echo $c->id; ?>"
                                <?php if (optional_param('courseid', 0, PARAM_INT) == $c->id) echo 'selected'; ?>>
                                <?php echo format_string($c->fullname); ?>
                                (<?php echo $c->shortname; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom: 20px;">
                    <label for="pdffile" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                        📄 Select PDF File
                    </label>
                    <input type="file" name="pdffile" id="pdffile" accept=".pdf,application/pdf" required
                           style="width: 100%; padding: 10px 14px; border: 1px solid #d1d5db;
                                  border-radius: 8px; font-size: 14px; background: #f9fafb;">
                    <small style="color: #6b7280; font-size: 12px; margin-top: 4px; display: block;">
                        PDF files only. Max size: <?php echo ini_get('upload_max_filesize'); ?>
                    </small>
                </div>

                <button type="submit"
                        style="width: 100%; padding: 12px 20px; background: linear-gradient(135deg, #0f6cbf, #1a7fd4);
                               color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600;
                               cursor: pointer; transition: transform 0.15s ease, box-shadow 0.2s ease;">
                    📤 Upload to Course
                </button>
            </form>

            <hr style="margin: 24px 0; border-color: #f3f4f6;">

            <h4 style="font-size: 15px; margin-bottom: 12px;">📋 Currently Indexed Courses</h4>
            <?php
            $indexed = $DB->get_records_sql(
                "SELECT courseid, COUNT(*) as chunks, MAX(created_at) as last_indexed
                   FROM {local_edubot_rag_chunks}
                  GROUP BY courseid
                  ORDER BY last_indexed DESC"
            );
            if (empty($indexed)):
            ?>
                <p style="color: #9ca3af; font-size: 13px;">No courses indexed yet.
                    Upload a PDF and the chatbot will auto-index it.</p>
            <?php else: ?>
                <table class="table table-sm" style="font-size: 13px;">
                    <thead>
                        <tr><th>Course</th><th>Chunks</th><th>Last Indexed</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($indexed as $idx):
                        $cname = $DB->get_field('course', 'fullname', ['id' => $idx->courseid]) ?? 'Unknown';
                    ?>
                        <tr>
                            <td><?php echo format_string($cname); ?></td>
                            <td><?php echo $idx->chunks; ?></td>
                            <td><?php echo userdate($idx->last_indexed, '%d %b %Y %H:%M'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();
