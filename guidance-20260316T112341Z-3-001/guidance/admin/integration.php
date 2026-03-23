<?php
session_start();
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$departments = guidance_integration_departments();
$message = '';
$messageType = 'success';
$studentIdFilter = trim((string) ($_GET['student_id_filter'] ?? ''));
$statusFilter = trim((string) ($_GET['status_filter'] ?? 'all'));
$sentReportFilter = trim((string) ($_GET['sent_report_filter'] ?? 'all'));

function guidance_decode_json_payload($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function guidance_count_result($conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}

function guidance_is_prefect_report_locked(array $event): bool
{
    $payload = guidance_decode_json_payload((string) ($event['payload_json'] ?? ''));
    $response = guidance_decode_json_payload((string) ($event['response_payload'] ?? ''));
    $reviewStatus = strtolower(trim((string) ($payload['review_status'] ?? $response['review_status'] ?? '')));
    $forwardedTo = strtolower(trim((string) ($response['forwarded_to'] ?? '')));

    return in_array($reviewStatus, ['completed', 'cleared'], true) || $forwardedTo === 'pmed';
}

if (isset($_POST['mark_prefect_read'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $eventResult = $conn->query("SELECT * FROM integration_flows WHERE id={$id} AND direction='INBOUND' AND target_department='guidance' AND source_department='prefect' ORDER BY id DESC LIMIT 1");
    $event = (is_object($eventResult) && method_exists($eventResult, 'fetch_assoc')) ? $eventResult->fetch_assoc() : null;
    if (!$event) {
        $message = 'Selected Prefect report was not found.';
        $messageType = 'error';
    } elseif (guidance_is_prefect_report_locked($event)) {
        $message = 'This report is completed/cleared and is now view-only.';
        $messageType = 'error';
    } else {
    $responsePayload = guidance_integration_quote(json_encode([
        'read_by' => 'guidance',
        'read_at' => date(DATE_ATOM),
        'action' => 'mark_as_read',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $ok = $conn->query("UPDATE integration_flows SET status='Acknowledged', acknowledged_at=NOW(), response_payload={$responsePayload}::jsonb, last_error=NULL, updated_at=NOW() WHERE id={$id} AND direction='INBOUND' AND target_department='guidance' AND source_department='prefect'");
    if ($ok) {
        $message = 'Prefect report marked as read.';
    } else {
        $message = 'Unable to mark Prefect report as read.';
        $messageType = 'error';
    }
    }
}

if (isset($_POST['send_prefect_to_pmed'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $eventResult = $conn->query("SELECT * FROM integration_flows WHERE id={$id} AND direction='INBOUND' AND target_department='guidance' AND source_department='prefect' ORDER BY id DESC LIMIT 1");
    $event = (is_object($eventResult) && method_exists($eventResult, 'fetch_assoc')) ? $eventResult->fetch_assoc() : null;

    if (!$event) {
        $message = 'Selected Prefect report was not found.';
        $messageType = 'error';
    } elseif (guidance_is_prefect_report_locked($event)) {
        $message = 'This report is completed/cleared and is now view-only.';
        $messageType = 'error';
    } else {
        $payload = guidance_decode_json_payload((string) ($event['payload_json'] ?? ''));
        $reviewDecision = trim((string) ($_POST['review_decision'] ?? 'for_pmed'));
        if (!in_array($reviewDecision, ['for_pmed', 'monitoring_only', 'needs_follow_up'], true)) {
            $reviewDecision = 'for_pmed';
        }
        $reviewStatus = trim((string) ($_POST['review_status'] ?? 'pending'));
        $reviewStatusMap = [
            'pending' => 'Pending',
            'completed' => 'Completed',
            'has_record' => 'Has Record',
            'cleared' => 'Cleared',
        ];
        if (!array_key_exists($reviewStatus, $reviewStatusMap)) {
            $reviewStatus = 'pending';
        }
        $reviewNotes = trim((string) ($_POST['review_notes'] ?? ''));
        $reviewActionTaken = trim((string) ($_POST['guidance_action_taken'] ?? ''));
        $studentId = trim((string) ($event['student_id'] ?? $payload['student_id'] ?? ''));
        $studentName = trim((string) ($event['student_name'] ?? $payload['student_name'] ?? ''));
        $concern = trim((string) ($payload['concern'] ?? $payload['summary'] ?? $payload['incident'] ?? $event['payload_summary'] ?? ''));
        $actionTaken = $reviewActionTaken !== ''
            ? $reviewActionTaken
            : trim((string) ($payload['action_taken'] ?? $payload['recommended_action'] ?? 'Guidance reviewed Prefect report and forwarded to PMED.'));
        $reportStatus = $reviewStatusMap[$reviewStatus];
        $complaints = trim((string) ($payload['complaints_behavior_records'] ?? $payload['summary'] ?? $concern));

        $queued = guidance_integration_queue_outbound($conn, 'pmed', 'incident_monitoring_update', [
            'route_key' => 'guidance_to_pmed_incident_monitoring_update',
            'reference_table' => 'integration_flows',
            'reference_id' => (string) $id,
            'student_id' => $studentId !== '' ? $studentId : null,
            'student_name' => $studentName !== '' ? $studentName : null,
            'payload_summary' => $concern,
            'payload_json' => [
                'student_id' => $studentId !== '' ? $studentId : null,
                'student_name' => $studentName !== '' ? $studentName : null,
                'concern' => $concern !== '' ? $concern : null,
                'action_taken' => $actionTaken,
                'status' => $reportStatus,
                'review_status' => $reviewStatus,
                'complaints_behavior_records' => $complaints,
                'review_decision' => $reviewDecision,
                'review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
                'source_department' => 'prefect',
                'via_department' => 'guidance',
                'source_inbound_event_id' => $id,
            ],
        ]);

        if ($queued) {
            $successPayload = guidance_integration_quote(json_encode([
                'success_report' => true,
                'archive_status' => 'Active',
                'sent_to' => 'pmed',
                'sent_at' => date(DATE_ATOM),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $conn->query("UPDATE integration_flows SET status='Sent', sent_at=NOW(), response_payload=COALESCE(response_payload, '{}'::jsonb) || {$successPayload}::jsonb, updated_at=NOW() WHERE id = (
                SELECT id FROM integration_flows
                WHERE direction='OUTBOUND'
                  AND source_department='guidance'
                  AND target_department='pmed'
                  AND source_record_id={$id}
                ORDER BY id DESC
                LIMIT 1
            )");
            $responsePayload = guidance_integration_quote(json_encode([
                'reviewed_by' => 'guidance',
                'reviewed_at' => date(DATE_ATOM),
                'review_decision' => $reviewDecision,
                'review_status' => $reviewStatus,
                'status' => $reportStatus,
                'action_taken' => $actionTaken,
                'review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
                'forwarded_to' => 'pmed',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $conn->query("UPDATE integration_flows SET status='Acknowledged', acknowledged_at=NOW(), response_payload={$responsePayload}::jsonb, last_error=NULL, updated_at=NOW() WHERE id={$id} AND direction='INBOUND' AND target_department='guidance' AND source_department='prefect'");
            $message = 'Prefect report was queued for PMED delivery with status: ' . $reportStatus . '.';
        } else {
            $message = 'Unable to queue Prefect report for PMED. Check route/event configuration.';
            $messageType = 'error';
        }
    }
}

if (isset($_POST['archive_report'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $archivePayload = guidance_integration_quote(json_encode([
        'archive_status' => 'Archived',
        'archived_at' => date(DATE_ATOM),
        'archived_by' => 'guidance',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $ok = $conn->query("UPDATE integration_flows
        SET response_payload=COALESCE(response_payload, '{}'::jsonb) || {$archivePayload}::jsonb,
            updated_at=NOW()
        WHERE id={$id}
          AND direction='OUTBOUND'
          AND source_department='guidance'");
    if ($ok) {
        $message = 'Report archived successfully.';
    } else {
        $message = 'Unable to archive report.';
        $messageType = 'error';
    }
}

if (isset($_POST['sync_registrar_student'])) {
    $sourceRecordId = trim((string) ($_POST['source_record_id'] ?? ''));
    $match = guidance_fetch_registrar_student_by_source_record_id($conn, $sourceRecordId);

    if (!$match) {
        $message = 'Registrar student record was not found.';
        $messageType = 'error';
    } else {
        $synced = guidance_receive_student_profile_from_registrar($conn, [
            'student_id' => (string) ($match['student_id'] ?? ''),
            'name' => (string) ($match['student_name'] ?? ''),
            'course' => (string) ($match['course'] ?? ''),
            'year_level' => (string) ($match['year_level'] ?? ''),
            'section_name' => '',
            'enrollment_status' => (string) ($match['enrollment_status'] ?? 'Active'),
            'subject_load' => (string) ($match['subject_load'] ?? ''),
        ]);

        if ($synced) {
            $message = 'Student information was fetched from Registrar and synced to Guidance.';
        } else {
            $message = 'Unable to sync the selected Registrar student record.';
            $messageType = 'error';
        }
    }
}

if (isset($_POST['request_hr_employee'])) {
    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $studentName = trim((string) ($_POST['student_name'] ?? ''));
    $requestedPosition = trim((string) ($_POST['requested_position'] ?? ''));
    $requestReason = trim((string) ($_POST['request_reason'] ?? ''));
    $preferredEmployeeId = trim((string) ($_POST['preferred_employee_id'] ?? ''));
    $preferredEmployeeName = trim((string) ($_POST['preferred_employee_name'] ?? ''));

    $summary = trim(implode(' | ', array_filter([
        $studentId !== '' ? ('Student ID: ' . $studentId) : '',
        $studentName !== '' ? ('Name: ' . $studentName) : '',
        $requestedPosition !== '' ? ('Requested Role: ' . $requestedPosition) : '',
        $requestReason !== '' ? ('Reason: ' . $requestReason) : '',
    ])));

    $queued = guidance_integration_queue_outbound($conn, 'hr', 'employee_support_request', [
        'reference_table' => 'guidance',
        'reference_id' => null,
        'student_id' => $studentId !== '' ? $studentId : null,
        'student_name' => $studentName !== '' ? $studentName : null,
        'payload_summary' => $summary !== '' ? $summary : 'Employee support request from Guidance.',
        'payload_json' => [
            'student_id' => $studentId !== '' ? $studentId : null,
            'student_name' => $studentName !== '' ? $studentName : null,
            'requested_position' => $requestedPosition !== '' ? $requestedPosition : null,
            'request_reason' => $requestReason !== '' ? $requestReason : null,
            'preferred_employee_id' => $preferredEmployeeId !== '' ? $preferredEmployeeId : null,
            'preferred_employee_name' => $preferredEmployeeName !== '' ? $preferredEmployeeName : null,
            'source_department' => 'guidance',
        ],
    ]);

    if ($queued) {
        $message = 'Employee request was sent to HR successfully.';
    } else {
        $message = 'Unable to send employee request to HR.';
        $messageType = 'error';
    }
}

if (isset($_POST['mark_sent'])) {
    $id = (int) $_POST['id'];
    $conn->query("UPDATE integration_flows SET status='Sent', sent_at=NOW(), last_error=NULL, updated_at=NOW() WHERE id=$id AND direction='OUTBOUND'");
    $message = 'Outbound message marked as sent.';
}

if (isset($_POST['mark_failed'])) {
    $id = (int) $_POST['id'];
    $errorNote = trim($_POST['last_error'] ?? '');
    $conn->query("UPDATE integration_flows SET status='Failed', last_error=" . guidance_integration_quote($errorNote !== '' ? $errorNote : 'Manual failure mark') . ", updated_at=NOW() WHERE id=$id AND direction='OUTBOUND'");
    $message = 'Outbound message marked as failed.';
}

if (isset($_POST['acknowledge'])) {
    $id = (int) $_POST['id'];
    if (guidance_apply_inbound_event($conn, $id)) {
        $message = 'Inbound event applied to Guidance and acknowledged.';
    } else {
        $conn->query("UPDATE integration_flows SET status='Acknowledged', acknowledged_at=NOW(), updated_at=NOW() WHERE id=$id AND direction='INBOUND' AND target_department='guidance'");
        $message = 'Inbound message acknowledged.';
    }
}

$viewEventId = isset($_GET['view']) ? (int) $_GET['view'] : 0;
$viewEvent = null;
if ($viewEventId > 0) {
    $viewResult = $conn->query("SELECT * FROM integration_flows WHERE id={$viewEventId} ORDER BY id DESC LIMIT 1");
    if (is_object($viewResult) && method_exists($viewResult, 'fetch_assoc')) {
        $viewEvent = $viewResult->fetch_assoc();
    }
}

$datasetCacheTtl = 45;
$nowTs = time();
$registrarDataset = ['rows' => [], 'source_label' => 'Unavailable', 'is_available' => false];
$hrDataset = ['rows' => [], 'source_label' => 'Unavailable', 'is_available' => false];

$cachedRegistrar = $_SESSION['guidance_registrar_dataset_cache'] ?? null;
if (is_array($cachedRegistrar) && ($nowTs - (int) ($cachedRegistrar['stored_at'] ?? 0)) < $datasetCacheTtl) {
    $registrarDataset = (array) ($cachedRegistrar['data'] ?? $registrarDataset);
} else {
    $registrarDataset = guidance_fetch_registrar_directory($conn, 12);
    $_SESSION['guidance_registrar_dataset_cache'] = [
        'stored_at' => $nowTs,
        'data' => $registrarDataset,
    ];
}

$cachedHr = $_SESSION['guidance_hr_dataset_cache'] ?? null;
if (is_array($cachedHr) && ($nowTs - (int) ($cachedHr['stored_at'] ?? 0)) < $datasetCacheTtl) {
    $hrDataset = (array) ($cachedHr['data'] ?? $hrDataset);
} else {
    $hrDataset = guidance_fetch_hr_employees($conn, 20);
    $_SESSION['guidance_hr_dataset_cache'] = [
        'stored_at' => $nowTs,
        'data' => $hrDataset,
    ];
}

$registrarRows = $registrarDataset['rows'] ?? [];
$hrRows = $hrDataset['rows'] ?? [];

$prefectFilterSql = '';
if ($studentIdFilter !== '') {
    $prefectFilterSql = " AND COALESCE(student_id, '') ILIKE " . guidance_integration_quote('%' . $studentIdFilter . '%');
}
if (!in_array($statusFilter, ['all', 'received', 'acknowledged'], true)) {
    $statusFilter = 'all';
}
if ($statusFilter !== 'all') {
    $prefectFilterSql .= " AND LOWER(COALESCE(status, '')) = " . guidance_integration_quote($statusFilter);
}
if (!in_array($sentReportFilter, ['all', 'completed', 'pending'], true)) {
    $sentReportFilter = 'all';
}
if ($sentReportFilter === 'completed') {
    $prefectFilterSql .= " AND COALESCE(response_payload->>'forwarded_to', '') = 'pmed'";
} elseif ($sentReportFilter === 'pending') {
    $prefectFilterSql .= " AND COALESCE(response_payload->>'forwarded_to', '') <> 'pmed'";
}

$prefectInbox = $conn->query("SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND source_department='prefect'{$prefectFilterSql} ORDER BY created_at DESC LIMIT 10");
$inbound = $conn->query("SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' ORDER BY created_at DESC LIMIT 60");
$outbound = $conn->query("SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance'" . ($studentIdFilter !== '' ? " AND COALESCE(student_id, '') ILIKE " . guidance_integration_quote('%' . $studentIdFilter . '%') : '') . " ORDER BY created_at DESC LIMIT 60");
if (!is_object($outbound) || !method_exists($outbound, 'fetch_assoc')) {
    if ($message === '') {
        $message = 'Unable to load outbound tracking records right now.';
        $messageType = 'error';
    }
}
$inboundCount = guidance_count_result($conn, "SELECT COUNT(*) AS total FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance'");
$outboundCount = guidance_count_result($conn, "SELECT COUNT(*) AS total FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance'");
$prefectUnreadCount = guidance_count_result($conn, "SELECT COUNT(*) AS total FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND source_department='prefect' AND status='Received'");
$failedCount = guidance_count_result($conn, "SELECT COUNT(*) AS total FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' AND status='Failed'");
$routeCount = guidance_count_result($conn, "SELECT COUNT(DISTINCT route_key) AS total FROM integration_flows WHERE route_key IS NOT NULL");

guidance_render_shell_start(
    'Integration Hub',
    'Guidance Integration Hub',
    'Receive and review Prefect reports, forward selected reports to PMED, fetch student information from Registrar, and send employee support requests to HR.',
    [
        ['label' => 'Inbound Queue', 'value' => $inboundCount, 'note' => 'Records received from external offices into Guidance.'],
        ['label' => 'Outbound Queue', 'value' => $outboundCount, 'note' => 'Guidance-originated events prepared for partner routing.'],
        ['label' => 'Unread Prefect Reports', 'value' => $prefectUnreadCount, 'note' => 'Prefect reports waiting to be marked as read.'],
        ['label' => 'Failed Sync', 'value' => $failedCount, 'note' => 'Outbound events needing retry or payload correction.'],
        ['label' => 'Registered Routes', 'value' => $routeCount, 'note' => 'Unique route keys already used for cross-module exchange.'],
    ],
    [
        ['label' => 'Prefect Report Inbox', 'href' => '#prefect-inbox', 'class' => 'btn-primary'],
        ['label' => 'Registrar Feed', 'href' => '#registrar-feed', 'class' => 'btn-secondary'],
        ['label' => 'Request HR Employee', 'href' => '#hr-request', 'class' => 'btn-secondary'],
    ],
    ['Receive from Prefect', 'Mark as Read', 'Forward to PMED', 'Fetch Registrar Student Data', 'Request HR Employee']
);
?>

<?php if ($message !== ''): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Connected Departments</h2>
            <p>Guidance is integrated with Registrar, Prefect, PMED, and HR for student and employee workflows.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Department</th>
                <th>Direction</th>
                <th>Purpose</th>
                <th>Outbound Events</th>
                <th>Inbound Events</th>
            </tr>
            <?php foreach ($departments as $department): ?>
                <tr>
                    <td><?php echo guidance_escape($department['label']); ?></td>
                    <td><?php echo guidance_escape(ucfirst($department['direction'])); ?></td>
                    <td><?php echo guidance_escape($department['description']); ?></td>
                    <td><?php echo guidance_escape(implode(', ', array_values($department['outbound_flows'] ?? [])) ?: '-'); ?></td>
                    <td><?php echo guidance_escape(implode(', ', array_values($department['inbound_flows'] ?? [])) ?: '-'); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</section>

<section class="table-panel" id="prefect-inbox">
    <div class="panel-heading">
        <div>
            <h2>Prefect Report Inbox</h2>
            <p>View Prefect reports received by Guidance, mark them as read, and forward selected reports to PMED.</p>
        </div>
    </div>
    <div class="table-wrap">
        <form method="GET" class="form-stack" style="margin-bottom:12px;">
            <input name="student_id_filter" placeholder="Monitor Student ID (e.g. 2026-0002)" value="<?php echo guidance_escape($studentIdFilter); ?>">
            <div class="split-layout">
                <select name="status_filter">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>Received</option>
                    <option value="acknowledged" <?php echo $statusFilter === 'acknowledged' ? 'selected' : ''; ?>>Acknowledged</option>
                </select>
                <select name="sent_report_filter">
                    <option value="all" <?php echo $sentReportFilter === 'all' ? 'selected' : ''; ?>>All Sent Report</option>
                    <option value="pending" <?php echo $sentReportFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="completed" <?php echo $sentReportFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <button type="submit">Filter</button>
        </form>
        <table>
            <tr>
                <th>Date</th>
                <th>Student ID</th>
                <th>Student</th>
                <th>Event</th>
                <th>Status</th>
                <th>Sent Report</th>
                <th>Workflow</th>
                <th>Action</th>
            </tr>
            <?php if (!$prefectInbox || $prefectInbox->num_rows === 0): ?>
                <tr>
                    <td colspan="8">No Prefect reports found.</td>
                </tr>
            <?php else: ?>
                <?php while ($row = $prefectInbox->fetch_assoc()): ?>
                    <?php $prefectPayload = guidance_decode_json_payload((string) ($row['payload_json'] ?? '')); ?>
                    <?php $prefectResponse = guidance_decode_json_payload((string) ($row['response_payload'] ?? '')); ?>
                    <?php $workflowStatus = strtolower(trim((string) ($prefectPayload['review_status'] ?? 'pending'))); ?>
                    <?php if (!in_array($workflowStatus, ['pending', 'completed', 'has_record', 'cleared'], true)) { $workflowStatus = 'pending'; } ?>
                    <?php $sentReportStatus = (($prefectResponse['forwarded_to'] ?? '') === 'pmed') ? 'Completed' : 'Pending'; ?>
                    <?php $isLocked = in_array($workflowStatus, ['completed', 'cleared'], true) || $sentReportStatus === 'Completed'; ?>
                    <tr>
                        <td><?php echo guidance_escape($row['created_at']); ?></td>
                        <td><?php echo guidance_escape($row['student_id'] ?: '-'); ?></td>
                        <td><?php echo guidance_escape($row['student_name'] ?: '-'); ?></td>
                        <td><?php echo guidance_escape($row['flow_type']); ?></td>
                        <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['status'])); ?>"><?php echo guidance_escape($row['status']); ?></span></td>
                        <td><span class="status-pill modern <?php echo strtolower(guidance_escape($sentReportStatus)); ?>"><?php echo guidance_escape($sentReportStatus); ?></span></td>
                        <td><span class="status-pill modern <?php echo strtolower(guidance_escape(str_replace('_', '', $workflowStatus))); ?>"><?php echo guidance_escape(ucwords(str_replace('_', ' ', $workflowStatus))); ?></span></td>
                        <td>
                            <div class="stack-layout">
                                <a class="table-link" href="integration.php?view=<?php echo (int) $row['id']; ?>#prefect-inbox">View</a>
                                <?php if (!$isLocked && $row['status'] !== 'Acknowledged'): ?>
                                    <form method="POST" class="form-stack">
                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                        <button name="mark_prefect_read">Mark as Read</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isLocked): ?>
                                    <span class="table-link" style="pointer-events:none; opacity:.65;">View only (record)</span>
                                <?php else: ?>
                                    <button type="button"
                                        class="open-review-modal"
                                        data-event-id="<?php echo (int) $row['id']; ?>"
                                        data-student-id="<?php echo guidance_escape($row['student_id'] ?: ''); ?>"
                                        data-student-name="<?php echo guidance_escape($row['student_name'] ?: ''); ?>"
                                        data-summary="<?php echo guidance_escape($row['payload_summary'] ?: ''); ?>"
                                        data-workflow-status="<?php echo guidance_escape($workflowStatus); ?>">
                                        Review & Send to PMED
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </table>
    </div>
</section>

<?php if ($viewEvent): ?>
<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Report View</h2>
            <p>Detailed payload for selected integration event.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr><th>Field</th><th>Value</th></tr>
            <tr><td>ID</td><td><?php echo (int) $viewEvent['id']; ?></td></tr>
            <tr><td>Source</td><td><?php echo guidance_escape($viewEvent['source_department']); ?></td></tr>
            <tr><td>Target</td><td><?php echo guidance_escape($viewEvent['target_department']); ?></td></tr>
            <tr><td>Student ID</td><td><?php echo guidance_escape($viewEvent['student_id'] ?: '-'); ?></td></tr>
            <tr><td>Student Name</td><td><?php echo guidance_escape($viewEvent['student_name'] ?: '-'); ?></td></tr>
            <tr><td>Summary</td><td><?php echo guidance_escape($viewEvent['payload_summary'] ?: '-'); ?></td></tr>
            <tr>
                <td>Payload JSON</td>
                <td><pre><?php echo guidance_escape(json_encode(guidance_decode_json_payload((string) ($viewEvent['payload_json'] ?? '')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre></td>
            </tr>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="table-panel" id="registrar-feed">
    <div class="panel-heading">
        <div>
            <h2>Registrar Student Information</h2>
            <p>Fetch student information from Registrar and sync selected records into Guidance.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Course / Year</th>
                <th>Enrollment</th>
                <th>Action</th>
            </tr>
            <?php if (!$registrarRows): ?>
                <tr><td colspan="5">No Registrar records available.</td></tr>
            <?php else: ?>
                <?php foreach ($registrarRows as $row): ?>
                    <tr>
                        <td><?php echo guidance_escape($row['student_id'] ?? ''); ?></td>
                        <td><?php echo guidance_escape($row['student_name'] ?? ''); ?></td>
                        <td><?php echo guidance_escape(trim(($row['course'] ?? '-') . ' / ' . ($row['year_level'] ?? '-'))); ?></td>
                        <td><?php echo guidance_escape($row['enrollment_status'] ?? ''); ?></td>
                        <td>
                            <form method="POST" class="form-stack">
                                <input type="hidden" name="source_record_id" value="<?php echo guidance_escape($row['source_record_id'] ?? ''); ?>">
                                <button name="sync_registrar_student">Fetch to Guidance</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</section>

<div class="split-layout" id="hr-request">
    <section class="table-panel">
        <div class="panel-heading">
            <div>
                <h2>HR Employee Directory</h2>
                <p>Employee information fetched from HR integration source.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Position</th>
                </tr>
                <?php if (!$hrRows): ?>
                    <tr><td colspan="4">No HR employee records available.</td></tr>
                <?php else: ?>
                    <?php foreach ($hrRows as $employee): ?>
                        <tr>
                            <td><?php echo guidance_escape($employee['employee_id'] ?? ''); ?></td>
                            <td><?php echo guidance_escape($employee['employee_name'] ?? ''); ?></td>
                            <td><?php echo guidance_escape($employee['department_name'] ?? '-'); ?></td>
                            <td><?php echo guidance_escape($employee['position_title'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </section>

    <section class="form-box">
        <div class="panel-heading">
            <div>
                <h2>Request Employee from HR</h2>
                <p>Create an HR request for employee support related to a student case.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <input name="student_id" placeholder="Student ID" required>
            <input name="student_name" placeholder="Student Name" required>
            <input name="requested_position" placeholder="Requested Position / Role" required>
            <textarea name="request_reason" placeholder="Reason for employee request" required></textarea>
            <div class="split-layout">
                <input name="preferred_employee_id" placeholder="Preferred Employee ID (optional)" list="hr-employee-id-list">
                <input name="preferred_employee_name" placeholder="Preferred Employee Name (optional)" list="hr-employee-name-list">
            </div>
            <button name="request_hr_employee">Send Request to HR</button>
        </form>

        <datalist id="hr-employee-id-list">
            <?php foreach ($hrRows as $employee): ?>
                <option value="<?php echo guidance_escape($employee['employee_id'] ?? ''); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <datalist id="hr-employee-name-list">
            <?php foreach ($hrRows as $employee): ?>
                <option value="<?php echo guidance_escape($employee['employee_name'] ?? ''); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </section>
</div>

<div class="split-layout">
    <section class="table-panel">
        <div class="panel-heading">
            <div>
                <h2>Outbound Tracking</h2>
                <p>Monitor Guidance-originated events and dispatch status.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Route</th>
                    <th>Event</th>
                    <th>Target</th>
                    <th>Student ID</th>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Success Report</th>
                    <th>Archive</th>
                    <th>Action</th>
                </tr>
                <?php if (is_object($outbound) && method_exists($outbound, 'fetch_assoc')): ?>
                    <?php while ($row = $outbound->fetch_assoc()): ?>
                        <?php $outboundResponse = guidance_decode_json_payload((string) ($row['response_payload'] ?? '')); ?>
                        <?php $successReport = (($outboundResponse['success_report'] ?? false) || in_array((string) ($row['status'] ?? ''), ['Sent', 'Acknowledged'], true)); ?>
                        <?php $archiveStatus = (string) ($outboundResponse['archive_status'] ?? ($successReport ? 'Active' : '-')); ?>
                        <tr>
                            <td><?php echo guidance_escape($row['created_at']); ?></td>
                            <td><?php echo guidance_escape($row['route_key'] ?: '-'); ?></td>
                            <td><?php echo guidance_escape($row['flow_type']); ?></td>
                            <td><?php echo guidance_escape(guidance_integration_department_label($row['target_department'])); ?></td>
                            <td><?php echo guidance_escape($row['student_id'] ?: '-'); ?></td>
                            <td><?php echo guidance_escape($row['student_name'] ?: '-'); ?></td>
                            <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row['status'])); ?>"><?php echo guidance_escape($row['status']); ?></span></td>
                            <td><?php echo $successReport ? 'Yes' : 'No'; ?></td>
                            <td><?php echo guidance_escape($archiveStatus); ?></td>
                            <td>
                                <div class="stack-layout">
                                    <form method="POST" class="form-stack">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button name="mark_sent">Mark Sent</button>
                                    </form>
                                    <form method="POST" class="form-stack">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <textarea name="last_error" placeholder="Reason if failed"></textarea>
                                        <button name="mark_failed" class="btn-danger">Mark Failed</button>
                                    </form>
                                    <?php if ($successReport && $archiveStatus !== 'Archived'): ?>
                                        <form method="POST" class="form-stack">
                                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                            <button name="archive_report">Archive</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10">Unable to load outbound tracking records.</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>
    </section>
</div>

<div id="review-send-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.35); z-index:9999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; width:min(680px, 100%); border-radius:12px; padding:16px; box-shadow:0 16px 30px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <h3 style="margin:0;">Review Prefect Report</h3>
            <button type="button" id="close-review-modal">Close</button>
        </div>
        <p style="margin:0 0 12px 0; color:#5c6470;">Review report details, set action taken, then send to PMED.</p>
        <form method="POST" class="form-stack">
            <input type="hidden" name="id" id="modal-event-id" value="">
            <div class="split-layout">
                <input type="text" id="modal-student-id" readonly placeholder="Student ID">
                <input type="text" id="modal-student-name" readonly placeholder="Student Name">
            </div>
            <textarea id="modal-summary" rows="2" readonly placeholder="Report summary"></textarea>
            <select name="review_decision">
                <option value="for_pmed" selected>Forward to PMED</option>
                <option value="monitoring_only">Monitoring only</option>
                <option value="needs_follow_up">Needs follow-up</option>
            </select>
            <select name="review_status" id="modal-review-status">
                <option value="pending" selected>Pending</option>
                <option value="completed">Completed</option>
                <option value="has_record">Has Record</option>
                <option value="cleared">Cleared</option>
            </select>
            <textarea name="guidance_action_taken" rows="3" placeholder="Action taken by Guidance">Guidance reviewed the Prefect report and prepared PMED monitoring handoff.</textarea>
            <textarea name="review_notes" rows="2" placeholder="Review notes (optional)"></textarea>
            <button name="send_prefect_to_pmed">Submit Review & Send</button>
        </form>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('review-send-modal');
    const closeBtn = document.getElementById('close-review-modal');
    const eventInput = document.getElementById('modal-event-id');
    const studentIdInput = document.getElementById('modal-student-id');
    const studentNameInput = document.getElementById('modal-student-name');
    const summaryInput = document.getElementById('modal-summary');
    const reviewStatusInput = document.getElementById('modal-review-status');
    const openButtons = document.querySelectorAll('.open-review-modal');

    if (!modal || !closeBtn || !eventInput || !studentIdInput || !studentNameInput || !summaryInput || !reviewStatusInput) return;

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            eventInput.value = button.getAttribute('data-event-id') || '';
            studentIdInput.value = button.getAttribute('data-student-id') || '';
            studentNameInput.value = button.getAttribute('data-student-name') || '';
            summaryInput.value = button.getAttribute('data-summary') || '';
            reviewStatusInput.value = button.getAttribute('data-workflow-status') || 'pending';
            modal.style.display = 'flex';
        });
    });

    closeBtn.addEventListener('click', () => {
        modal.style.display = 'none';
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });

})();
</script>

<?php guidance_render_shell_end(); ?>
