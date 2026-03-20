<?php
session_start();
include "../config/db.php";
include "_shared.php";
include "integration_helpers.php";

guidance_integration_ensure_schema($conn);

$departments = guidance_integration_departments();
$message = '';
$messageType = 'success';

if (isset($_POST["queue_outbound"])) {
    $targetDepartment = guidance_integration_normalize_department($_POST["target_department"] ?? '');
    $eventCode = trim($_POST["event_code"] ?? '');
    $referenceTable = trim($_POST["reference_table"] ?? '');
    $referenceId = trim($_POST["reference_id"] ?? '');
    $studentId = trim($_POST["student_id"] ?? '');
    $studentName = trim($_POST["student_name"] ?? '');
    $payloadSummary = trim($_POST["payload_summary"] ?? '');

    $queued = guidance_integration_queue_outbound($conn, $targetDepartment, $eventCode, [
        'route_key' => trim($_POST["route_key"] ?? ''),
        'reference_table' => $referenceTable !== '' ? $referenceTable : null,
        'reference_id' => $referenceId,
        'student_id' => $studentId !== '' ? $studentId : null,
        'student_name' => $studentName !== '' ? $studentName : null,
        'payload_summary' => $payloadSummary !== '' ? $payloadSummary : null,
        'payload_json' => [
            'student_id' => $studentId !== '' ? $studentId : null,
            'student_name' => $studentName !== '' ? $studentName : null,
            'summary' => $payloadSummary !== '' ? $payloadSummary : null,
            'reference_table' => $referenceTable !== '' ? $referenceTable : null,
            'reference_id' => $referenceId !== '' ? (int) $referenceId : null,
        ],
    ]);

    if ($queued) {
        $message = 'Outbound integration event queued successfully.';
    } else {
        $message = 'Unable to queue outbound event. Check the route and target department.';
        $messageType = 'error';
    }
}

if (isset($_POST["receive_inbound"])) {
    $sourceDepartment = guidance_integration_normalize_department($_POST["source_department"] ?? '');
    $eventCode = trim($_POST["event_code"] ?? '');
    $studentId = trim($_POST["student_id"] ?? '');
    $studentName = trim($_POST["student_name"] ?? '');
    $payloadSummary = trim($_POST["payload_summary"] ?? '');
    $correlationId = trim($_POST["correlation_id"] ?? '');

    if (isset($departments[$sourceDepartment])) {
        $sql = "INSERT INTO integration_flows(
            direction, source_department, target_department, flow_type, student_id, student_name,
            payload_summary, status, received_at, route_key, event_code, correlation_id, payload_json, updated_at
        ) VALUES (
            'INBOUND',
            " . guidance_integration_quote($sourceDepartment) . ",
            'guidance',
            " . guidance_integration_quote(guidance_integration_flow_label($sourceDepartment, $eventCode, 'inbound')) . ",
            " . guidance_integration_quote($studentId !== '' ? $studentId : null) . ",
            " . guidance_integration_quote($studentName !== '' ? $studentName : null) . ",
            " . guidance_integration_quote($payloadSummary !== '' ? $payloadSummary : null) . ",
            'Received',
            NOW(),
            " . guidance_integration_quote($sourceDepartment . '_to_guidance_' . $eventCode) . ",
            " . guidance_integration_quote($eventCode) . ",
            " . guidance_integration_quote($correlationId !== '' ? $correlationId : uniqid($sourceDepartment . '_', true)) . ",
            " . guidance_integration_quote(json_encode([
                'student_id' => $studentId !== '' ? $studentId : null,
                'student_name' => $studentName !== '' ? $studentName : null,
                'summary' => $payloadSummary !== '' ? $payloadSummary : null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "::jsonb,
            NOW()
        )";

        if ($conn->query($sql)) {
            $message = 'Inbound integration event recorded for Guidance.';
        } else {
            $message = 'Unable to record inbound event.';
            $messageType = 'error';
        }
    } else {
        $message = 'Unknown source department.';
        $messageType = 'error';
    }
}

if (isset($_POST["mark_sent"])) {
    $id = (int) $_POST["id"];
    $conn->query("UPDATE integration_flows SET status='Sent', sent_at=NOW(), last_error=NULL, updated_at=NOW() WHERE id=$id AND direction='OUTBOUND'");
    $message = "Outbound message marked as sent.";
}

if (isset($_POST["mark_failed"])) {
    $id = (int) $_POST["id"];
    $errorNote = trim($_POST["last_error"] ?? '');
    $conn->query("UPDATE integration_flows SET status='Failed', last_error=" . guidance_integration_quote($errorNote !== '' ? $errorNote : 'Manual failure mark') . ", updated_at=NOW() WHERE id=$id AND direction='OUTBOUND'");
    $message = "Outbound message marked as failed.";
}

if (isset($_POST["acknowledge"])) {
    $id = (int) $_POST["id"];
    if (guidance_apply_inbound_event($conn, $id)) {
        $message = "Inbound event applied to Guidance and acknowledged.";
    } else {
        $conn->query("UPDATE integration_flows SET status='Acknowledged', acknowledged_at=NOW(), updated_at=NOW() WHERE id=$id AND direction='INBOUND' AND target_department='guidance'");
        $message = "Inbound message acknowledged.";
    }
}

$prefill = [
    "event_code" => "",
    "target_department" => "",
    "route_key" => "",
    "reference_table" => "",
    "reference_id" => "",
    "student_id" => "",
    "student_name" => "",
    "payload_summary" => ""
];

if (isset($_GET["sync"]) && $_GET["sync"] === "1") {
    $prefill["event_code"] = $_GET["event_code"] ?? "";
    $prefill["target_department"] = guidance_integration_normalize_department($_GET["target"] ?? "");
    $prefill["route_key"] = $_GET["route_key"] ?? "";
    $prefill["reference_table"] = $_GET["table"] ?? "";
    $prefill["reference_id"] = $_GET["id"] ?? "";
    $prefill["student_id"] = $_GET["student_id"] ?? "";
    $prefill["student_name"] = $_GET["student_name"] ?? "";
    $prefill["payload_summary"] = $_GET["summary"] ?? "";
}

$inbound = $conn->query("SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' ORDER BY created_at DESC");
$outbound = $conn->query("SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' ORDER BY created_at DESC");
$inboundCount = $inbound ? $inbound->num_rows : 0;
$outboundCount = $outbound ? $outbound->num_rows : 0;
$receivedCountResult = $conn->query("SELECT * FROM integration_flows WHERE direction='INBOUND' AND target_department='guidance' AND status='Received'");
$receivedCount = $receivedCountResult ? $receivedCountResult->num_rows : 0;
$failedCountResult = $conn->query("SELECT * FROM integration_flows WHERE direction='OUTBOUND' AND source_department='guidance' AND status='Failed'");
$failedCount = $failedCountResult ? $failedCountResult->num_rows : 0;
$routeCountResult = $conn->query("SELECT DISTINCT route_key FROM integration_flows WHERE route_key IS NOT NULL");
$routeCount = $routeCountResult ? $routeCountResult->num_rows : 0;

guidance_render_shell_start(
    'Integration Hub',
    'Guidance Integration Hub',
    'Operate Guidance as an integration-ready module inside the school platform, with route metadata, partner registries, and auditable inbound and outbound events.',
    [
        ['label' => 'Inbound Queue', 'value' => $inboundCount, 'note' => 'Records received from external offices into Guidance.'],
        ['label' => 'Outbound Queue', 'value' => $outboundCount, 'note' => 'Guidance-originated events prepared for partner routing.'],
        ['label' => 'Awaiting Acknowledgment', 'value' => $receivedCount, 'note' => 'Inbound items that still need Guidance confirmation.'],
        ['label' => 'Failed Sync', 'value' => $failedCount, 'note' => 'Outbound events needing retry or payload correction.'],
        ['label' => 'Registered Routes', 'value' => $routeCount, 'note' => 'Unique route keys already used for cross-module exchange.'],
    ],
    [
        ['label' => 'Queue Outbound Event', 'href' => '#outbound-form', 'class' => 'btn-primary'],
        ['label' => 'Receive Inbound Event', 'href' => '#inbound-form', 'class' => 'btn-secondary'],
    ],
    ['Registrar Sends Student Identity', 'Guidance Owns Cases', 'Registrar / Prefect / PMED Exchange', 'Audit-Ready Monitoring']
);
?>

<?php if ($message !== ""): ?>
    <div class="flash-message <?php echo $messageType === 'error' ? 'flash-error' : ''; ?>"><?php echo guidance_escape($message); ?></div>
<?php endif; ?>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Connected Departments</h2>
            <p>The Guidance module is now aligned to the wider school-platform flow, with the following partner offices available for intake or outbound routing.</p>
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
            <?php foreach ($departments as $departmentKey => $department): ?>
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

<div class="split-layout">
    <section class="form-box" id="outbound-form">
        <div class="panel-heading">
            <div>
                <h2>Queue Outbound Event</h2>
                <p>Publish Guidance-owned counseling, behavior, referral, or incident records to Registrar, Prefect, or PMED using stable route metadata.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <div>
                <label>Target Department</label>
                <select name="target_department" required>
                    <option value="">Select Target</option>
                    <?php foreach ($departments as $departmentKey => $department): ?>
                        <?php if (!empty($department['outbound_flows'])): ?>
                            <option value="<?php echo guidance_escape($departmentKey); ?>" <?php if ($prefill["target_department"] === $departmentKey) echo "selected"; ?>>
                                <?php echo guidance_escape($department['label']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Event Code</label>
                <input name="event_code" placeholder="e.g. counseling_report" value="<?php echo guidance_escape($prefill["event_code"]); ?>" required>
            </div>

            <input name="route_key" placeholder="Route Key (optional override)" value="<?php echo guidance_escape($prefill["route_key"]); ?>">
            <input name="reference_table" placeholder="Source Table (e.g. counseling)" value="<?php echo guidance_escape($prefill["reference_table"]); ?>">
            <input name="reference_id" placeholder="Source ID" value="<?php echo guidance_escape($prefill["reference_id"]); ?>">
            <input name="student_id" placeholder="Student ID (optional)" value="<?php echo guidance_escape($prefill["student_id"]); ?>">
            <input name="student_name" placeholder="Student Name" value="<?php echo guidance_escape($prefill["student_name"]); ?>">
            <textarea name="payload_summary" placeholder="Summary / payload notes"><?php echo guidance_escape($prefill["payload_summary"]); ?></textarea>
            <button name="queue_outbound">Queue Outbound</button>
        </form>
    </section>

    <section class="form-box" id="inbound-form">
        <div class="panel-heading">
            <div>
                <h2>Receive Inbound Event</h2>
                <p>Capture student profile sync, behavior updates, and monitoring feedback arriving from Registrar, Prefect, or PMED.</p>
            </div>
        </div>
        <form method="POST" class="form-stack">
            <div>
                <label>Source Department</label>
                <select name="source_department" required>
                    <option value="">Select Source</option>
                    <?php foreach ($departments as $departmentKey => $department): ?>
                        <?php if (!empty($department['inbound_flows'])): ?>
                            <option value="<?php echo guidance_escape($departmentKey); ?>">
                                <?php echo guidance_escape($department['label']); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Event Code</label>
                <input name="event_code" placeholder="e.g. offense_report" required>
            </div>

            <input name="correlation_id" placeholder="Correlation ID (optional)">
            <input name="student_id" placeholder="Student ID (optional)">
            <input name="student_name" placeholder="Student Name">
            <textarea name="payload_summary" placeholder="Summary / payload notes"></textarea>
            <button name="receive_inbound">Receive Inbound</button>
        </form>
    </section>
</div>

<div class="split-layout">
    <section class="table-panel">
        <div class="panel-heading">
            <div>
                <h2>Outbound Tracking</h2>
                <p>Monitor Guidance-originated events, route keys, and the current dispatch state for each integration handoff.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr>
                    <th>Date</th>
                    <th>Route</th>
                    <th>Event</th>
                    <th>Target</th>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Correlation</th>
                    <th>Action</th>
                </tr>
                <?php while ($row = $outbound->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo guidance_escape($row["created_at"]); ?></td>
                        <td><?php echo guidance_escape($row["route_key"] ?: '-'); ?></td>
                        <td><?php echo guidance_escape($row["flow_type"]); ?></td>
                        <td><?php echo guidance_escape(guidance_integration_department_label($row["target_department"])); ?></td>
                        <td><?php echo guidance_escape($row["student_name"] ?: "-"); ?></td>
                        <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row["status"])); ?>"><?php echo guidance_escape($row["status"]); ?></span></td>
                        <td><?php echo guidance_escape($row["correlation_id"] ?: '-'); ?></td>
                        <td>
                            <div class="stack-layout">
                                <form method="POST" class="form-stack">
                                    <input type="hidden" name="id" value="<?php echo $row["id"]; ?>">
                                    <button name="mark_sent">Mark Sent</button>
                                </form>
                                <form method="POST" class="form-stack">
                                    <input type="hidden" name="id" value="<?php echo $row["id"]; ?>">
                                    <textarea name="last_error" placeholder="Reason if failed"></textarea>
                                    <button name="mark_failed" class="btn-danger">Mark Failed</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>

    <aside class="insight-panel soft">
        <div class="panel-heading">
            <div>
                <h3>Integration Rules</h3>
                <p>Guidance now behaves like an integration-aware transaction module in the wider platform.</p>
            </div>
        </div>
        <div class="mini-list">
            <article class="mini-list-item">
                <div class="mini-list-title">Master data stays external</div>
                <div class="mini-list-note">Registrar remains the source of truth for student identity and academic information.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Guidance owns intervention records</div>
                <div class="mini-list-note">Counseling, incidents, referrals, and behavior monitoring records originate in this module.</div>
            </article>
            <article class="mini-list-item">
                <div class="mini-list-title">Every sync is traceable</div>
                <div class="mini-list-note">Route keys, event codes, correlation IDs, and status updates support central monitoring and later API/Supabase wiring.</div>
            </article>
        </div>
    </aside>
</div>

<section class="table-panel">
    <div class="panel-heading">
        <div>
            <h2>Inbound Tracking</h2>
            <p>Review partner updates delivered into Guidance and acknowledge them after office review.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <tr>
                <th>Date</th>
                <th>Route</th>
                <th>Event</th>
                <th>Source</th>
                <th>Student</th>
                <th>Status</th>
                <th>Correlation</th>
                <th>Action</th>
            </tr>
            <?php while ($row = $inbound->fetch_assoc()): ?>
                <tr>
                    <td><?php echo guidance_escape($row["created_at"]); ?></td>
                    <td><?php echo guidance_escape($row["route_key"] ?: '-'); ?></td>
                    <td><?php echo guidance_escape($row["flow_type"]); ?></td>
                    <td><?php echo guidance_escape(guidance_integration_department_label($row["source_department"])); ?></td>
                    <td><?php echo guidance_escape($row["student_name"] ?: "-"); ?></td>
                    <td><span class="status-pill modern <?php echo strtolower(guidance_escape($row["status"])); ?>"><?php echo guidance_escape($row["status"]); ?></span></td>
                    <td><?php echo guidance_escape($row["correlation_id"] ?: '-'); ?></td>
                    <td>
                        <?php if ($row["status"] !== "Acknowledged"): ?>
                            <form method="POST">
                                <input type="hidden" name="id" value="<?php echo $row["id"]; ?>">
                                <button name="acknowledge">Acknowledge</button>
                            </form>
                        <?php else: ?>
                            <span class="table-link success">Completed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    </div>
</section>

<?php guidance_render_shell_end(); ?>
