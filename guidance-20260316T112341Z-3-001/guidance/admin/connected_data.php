<?php
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

function guidance_connected_date_label(?string $value): string
{
    if (!$value) {
        return 'No timestamp';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y h:i A', $time) : $value;
}

function guidance_connected_status_class(?string $value): string
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return 'pending';
    }

    return str_replace([' ', '/'], ['_', '_'], $value);
}

function guidance_connected_subject_count_label(array $row): string
{
    $count = isset($row['subject_count']) ? (int) $row['subject_count'] : 0;
    return $count > 0 ? $count . ' enrolled class' . ($count === 1 ? '' : 'es') : 'No class load yet';
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['receive_registrar_student'])) {
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $payload = [
            'student_id' => trim((string) ($_POST['student_id'] ?? '')),
            'name' => $studentName,
            'student_name' => $studentName,
            'course' => trim((string) ($_POST['course'] ?? '')),
            'year_level' => trim((string) ($_POST['year_level'] ?? '')),
            'section_name' => trim((string) ($_POST['section_name'] ?? '')),
            'enrollment_status' => trim((string) ($_POST['enrollment_status'] ?? 'Enrolled')),
            'subject_load' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['subject_load'] ?? ''))))),
            'source_record_id' => trim((string) ($_POST['source_record_id'] ?? '')),
        ];

        $received = guidance_receive_shared_department_event($conn, 'registrar', 'student_profile_sync', [
            'flow_type' => 'Student Profile Sync',
            'route_key' => 'registrar_to_guidance_student_profile_sync',
            'reference_table' => 'registrar_students',
            'reference_id' => $payload['source_record_id'],
            'student_id' => $payload['student_id'],
            'student_name' => $studentName,
            'payload_summary' => 'Registrar profile received for Guidance student intake.',
            'payload_json' => $payload,
        ]);

        if ($received) {
            $message = $studentName . ' was received from Registrar and synced into the Guidance registry.';
        } else {
            $message = 'Guidance could not receive the selected Registrar record. Check the shared Supabase schema and bridge views.';
            $messageType = 'error';
        }
    } elseif (isset($_POST['receive_prefect_incident'])) {
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $summary = trim($title . ($description !== '' ? ' - ' . $description : ''));
        $sourceRecordId = trim((string) ($_POST['source_record_id'] ?? ''));

        $payload = [
            'student_id' => trim((string) ($_POST['student_id'] ?? '')),
            'student_name' => $studentName !== '' ? $studentName : 'Student from Prefect',
            'summary' => $summary !== '' ? $summary : 'Prefect incident endorsed to Guidance.',
            'incident' => $summary !== '' ? $summary : 'Prefect incident endorsed to Guidance.',
            'recommended_action' => 'Create a counseling-based behavior intervention and coordinate with Prefect.',
            'severity_level' => trim((string) ($_POST['severity'] ?? 'medium')),
            'priority_level' => trim((string) ($_POST['severity'] ?? 'medium')),
            'case_reference' => $sourceRecordId !== '' ? 'PREF-' . preg_replace('/[^A-Za-z0-9]/', '', $sourceRecordId) : '',
            'source_record_id' => $sourceRecordId,
        ];

        $received = guidance_receive_shared_department_event($conn, 'prefect', 'offense_report', [
            'flow_type' => 'Offense Report',
            'route_key' => 'prefect_to_guidance_offense_report',
            'reference_table' => 'prefect_incident_reports',
            'reference_id' => $sourceRecordId,
            'student_id' => $payload['student_id'],
            'student_name' => $payload['student_name'],
            'payload_summary' => 'Prefect offense report received for Guidance review.',
            'payload_json' => $payload,
        ]);

        if ($received) {
            $message = ($title !== '' ? $title : 'The selected Prefect incident') . ' was received and opened as a Guidance behavior record.';
        } else {
            $message = 'Guidance could not receive the selected Prefect incident. Confirm the shared Prefect tables are available in Supabase.';
            $messageType = 'error';
        }
    } elseif (isset($_POST['receive_pmed_monitoring'])) {
        $studentName = trim((string) ($_POST['student_name'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $followUpAt = trim((string) ($_POST['follow_up_at'] ?? ''));
        $caseReference = trim((string) ($_POST['case_reference'] ?? ''));
        $sourceRecordId = trim((string) ($_POST['source_record_id'] ?? ''));

        $payload = [
            'student_id' => trim((string) ($_POST['student_id'] ?? '')),
            'student_name' => $studentName,
            'summary' => $summary !== '' ? $summary : 'PMED monitoring summary received for Guidance follow-up.',
            'issue' => $summary !== '' ? $summary : 'PMED monitoring summary received for Guidance follow-up.',
            'recommended_action' => $followUpAt !== '' ? 'Schedule Guidance follow-up before ' . $followUpAt . '.' : 'Continue wellness monitoring with PMED.',
            'severity_level' => trim((string) ($_POST['risk_level'] ?? 'medium')),
            'priority_level' => trim((string) ($_POST['risk_level'] ?? 'medium')),
            'case_reference' => $caseReference !== '' ? 'PMED-' . preg_replace('/[^A-Za-z0-9-]/', '', $caseReference) : '',
            'source_record_id' => $sourceRecordId,
        ];

        $received = guidance_receive_shared_department_event($conn, 'pmed', 'monitoring_summary', [
            'flow_type' => 'Monitoring Summary',
            'route_key' => 'pmed_to_guidance_monitoring_summary',
            'reference_table' => 'pmed_mental_health_sessions',
            'reference_id' => $sourceRecordId,
            'student_id' => $payload['student_id'],
            'student_name' => $studentName,
            'payload_summary' => 'PMED monitoring summary received for Guidance wellness monitoring.',
            'payload_json' => $payload,
        ]);

        if ($received) {
            $message = ($studentName !== '' ? $studentName : 'The selected PMED record') . ' was received and logged as a Guidance wellness record.';
        } else {
            $message = 'Guidance could not receive the selected PMED monitoring record. Confirm the shared PMED views are available in Supabase.';
            $messageType = 'error';
        }
    }
}

$registrarDataset = guidance_fetch_registrar_directory($conn, 40);
$prefectDataset = guidance_fetch_prefect_incidents($conn, 20);
$pmedDataset = guidance_fetch_pmed_monitoring_sessions($conn, 20);

$registrarRows = $registrarDataset['rows'];
$prefectRows = $prefectDataset['rows'];
$pmedRows = $pmedDataset['rows'];
$guidanceRegistryCount = count(guidance_fetch_students($conn));
$receivedFlowResult = $conn->query("SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance'");
$receivedFlowCount = $receivedFlowResult ? $receivedFlowResult->num_rows : 0;

guidance_render_shell_start(
    'Connected Data',
    'Shared Department Data Feed',
    'Fetch live student, behavior, and wellness records from Registrar, Prefect, and PMED through the shared Supabase database, then receive them into Guidance with end-to-end integration logs instead of relying on Guidance-only seed data.',
    [
        ['label' => 'Registrar Feed', 'value' => count($registrarRows), 'note' => 'Student master records available from the shared Registrar source.'],
        ['label' => 'Prefect Feed', 'value' => count($prefectRows), 'note' => 'Behavior and discipline items Guidance can receive from Prefect.'],
        ['label' => 'PMED Feed', 'value' => count($pmedRows), 'note' => 'Wellness and monitoring summaries Guidance can receive from PMED.'],
        ['label' => 'Received Flows', 'value' => $receivedFlowCount, 'note' => 'Inbound integration events already logged for Guidance.'],
    ],
    [
        ['label' => 'Open Student Registry', 'href' => 'students.php', 'class' => 'btn-primary'],
        ['label' => 'Open Integration Hub', 'href' => 'integration.php', 'class' => 'btn-secondary'],
    ],
    ['Registrar Source Feed', 'Prefect Incident Feed', 'PMED Monitoring Feed', 'Receive Into Guidance', 'Track In Integration Hub']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<div class="split-layout">
    <section class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h2>Shared Supabase Intake</h2>
                <p>This page reads live department data from shared schemas or public bridge views, then records each receive action in the Guidance integration flow table.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">1. Read live department data</div>
                <div class="mini-list-note">Guidance reads Registrar, Prefect, and PMED records from the shared Supabase project instead of depending on local seed rows.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">2. Receive selected records</div>
                <div class="mini-list-note">Each action creates shared inbound and outbound integration entries so the receive process is visible end to end.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">3. Apply into Guidance</div>
                <div class="mini-list-note">Student profiles sync into the Guidance registry, while Prefect and PMED records become Guidance monitoring or behavior records.</div>
            </article>
        </div>
    </section>

    <aside class="insight-panel">
        <div class="panel-heading">
            <div>
                <h3>Current Reach</h3>
                <p>The live data sources below are what Guidance currently fetches from connected departments.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">Registrar</div>
                <div class="mini-list-note">Source: <?php echo guidance_escape($registrarDataset['source_label']); ?><br>Imported students in Guidance: <?php echo guidance_escape((string) $guidanceRegistryCount); ?></div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Prefect Management</div>
                <div class="mini-list-note">Source: <?php echo guidance_escape($prefectDataset['source_label']); ?><br>Receive path: Prefect incident to Guidance behavior record.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">PMED</div>
                <div class="mini-list-note">Source: <?php echo guidance_escape($pmedDataset['source_label']); ?><br>Receive path: PMED monitoring summary to Guidance wellness record.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Registrar Student Master Feed</h2>
            <p>These student profiles are fetched from the shared Registrar source. Receiving one will sync it into the Guidance registry and record the inbound flow.</p>
        </div>
        <div class="badge-row">
            <span><?php echo guidance_escape($registrarDataset['source_label']); ?></span>
            <span>Shared Supabase</span>
        </div>
    </div>

    <?php if (!$registrarDataset['is_available']): ?>
        <div class="empty-state">Registrar shared tables were not found. Run the Registrar schema and bridge views in Supabase first.</div>
    <?php elseif (!$registrarRows): ?>
        <div class="empty-state">No Registrar student records were found in the shared database yet.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Student ID</th>
                    <th>Name</th>
                    <th>Program / Year</th>
                    <th>Enrollment</th>
                    <th>Academic Load</th>
                    <th>Source Updated</th>
                    <th>Receive</th>
                </tr>
                <?php foreach ($registrarRows as $row): ?>
                    <tr>
                        <td><?php echo guidance_escape($row['student_id'] ?? ''); ?></td>
                        <td><?php echo guidance_escape($row['student_name'] ?? ''); ?></td>
                        <td><?php echo guidance_escape(trim(($row['course'] ?? '-') . ' / ' . ($row['year_level'] ?? '-'))); ?></td>
                        <td><span class="status-pill modern <?php echo guidance_connected_status_class($row['enrollment_status'] ?? 'pending'); ?>"><?php echo guidance_escape($row['enrollment_status'] ?? 'Unknown'); ?></span></td>
                        <td>
                            <div class="mini-list-note"><?php echo guidance_escape(guidance_connected_subject_count_label($row)); ?></div>
                            <div class="integration-note"><?php echo guidance_escape($row['subject_load'] !== '' ? $row['subject_load'] : 'No subjects published from Registrar.'); ?></div>
                        </td>
                        <td><?php echo guidance_escape(guidance_connected_date_label($row['source_updated_at'] ?? null)); ?></td>
                        <td>
                            <form method="POST" class="compact-action-form">
                                <input type="hidden" name="source_record_id" value="<?php echo guidance_escape($row['source_record_id'] ?? ''); ?>">
                                <input type="hidden" name="student_id" value="<?php echo guidance_escape($row['student_id'] ?? ''); ?>">
                                <input type="hidden" name="student_name" value="<?php echo guidance_escape($row['student_name'] ?? ''); ?>">
                                <input type="hidden" name="course" value="<?php echo guidance_escape($row['course'] ?? ''); ?>">
                                <input type="hidden" name="year_level" value="<?php echo guidance_escape($row['year_level'] ?? ''); ?>">
                                <input type="hidden" name="section_name" value="">
                                <input type="hidden" name="enrollment_status" value="<?php echo guidance_escape($row['enrollment_status'] ?? 'Enrolled'); ?>">
                                <input type="hidden" name="subject_load" value="<?php echo guidance_escape($row['subject_load'] ?? ''); ?>">
                                <button type="submit" name="receive_registrar_student">Receive Into Guidance</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Prefect Incident Feed</h2>
            <p>These discipline and offense items come from Prefect. Receiving one creates an inbound Guidance behavior record and acknowledges the shared flow.</p>
        </div>
        <div class="badge-row">
            <span><?php echo guidance_escape($prefectDataset['source_label']); ?></span>
            <span>Behavior Receive Path</span>
        </div>
    </div>

    <?php if (!$prefectDataset['is_available']): ?>
        <div class="empty-state">Prefect shared tables were not found. Run the Prefect schema and bridge views in Supabase first.</div>
    <?php elseif (!$prefectRows): ?>
        <div class="empty-state">No Prefect incident reports were found in the shared database yet.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Incident</th>
                    <th>Student</th>
                    <th>Severity</th>
                    <th>Location / Date</th>
                    <th>Status</th>
                    <th>Receive</th>
                </tr>
                <?php foreach ($prefectRows as $row): ?>
                    <tr>
                        <td>
                            <div class="mini-list-title"><?php echo guidance_escape($row['title'] ?? 'Untitled incident'); ?></div>
                            <div class="integration-note"><?php echo guidance_escape($row['description'] ?? ''); ?></div>
                        </td>
                        <td><?php echo guidance_escape(($row['student_name'] ?? '') !== '' ? $row['student_name'] : ($row['student_id'] ?? 'Unknown student')); ?></td>
                        <td><span class="status-pill modern <?php echo guidance_connected_status_class($row['severity'] ?? 'medium'); ?>"><?php echo guidance_escape(ucfirst((string) ($row['severity'] ?? 'medium'))); ?></span></td>
                        <td>
                            <div class="mini-list-note"><?php echo guidance_escape($row['location'] ?: 'No location'); ?></div>
                            <div class="integration-note"><?php echo guidance_escape(guidance_connected_date_label($row['incident_date'] ?? null)); ?></div>
                        </td>
                        <td><span class="status-pill modern <?php echo guidance_connected_status_class($row['incident_status'] ?? 'received'); ?>"><?php echo guidance_escape($row['incident_status'] ?? 'Open'); ?></span></td>
                        <td>
                            <form method="POST" class="compact-action-form">
                                <input type="hidden" name="source_record_id" value="<?php echo guidance_escape($row['source_record_id'] ?? ''); ?>">
                                <input type="hidden" name="student_id" value="<?php echo guidance_escape($row['student_id'] ?? ''); ?>">
                                <input type="hidden" name="student_name" value="<?php echo guidance_escape($row['student_name'] ?? ''); ?>">
                                <input type="hidden" name="title" value="<?php echo guidance_escape($row['title'] ?? ''); ?>">
                                <input type="hidden" name="description" value="<?php echo guidance_escape($row['description'] ?? ''); ?>">
                                <input type="hidden" name="severity" value="<?php echo guidance_escape($row['severity'] ?? 'medium'); ?>">
                                <button type="submit" name="receive_prefect_incident">Receive Incident</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>PMED Monitoring Feed</h2>
            <p>These monitoring and wellness sessions come from PMED. Receiving one creates a Guidance wellness record tied to the shared PMED summary.</p>
        </div>
        <div class="badge-row">
            <span><?php echo guidance_escape($pmedDataset['source_label']); ?></span>
            <span>Wellness Receive Path</span>
        </div>
    </div>

    <?php if (!$pmedDataset['is_available']): ?>
        <div class="empty-state">PMED shared views were not found. Run the PMED schema and bridge views in Supabase first.</div>
    <?php elseif (!$pmedRows): ?>
        <div class="empty-state">No PMED monitoring sessions were found in the shared database yet.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Case</th>
                    <th>Student</th>
                    <th>Risk / Status</th>
                    <th>Follow-up</th>
                    <th>Summary</th>
                    <th>Receive</th>
                </tr>
                <?php foreach ($pmedRows as $row): ?>
                    <tr>
                        <td>
                            <div class="mini-list-title"><?php echo guidance_escape($row['case_reference'] ?? 'PMED session'); ?></div>
                            <div class="integration-note"><?php echo guidance_escape(($row['session_type'] ?? 'Monitoring') . ' / ' . ($row['counselor'] ?? 'PMED staff')); ?></div>
                        </td>
                        <td><?php echo guidance_escape(($row['student_name'] ?? '') !== '' ? $row['student_name'] : ($row['student_id'] ?? 'Unknown student')); ?></td>
                        <td>
                            <div><span class="status-pill modern <?php echo guidance_connected_status_class($row['risk_level'] ?? 'medium'); ?>"><?php echo guidance_escape(ucfirst((string) ($row['risk_level'] ?? 'medium'))); ?></span></div>
                            <div class="integration-note"><?php echo guidance_escape($row['monitoring_status'] ?? ($row['session_status'] ?? 'Active')); ?></div>
                        </td>
                        <td><?php echo guidance_escape(guidance_connected_date_label($row['follow_up_at'] ?? null)); ?></td>
                        <td><?php echo guidance_escape($row['summary'] !== '' ? $row['summary'] : 'No PMED session outcome was provided.'); ?></td>
                        <td>
                            <form method="POST" class="compact-action-form">
                                <input type="hidden" name="source_record_id" value="<?php echo guidance_escape($row['source_record_id'] ?? ''); ?>">
                                <input type="hidden" name="case_reference" value="<?php echo guidance_escape($row['case_reference'] ?? ''); ?>">
                                <input type="hidden" name="student_id" value="<?php echo guidance_escape($row['student_id'] ?? ''); ?>">
                                <input type="hidden" name="student_name" value="<?php echo guidance_escape($row['student_name'] ?? ''); ?>">
                                <input type="hidden" name="risk_level" value="<?php echo guidance_escape($row['risk_level'] ?? 'medium'); ?>">
                                <input type="hidden" name="follow_up_at" value="<?php echo guidance_escape($row['follow_up_at'] ?? ''); ?>">
                                <input type="hidden" name="summary" value="<?php echo guidance_escape($row['summary'] ?? ''); ?>">
                                <button type="submit" name="receive_pmed_monitoring">Receive Monitoring</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php guidance_render_shell_end(); ?>
